<?php
declare(strict_types=1);

$role = defined('NIGHTLINE_ROLE') ? NIGHTLINE_ROLE : 'viewer';
$legacyRole = $_GET['role'] ?? null;
if ($role === 'viewer' && in_array($legacyRole, ['admin', 'superadmin'], true)) {
    header('Location: ' . $legacyRole . '.php', true, 303);
    exit;
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; media-src https:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
$configFile = dirname((string) $_SERVER['DOCUMENT_ROOT']) . '/nightline-private/config.php';
try {
    $config = require $configFile;
    $db = new PDO('sqlite:' . $config['database'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
    $db->exec("
        CREATE TABLE IF NOT EXISTS accounts (
            id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, password TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('admin','superadmin','viewer'))
        );
        CREATE UNIQUE INDEX IF NOT EXISTS singleton_roles ON accounts(role) WHERE role != 'viewer';
        CREATE TABLE IF NOT EXISTS sessions (
            token TEXT PRIMARY KEY, account INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
            expires INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS services (
            id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL, stream TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS assignments (
            account INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
            service INTEGER NOT NULL REFERENCES services(id) ON DELETE CASCADE,
            expiry TEXT NOT NULL DEFAULT '', PRIMARY KEY(account, service)
        );
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY, account INTEGER REFERENCES accounts(id) ON DELETE CASCADE,
            body TEXT NOT NULL
        );
    ");
} catch (Throwable $error) {
    error_log('Nightline setup: ' . $error->getMessage());
    http_response_code(503);
    exit('Nightline setup is incomplete. Check the private configuration, PDO SQLite and directory permissions.');
}

function query(string $sql, array $args = []): PDOStatement {
    global $db;
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return $statement;
}

function h(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

function redirect(string $url): never {
    header('Location: ' . $url, true, 303);
    exit;
}

function field(string $key, string $label, string $type = 'text', bool $required = true): string {
    return '<label>' . h($label) . '<input name="' . h($key) . '" type="' . h($type) .
        '" maxlength="2048"' . ($required ? ' required' : '') . '></label>';
}

function form(string $action, string $label, string $contents): string {
    global $csrf;
    return '<form method="post"><input type="hidden" name="action" value="' . h($action) .
        '"><input type="hidden" name="csrf" value="' . h($csrf) . '">' .
        $contents . '<button>' . h($label) . '</button></form>';
}

function saveAccount(string $role, bool $createOnly = false): void {
    global $db;
    $name = trim(post('username'));
    $password = post('password');
    if ($name === '' || strlen($name) > 80 || strlen($password) < 12 || strlen($password) > 72 ||
        str_contains($password, "\0")) {
        throw new InvalidArgumentException('Enter a username and a password between 12 and 72 bytes.');
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->beginTransaction();
    try {
        $account = $createOnly ? false : query(
            "SELECT id FROM accounts WHERE role=? AND (role!='viewer' OR username=?)", [$role, $name]
        )->fetchColumn();
        if ($account !== false) {
            query('UPDATE accounts SET username=?, password=? WHERE id=?', [$name, $hash, $account]);
            query('DELETE FROM sessions WHERE account=?', [$account]);
        } else {
            query('INSERT INTO accounts(username,password,role) VALUES(?,?,?)', [$name, $hash, $role]);
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

$title = ['viewer' => 'NIGHTLINE TV', 'admin' => 'Nightline Admin', 'superadmin' => 'Nightline Superadmin'][$role];
$url = ['viewer' => 'index.php', 'admin' => 'admin.php', 'superadmin' => 'superadmin.php'][$role];
$cookie = 'nightline_' . $role;
$token = $_COOKIE[$cookie] ?? '';
$token = is_string($token) ? $token : '';
$hash = hash('sha256', $token);
$csrf = hash_hmac('sha256', 'nightline-form', $token);
$cookiePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
$user = query('SELECT a.id FROM accounts a JOIN sessions s ON s.account=a.id
               WHERE s.token=? AND s.expires>? AND a.role=?', [$hash, time(), $role])->fetchColumn();
$setupCode = (string) ($config['setup_code'] ?? '');
$setup = $role === 'superadmin' && strlen($setupCode) >= 32 &&
    query("SELECT id FROM accounts WHERE role='superadmin'")->fetchColumn() === false;
$feedback = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    try {
        if ($setup && $action === 'setup' && hash_equals($setupCode, post('setup_code'))) {
            saveAccount('superadmin', true);
            redirect($url);
        }
        if ($user === false && $action === 'login') {
            $account = query('SELECT id, password FROM accounts WHERE role=? AND username=?',
                [$role, trim(post('username'))])->fetch();
            if ($account && strlen(post('password')) <= 72 && password_verify(post('password'), $account['password'])) {
                $newToken = bin2hex(random_bytes(32));
                $expires = time() + 86400 * ($role === 'viewer' ? 30 : 1);
                query('DELETE FROM sessions WHERE expires<=?', [time()]);
                query('INSERT INTO sessions VALUES(?,?,?)', [hash('sha256', $newToken), $account['id'], $expires]);
                setcookie($cookie, $newToken, [
                    'expires' => $expires, 'path' => $cookiePath, 'secure' => true,
                    'httponly' => true, 'samesite' => 'Strict',
                ]);
                redirect($url);
            }
            throw new InvalidArgumentException('Sign-in failed.');
        }
        if ($user === false || !hash_equals($csrf, post('csrf'))) {
            http_response_code(403);
            throw new InvalidArgumentException('Request denied. Open the page again and retry.');
        }
        if ($action === 'logout') {
            query('DELETE FROM sessions WHERE token=?', [$hash]);
            redirect($url);
        }
        if ($role === 'superadmin' && $action === 'admin') {
            saveAccount('admin');
        } elseif ($role === 'admin') {
            updateAdmin($action);
        } else {
            http_response_code(403);
            throw new InvalidArgumentException('Request denied.');
        }
        redirect($url);
    } catch (InvalidArgumentException $error) {
        $feedback = $error->getMessage();
    } catch (PDOException $error) {
        error_log('Nightline update: ' . $error->getMessage());
        $feedback = 'Could not save. Check the selected account, name or service.';
    }
}

function updateAdmin(string $action): void {
    if ($action === 'viewer') {
        saveAccount('viewer');
        return;
    }
    if ($action === 'service') {
        $name = trim(post('name'));
        $stream = trim(post('stream'));
        $parts = parse_url($stream);
        if ($name === '' || strlen($name) > 100 || strlen($stream) > 2048 ||
            !filter_var($stream, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https' ||
            isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Enter a service name and a valid HTTPS stream URL.');
        }
        query('INSERT INTO services(name,stream) VALUES(?,?)
               ON CONFLICT(name) DO UPDATE SET stream=excluded.stream', [$name, $stream]);
        return;
    }
    $account = post('account');
    if ($account !== '' && !query("SELECT id FROM accounts WHERE id=? AND role='viewer'", [$account])->fetchColumn()) {
        throw new InvalidArgumentException('Select a viewer account.');
    }
    if ($action === 'message') {
        $body = trim(post('body'));
        if ($body === '' || strlen($body) > 2048) {
            throw new InvalidArgumentException('Enter a message of up to 2048 bytes.');
        }
        query('INSERT INTO messages(account,body) VALUES(?,?)', [$account === '' ? null : $account, $body]);
        return;
    }
    $service = post('service');
    if ($account === '' || !query('SELECT id FROM services WHERE id=?', [$service])->fetchColumn()) {
        throw new InvalidArgumentException('Select a viewer and service.');
    }
    if ($action === 'assign') {
        $expiry = post('expiry');
        $date = $expiry === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $expiry);
        if ($expiry !== '' && (!$date || $date->format('Y-m-d') !== $expiry)) {
            throw new InvalidArgumentException('Enter a valid expiry date.');
        }
        query('INSERT INTO assignments(account,service,expiry) VALUES(?,?,?)
               ON CONFLICT(account,service) DO UPDATE SET expiry=excluded.expiry', [$account, $service, $expiry]);
    } elseif ($action === 'unassign') {
        query('DELETE FROM assignments WHERE account=? AND service=?', [$account, $service]);
    } else {
        throw new InvalidArgumentException('Unknown action.');
    }
}

function selectField(string $name, string $label, array $rows, bool $broadcast = false): string {
    $options = $broadcast ? '<option value="">Everyone</option>' : '';
    foreach ($rows as $row) {
        $options .= '<option value="' . (int) $row['id'] . '">' . h($row['name']) . '</option>';
    }
    return '<label>' . h($label) . '<select name="' . h($name) . '">' . $options . '</select></label>';
}

function viewerScreen(int $account): string {
    $selected = $_GET['service'] ?? '';
    $services = query('SELECT s.*,a.expiry FROM assignments a JOIN services s ON s.id=a.service
        WHERE a.account=? ORDER BY s.name', [$account])->fetchAll();
    $messages = query('SELECT body FROM messages WHERE account IS NULL OR account=?
                       ORDER BY id DESC LIMIT 30', [$account])->fetchAll();
    $inbox = '<section id="messages"><h2>Messages</h2>';
    foreach ($messages as $message) {
        $inbox .= '<p>' . h($message['body']) . '</p>';
    }
    $inbox .= ($messages ? '' : '<p>No messages yet.</p>') . '</section>';
    if (!$services) {
        return '<h1>No service assigned</h1><p>Ask your app administrator to assign a service.</p>' . $inbox;
    }
    $service = null;
    foreach ($services as $item) {
        if ((string) $item['id'] === $selected) {
            $service = $item;
        }
    }
    if ($service === null && count($services) === 1 && $selected === '') {
        $service = $services[0];
    }
    if ($service === null) {
        $links = '';
        foreach ($services as $item) {
            $links .= '<a href="index.php?service=' . (int) $item['id'] . '">' . h($item['name']) . '</a>';
        }
        return '<h1>Choose a service</h1><div class="tiles">' . $links . '</div>' . $inbox;
    }
    $notice = '';
    if ($service['expiry'] !== '') {
        $days = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($service['expiry']))->format('%r%a');
        if ($days <= 7) {
            $notice = $days < 0 ? '<p>Service expiry date has passed.</p>' : '<p>Service expires in ' . $days . ' day(s).</p>';
        }
    }
    return '<header><strong>NIGHTLINE TV</strong><nav><a href="#live">Live TV</a><a href="#messages">Messages</a>' .
        '<a href="index.php?service=choose">Switch Service</a><a href="#appearance">Settings</a></nav></header>' .
        '<section class="hero"><h1>' . h($service['name']) . '</h1><a class="primary" href="#live">Watch Live</a></section>' .
        $notice . '<div class="tiles"><a href="#live">Live TV</a><a href="#messages">Messages</a>' .
        '<a href="index.php?service=choose">Switch Service</a></div>' .
        '<section id="live"><h2>Live TV</h2><video controls preload="none" src="' . h($service['stream']) . '"></video></section>' .
        $inbox . '<section id="appearance"><h2>Appearance</h2><label>Theme<select id="theme">' .
        '<option value="blue">Midnight Blue</option><option value="mint">Slate &amp; Mint</option>' .
        '<option value="amber">Charcoal &amp; Amber</option></select></label></section>';
}

$credentials = field('username', 'Username') . field('password', 'Password', 'password');
$body = $feedback === '' ? '' : '<p role="alert">' . h($feedback) . '</p>';
if ($user === false) {
    $body .= '<h1>' . h($title) . '</h1>';
    $body .= $setup
        ? form('setup', 'Create Superadmin', $credentials . field('setup_code', 'Setup code', 'password'))
        : form('login', 'Sign in', $credentials);
} elseif ($role === 'viewer') {
    $body .= viewerScreen((int) $user);
} elseif ($role === 'superadmin') {
    $body .= '<h1>Nightline Superadmin</h1><h2>Admin account</h2>' . form('admin', 'Save Admin account', $credentials);
} else {
    $users = query("SELECT id,username AS name FROM accounts WHERE role='viewer' ORDER BY username")->fetchAll();
    $services = query('SELECT id,name FROM services ORDER BY name')->fetchAll();
    $assignment = selectField('account', 'Viewer', $users) . selectField('service', 'Service', $services);
    $body .= '<h1>Nightline Admin</h1><h2>Viewer accounts</h2>' . form('viewer', 'Save viewer account', $credentials) .
        '<h2>Services</h2>' . form('service', 'Save service', field('name', 'Name') . field('stream', 'HTTPS test stream URL', 'url')) .
        '<h2>Assignments</h2>' . form('assign', 'Assign service', $assignment . field('expiry', 'Expiry', 'date', false)) .
        form('unassign', 'Remove assignment', $assignment) . '<h2>Messages</h2>' .
        form('message', 'Send message', selectField('account', 'Recipient', $users, true) . field('body', 'Message'));
}
if ($user !== false) {
    $body .= form('logout', 'Sign out', '');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <link rel="stylesheet" href="style.css">
    <script src="tv.js" defer></script>
</head>
<body class="<?= $role === 'viewer' ? 'tv' : '' ?>"><main><?= $body ?></main></body>
</html>
