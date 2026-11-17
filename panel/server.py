"""Nightline Admin and TV web application. Run behind HTTPS for device testing."""
import hashlib
import hmac
import os
import secrets
import sqlite3
import sys
import time
from contextlib import closing
from datetime import date
from html import escape
from http.cookies import SimpleCookie
from pathlib import Path
from urllib.parse import parse_qs, urlsplit
from wsgiref.simple_server import make_server

ROOT = Path(__file__).resolve().parent
DB = os.environ.get("NIGHTLINE_DB", str(ROOT / "nightline.sqlite"))
LOCAL = os.environ.get("NIGHTLINE_LOCAL") == "1"
SCHEMA = """
CREATE TABLE IF NOT EXISTS accounts (
 id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, password TEXT NOT NULL,
 role TEXT NOT NULL CHECK(role IN ('admin','superadmin','viewer'))
);
CREATE UNIQUE INDEX IF NOT EXISTS singleton_roles ON accounts(role) WHERE role != 'viewer';
CREATE TABLE IF NOT EXISTS sessions (
 token TEXT PRIMARY KEY, account INTEGER REFERENCES accounts(id) ON DELETE CASCADE,
 expires INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS services (
 id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL, stream TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS assignments (
 account INTEGER REFERENCES accounts(id) ON DELETE CASCADE,
 service INTEGER REFERENCES services(id) ON DELETE CASCADE,
 expiry TEXT NOT NULL DEFAULT '', PRIMARY KEY(account, service)
);
CREATE TABLE IF NOT EXISTS messages (
 id INTEGER PRIMARY KEY, account INTEGER REFERENCES accounts(id) ON DELETE CASCADE,
 body TEXT NOT NULL
);
"""


def database():
    db = sqlite3.connect(DB)
    db.row_factory = sqlite3.Row
    db.execute("PRAGMA foreign_keys=ON")
    db.executescript(SCHEMA)
    return db


def password_hash(password, salt=None):
    salt = salt or secrets.token_hex(16)
    digest = hashlib.scrypt(password.encode(), salt=bytes.fromhex(salt), n=16384, r=8, p=1)
    return salt + ":" + digest.hex()


def save_account(db, role, username, password):
    if not 1 <= len(username) <= 80 or not 12 <= len(password) <= 256:
        raise ValueError("Use a username and a password of 12–256 characters.")
    existing = db.execute("SELECT id FROM accounts WHERE role=? AND (role!='viewer' OR username=?)",
                          (role, username)).fetchone()
    if existing:
        db.execute("UPDATE accounts SET username=?,password=? WHERE id=?",
                   (username, password_hash(password), existing["id"]))
        db.execute("DELETE FROM sessions WHERE account=?", (existing["id"],))
    else:
        db.execute("INSERT INTO accounts(username,password,role) VALUES(?,?,?)",
                   (username, password_hash(password), role))


def html(title, body, css=""):
    return (f'<!doctype html><html lang="en"><meta charset="utf-8">'
            f'<meta name="viewport" content="width=device-width, initial-scale=1">'
            f'<title>{escape(title)}</title><link rel="stylesheet" href="/style.css">'
            f'<body class="{css}">{body}<script src="/tv.js" defer></script></body></html>')


def field(key, label, kind="text", required=True):
    return (f'<label>{label}<input name="{key}" type="{kind}" maxlength="2048"'
            f'{" required" if required else ""}></label>')


def form(action, label, contents, csrf):
    return (f'<form method="post"><input type="hidden" name="action" value="{action}">'
            f'<input type="hidden" name="csrf" value="{csrf}">{contents}'
            f'<button>{label}</button></form>')


def options(rows, all_users=False):
    return ('<option value="">Everyone</option>' if all_users else '') + ''.join(
        f'<option value="{row[0]}">{escape(row[1])}</option>' for row in rows)


def digest(token):
    return hashlib.sha256(token.encode()).hexdigest()


def panel(db, role, csrf):
    if role == "superadmin":
        return "<h1>Nightline Superadmin</h1><h2>Admin account</h2>" + form(
            "admin", "Save Admin account", field("username", "Username") +
            field("password", "Password", "password"), csrf)
    users = db.execute("SELECT id,username FROM accounts WHERE role='viewer' ORDER BY username").fetchall()
    services = db.execute("SELECT id,name FROM services ORDER BY name").fetchall()
    user_select = '<label>User<select name="account" required>' + options(users) + '</select></label>'
    service_select = '<label>Service<select name="service" required>' + options(services) + '</select></label>'
    return ("<h1>Nightline Admin</h1><h2>Viewer accounts</h2>" +
            form("viewer", "Save viewer account", field("username", "Username") +
                 field("password", "Password", "password"), csrf) +
            "<h2>Services</h2>" +
            form("service", "Save service", field("name", "Name") +
                 field("stream", "HTTPS test stream URL", "url"), csrf) +
            "<h2>Service assignments</h2>" +
            form("assign", "Assign service", user_select + service_select +
                 field("expiry", "Expiry", "date", False), csrf) +
            form("unassign", "Remove assignment", user_select + service_select, csrf) +
            "<h2>Messages</h2>" +
            form("message", "Send message", '<label>Recipient<select name="account">' +
                 options(users, True) + '</select></label>' + field("body", "Message"), csrf))


def update_panel(db, role, action, value):
    if role == "superadmin" and action == "admin":
        save_account(db, action, value("username"), value("password"))
        return
    if role != "admin":
        raise ValueError("This action requires the Admin account.")
    if action == "viewer":
        save_account(db, action, value("username"), value("password"))
    elif action == "service":
        if not value("name"):
            raise ValueError("Enter a service name.")
        url = urlsplit(value("stream"))
        if url.scheme != "https" or not url.hostname or url.username or url.password:
            raise ValueError("Enter an HTTPS stream URL.")
        db.execute("INSERT INTO services(name,stream) VALUES(?,?) "
                   "ON CONFLICT(name) DO UPDATE SET stream=excluded.stream", (value("name"), value("stream")))
    elif action in ("assign", "unassign", "message"):
        account = value("account")
        if account and not db.execute("SELECT 1 FROM accounts WHERE id=? AND role='viewer'", (account,)).fetchone():
            raise ValueError("Select a viewer account.")
        if action == "message":
            if not value("body"):
                raise ValueError("Enter a message.")
            db.execute("INSERT INTO messages(account,body) VALUES(?,?)", (account or None, value("body")))
        elif not account:
            raise ValueError("Select a viewer account.")
        elif action == "assign":
            expiry = value("expiry")
            if expiry:
                date.fromisoformat(expiry)
            db.execute("INSERT INTO assignments(account,service,expiry) VALUES(?,?,?) "
                       "ON CONFLICT(account,service) DO UPDATE SET expiry=excluded.expiry",
                       (account, value("service"), expiry))
        else:
            db.execute("DELETE FROM assignments WHERE account=? AND service=?", (account, value("service")))
    else:
        raise ValueError("Unknown action.")


def tv(db, account, selected):
    rows = db.execute("SELECT s.*,a.expiry FROM assignments a JOIN services s ON a.service=s.id "
                      "WHERE a.account=? ORDER BY s.name", (account,)).fetchall()
    service = next((row for row in rows if str(row["id"]) == selected), None)
    if not rows:
        return "<h1>No service assigned</h1><p>Ask your app administrator to assign a service.</p>"
    if service is None and len(rows) == 1 and selected != "choose":
        service = rows[0]
    if service is None:
        return "<h1>Choose a service</h1><div class='tiles'>" + "".join(
            f'<a href="/tv?service={row["id"]}">{escape(row["name"])}</a>' for row in rows) + "</div>"
    messages = db.execute("SELECT body FROM messages WHERE account IS NULL OR account=? ORDER BY id DESC LIMIT 30",
                          (account,)).fetchall()
    expiry = date.fromisoformat(service["expiry"]) if service["expiry"] else None
    days = (expiry - date.today()).days if expiry else None
    notice = ''
    if days is not None and days <= 7:
        notice = "<p>" + (f"Service expires in {days} day(s)." if days >= 0 else "Service expiry date has passed.") + "</p>"
    nav = ('<header><strong>NIGHTLINE TV</strong><nav><a href="#live">Live TV</a>'
           '<a href="#messages">Messages</a><a href="/tv?service=choose">Switch Service</a>'
           '<a href="#appearance">Settings</a></nav></header>')
    return (nav + f'<section class="hero" id="hero"><h1>{escape(service["name"])}</h1>'
            '<a class="primary" href="#live">Watch Live</a></section>' + notice +
            '<div class="tiles"><a href="#live">Live TV</a><a href="#messages">Messages</a>'
            '<a href="/tv?service=choose">Switch Service</a></div>'
            f'<section id="live"><h2>Live TV</h2><video controls preload="none" src="{escape(service["stream"])}"></video></section>'
            '<section id="messages"><h2>Messages</h2>' + (''.join(f'<p>{escape(m["body"])}</p>' for m in messages)
            or '<p>No messages yet.</p>') + '</section>'
            '<section id="appearance"><h2>Appearance</h2><label>Theme<select id="theme">'
            '<option value="blue">Midnight Blue</option><option value="mint">Slate &amp; Mint</option>'
            '<option value="amber">Charcoal &amp; Amber</option></select></label></section>')


def application(env, start_response):
    path = env.get("PATH_INFO")
    def send(status, content, kind="text/html; charset=utf-8", headers=()):
        start_response(status, [("Content-Type", kind), ("Cache-Control", "no-store"),
                                ("X-Content-Type-Options", "nosniff"),
                                ("Content-Security-Policy", "default-src 'self'; media-src https:; frame-ancestors 'none'; form-action 'self'"),
                                *headers])
        return [content.encode()]

    assets = {"/style.css": ("style.css", "text/css"), "/tv.js": ("tv.js", "application/javascript")}
    if path in assets:
        name, kind = assets[path]
        return send("200 OK", (ROOT / name).read_text(), kind)
    roles = {"/admin": "admin", "/superadmin": "superadmin", "/tv": "viewer"}
    if path not in roles:
        return send("404 Not Found", "Page not found")
    role = roles[path]
    title = {"admin": "Nightline Admin", "superadmin": "Nightline Superadmin", "viewer": "NIGHTLINE TV"}[role]
    cookie = SimpleCookie()
    try:
        cookie.load(env.get("HTTP_COOKIE", ""))
    except ValueError:
        pass
    key = "nightline_" + role
    token = cookie[key].value if key in cookie else ""
    csrf = digest("post:" + token)
    secure = "" if LOCAL else "; Secure"

    with closing(database()) as db, db:
        user = db.execute("SELECT a.* FROM accounts a JOIN sessions s ON a.id=s.account "
                          "WHERE s.token=? AND s.expires>? AND role=?", (digest(token), time.time(), role)).fetchone()
        feedback = ""
        if env["REQUEST_METHOD"] == "POST":
            length = int(env.get("CONTENT_LENGTH") or 0)
            if not 0 <= length <= 16384:
                return send("413 Payload Too Large", "Request too large")
            values = parse_qs(env["wsgi.input"].read(length).decode(), keep_blank_values=True)
            def value(name):
                return values.get(name, [""])[0].strip()

            action = value("action")
            if action == "login" and not user:
                account = db.execute("SELECT * FROM accounts WHERE username=? AND role=?",
                                     (value("username"), role)).fetchone()
                password = value("password")
                if account and len(password) <= 256 and hmac.compare_digest(
                        password_hash(password, account["password"].split(":")[0]), account["password"]):
                    token = secrets.token_urlsafe(32)
                    db.execute("DELETE FROM sessions WHERE expires<=?", (time.time(),))
                    db.execute("INSERT INTO sessions VALUES(?,?,?)",
                               (digest(token), account["id"], int(time.time()) + 86400 * 30))
                    return send("303 See Other", "", headers=[
                        ("Location", path), ("Set-Cookie", f"{key}={token}; Path={path}; HttpOnly; SameSite=Strict; Max-Age=2592000{secure}")])
                feedback = "<p>Sign-in failed.</p>"
            elif not user or not hmac.compare_digest(csrf, value("csrf")):
                return send("403 Forbidden", "Open the page again and retry.")
            elif action == "logout":
                db.execute("DELETE FROM sessions WHERE token=?", (digest(token),))
                return send("303 See Other", "", headers=[("Location", path)])
            elif role != "viewer":
                try:
                    update_panel(db, role, action, value)
                except (ValueError, sqlite3.IntegrityError) as exc:
                    feedback = "<p>" + (escape(str(exc)) if isinstance(exc, ValueError) else "Check the selected account or service.") + "</p>"
                else:
                    return send("303 See Other", "", headers=[("Location", path)])
        if not user:
            body = f"<h1>{title}</h1>" + feedback + form("login", "Sign in", field("username", "Username") +
                    field("password", "Password", "password"), "")
        else:
            selected = parse_qs(env.get("QUERY_STRING", "")).get("service", [""])[0]
            body = (tv(db, user["id"], selected) if role == "viewer" else feedback + panel(db, role, csrf))
            body += form("logout", "Sign out", "", csrf)
        return send("200 OK", html(title, f"<main>{body}</main>", "tv" if role == "viewer" else ""))


if __name__ == "__main__":
    if sys.argv[1:] == ["superadmin"]:
        from getpass import getpass
        with closing(database()) as db, db:
            save_account(db, "superadmin", input("Superadmin username: ").strip(), getpass().strip())
    else:
        make_server("127.0.0.1", int(os.environ.get("PORT", "8080")), application).serve_forever()
