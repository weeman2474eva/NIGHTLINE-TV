# Nightline Admin for PHP 8.1

1. In your hosting control panel, select PHP 8.1 and enable PDO and pdo_sqlite for the site.
2. Create a directory called nightline-private alongside your domain's document root. For example, if the document root is /home/ACCOUNT/public_html, use /home/ACCOUNT/nightline-private. Upload private/config.php there. Set setup_code to a unique random value of at least 32 characters. Use the host's permissions to keep that directory private and writable by PHP.
3. Copy the contents of public/ into your web folder, for example public_html/nightline/. You need a working HTTPS certificate. The panel requires no URL rewrite rules.
4. Open https://YOUR-HOST/nightline/index.php?role=superadmin. Enter the setup code and choose the first Superadmin username and password. The setup form is disabled once the Superadmin exists. Clear the setup code from config.php afterward.
5. Sign in as Superadmin and create the single Admin account. Open index.php?role=admin to create viewers, add services, assign them, and send messages. Open index.php for the TV interface.
6. For the GitHub APK workflow, enter https://YOUR-HOST/nightline as panel_url.

The first milestone uses one direct HTTPS test stream per service. This port starts with a fresh SQLite database. The original Python panel uses a different password format, so recreate prototype accounts rather than copying the database. If the panel returns a setup error, check the PHP error log, the document root used to derive the private config path, presence of PDO SQLite, and whether PHP can write to the database directory. If your hosting uses open_basedir, the host must allow the private directory.
