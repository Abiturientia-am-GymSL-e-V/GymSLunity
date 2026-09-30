<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);

// A finished installation whose .env went missing must not be taken over
// through the web installer.
if (is_file($basePath.'/storage/app/installed')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "GymSLunity ist bereits installiert, aber die Datei .env fehlt.\nBitte .env aus der Sicherung wiederherstellen.\n";
    exit;
}

// The setup code proves access to the server's file system, so nobody else
// can complete the installation between upload and first visit.
$setupTokenFile = $basePath.'/storage/app/setup-token';
if (! is_file($setupTokenFile) && is_dir(dirname($setupTokenFile)) && is_writable(dirname($setupTokenFile))) {
    file_put_contents($setupTokenFile, bin2hex(random_bytes(12)).PHP_EOL, LOCK_EX);
    @chmod($setupTokenFile, 0600);
}
$setupToken = is_file($setupTokenFile) ? trim((string) file_get_contents($setupTokenFile)) : '';

$secure = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';

session_name('gymslunity_installer');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => $secure,
]);

$_SESSION['installer_token'] ??= bin2hex(random_bytes(32));
$errors = [];
$host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$defaultUrl = ($secure ? 'https://' : 'http://').($host ?: 'localhost');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    $appUrl = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');
    $connection = (string) ($_POST['db_connection'] ?? 'sqlite');
    $dbHost = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
    $dbPort = trim((string) ($_POST['db_port'] ?? '3306'));
    $dbDatabase = trim((string) ($_POST['db_database'] ?? 'gymslunity'));
    $dbUsername = trim((string) ($_POST['db_username'] ?? 'gymslunity'));
    $dbPassword = (string) ($_POST['db_password'] ?? '');

    if (! hash_equals((string) $_SESSION['installer_token'], $token)) {
        $errors[] = 'Die Installationssitzung ist abgelaufen. Bitte lade die Seite neu.';
    }
    if ($setupToken === '') {
        $errors[] = 'Das Verzeichnis storage/app ist für PHP nicht beschreibbar, daher konnte kein Einrichtungscode erzeugt werden.';
    } elseif (! hash_equals($setupToken, trim((string) ($_POST['setup_token'] ?? '')))) {
        $errors[] = 'Der Einrichtungscode ist falsch.';
    }
    if (filter_var($appUrl, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($appUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
        $errors[] = 'Bitte gib eine vollständige Anwendungs-URL an.';
    }
    if (! in_array($connection, ['sqlite', 'mysql', 'mariadb'], true)) {
        $errors[] = 'Die gewählte Datenbank wird nicht unterstützt.';
    }
    if ($connection !== 'sqlite' && ($dbHost === '' || $dbDatabase === '' || $dbUsername === '')) {
        $errors[] = 'Host, Datenbankname und Benutzername sind erforderlich.';
    }
    foreach (['vendor/autoload.php', 'public/build/manifest.json', '.env.example'] as $required) {
        if (! is_file($basePath.'/'.$required)) {
            $errors[] = 'Die Release-Datei ist unvollständig: '.$required.' fehlt.';
        }
    }
    if (! is_writable($basePath)) {
        $errors[] = 'Das Anwendungsverzeichnis ist für PHP nicht beschreibbar. Erlaube dies vorübergehend für die Einrichtung.';
    }

    // Test the credentials before writing .env: afterwards Laravel boots
    // with them, and a wrong value only surfaces as a server error.
    if ($errors === [] && $connection !== 'sqlite') {
        if (! extension_loaded('pdo_mysql')) {
            $errors[] = 'Die PHP-Erweiterung pdo_mysql fehlt.';
        } elseif (! ctype_digit($dbPort)) {
            $errors[] = 'Der Port muss eine Zahl sein.';
        } elseif (preg_match('/[;\s]/', $dbHost.$dbDatabase) === 1) {
            $errors[] = 'Host und Datenbankname dürfen keine Leerzeichen oder Semikolons enthalten.';
        } else {
            try {
                new PDO(
                    'mysql:host='.$dbHost.';port='.$dbPort.';dbname='.$dbDatabase.';charset=utf8mb4',
                    $dbUsername,
                    $dbPassword,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
                );
            } catch (PDOException $exception) {
                $errors[] = match ((int) ($exception->errorInfo[1] ?? $exception->getCode())) {
                    1045 => 'Die Datenbank hat die Anmeldung abgelehnt. Bitte Benutzername und Passwort prüfen.',
                    1044, 1049 => 'Die Datenbank „'.$dbDatabase.'“ existiert nicht oder der Benutzer hat keinen Zugriff darauf.',
                    2002, 2005 => 'Der Datenbankserver „'.$dbHost.'“ ist nicht erreichbar. Bitte Host und Port prüfen.',
                    default => 'Die Verbindung zur Datenbank ist fehlgeschlagen: '.$exception->getMessage(),
                };
            }
        }
    } elseif ($errors === [] && ! extension_loaded('pdo_sqlite')) {
        $errors[] = 'Die PHP-Erweiterung pdo_sqlite fehlt.';
    }

    if ($errors === []) {
        $example = file_get_contents($basePath.'/.env.example');
        if (! is_string($example)) {
            $errors[] = 'Die Vorlage .env.example konnte nicht gelesen werden.';
        } else {
            $values = [
                'APP_ENV' => 'production',
                'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'APP_DEBUG' => 'false',
                'APP_URL' => $appUrl,
                'PASSKEYS_USER_HANDLE_SECRET' => 'base64:'.base64_encode(random_bytes(32)),
                'SESSION_DRIVER' => 'file',
                'SESSION_SECURE_COOKIE' => str_starts_with($appUrl, 'https://') ? 'true' : 'false',
                'CACHE_STORE' => 'file',
                'DB_CONNECTION' => $connection,
                'DB_DATABASE' => $connection === 'sqlite' ? $basePath.'/database/database.sqlite' : $dbDatabase,
                'DB_HOST' => $dbHost,
                'DB_PORT' => $dbPort,
                'DB_USERNAME' => $dbUsername,
                'DB_PASSWORD' => $dbPassword,
                'MAIL_FROM_ADDRESS' => 'noreply@'.(parse_url($appUrl, PHP_URL_HOST) ?: 'localhost'),
            ];

            foreach ($values as $key => $value) {
                $quoted = in_array($value, ['true', 'false'], true)
                    ? $value
                    : '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $value).'"';
                $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
                $line = $key.'='.$quoted;
                $example = preg_match($pattern, $example) === 1
                    ? (string) preg_replace($pattern, $line, $example)
                    : $example."\n".$line;
            }

            if ($connection === 'sqlite' && ! is_file($basePath.'/database/database.sqlite')) {
                if (@touch($basePath.'/database/database.sqlite') === false) {
                    $errors[] = 'Die SQLite-Datenbankdatei konnte nicht angelegt werden.';
                }
            }

            if ($errors === [] && file_put_contents($basePath.'/.env', $example, LOCK_EX) === false) {
                $errors[] = 'Die .env-Datei konnte nicht geschrieben werden.';
            }

            if ($errors === []) {
                @chmod($basePath.'/.env', 0640);
                session_destroy();
                header('Location: /install', true, 303);
                exit;
            }
        }
    }
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GymSLunity einrichten</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { margin: 0; background: #f5f5f4; color: #1c1917; }
        main { width: min(680px, calc(100% - 2rem)); margin: 3rem auto; padding: 2rem; box-sizing: border-box; border: 1px solid #d6d3d1; border-radius: .75rem; background: white; }
        h1 { margin-top: 0; } fieldset { border: 0; padding: 0; margin: 1.5rem 0; }
        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        label { display: grid; gap: .4rem; font-size: .9rem; } .full { grid-column: 1 / -1; }
        input, select, button { box-sizing: border-box; min-height: 2.5rem; border: 1px solid #a8a29e; border-radius: .4rem; padding: .55rem .7rem; font: inherit; background: white; color: inherit; }
        button { width: 100%; border-color: #1c1917; background: #1c1917; color: white; font-weight: 600; cursor: pointer; }
        .errors { border: 1px solid #dc2626; border-radius: .4rem; padding: .75rem 1rem; color: #991b1b; background: #fef2f2; }
        small { color: #57534e; } @media (max-width: 600px) { .grid { grid-template-columns: 1fr; } .full { grid-column: auto; } main { padding: 1.25rem; margin: 1rem auto; } }
    </style>
</head>
<body>
<main>
    <h1>GymSLunity einrichten</h1>
    <p>Im ersten Schritt werden Anwendungsschlüssel und Datenbankverbindung sicher in <code>.env</code> gespeichert.</p>
    <?php if ($errors !== []) { ?>
        <div class="errors"><ul><?php foreach ($errors as $error) { ?><li><?= $escape($error) ?></li><?php } ?></ul></div>
    <?php } ?>
    <form method="post" action="/install">
        <input type="hidden" name="_token" value="<?= $escape((string) $_SESSION['installer_token']) ?>">
        <div class="grid">
            <label class="full">Einrichtungscode
                <input name="setup_token" required autocomplete="off" spellcheck="false">
                <small>Steht in der Datei <code>storage/app/setup-token</code> auf dem Server (z. B. per SSH mit <code>cat storage/app/setup-token</code> oder per FTP).</small>
            </label>
            <label class="full">Öffentliche URL
                <input name="app_url" type="url" required value="<?= $escape((string) ($_POST['app_url'] ?? $defaultUrl)) ?>">
                <small>Für Passkeys ist außerhalb von localhost HTTPS erforderlich.</small>
            </label>
            <label>Datenbank
                <select name="db_connection">
                    <option value="sqlite">SQLite (einfach)</option>
                    <option value="mariadb" <?= ($_POST['db_connection'] ?? '') === 'mariadb' ? 'selected' : '' ?>>MariaDB</option>
                    <option value="mysql" <?= ($_POST['db_connection'] ?? '') === 'mysql' ? 'selected' : '' ?>>MySQL</option>
                </select>
            </label>
            <div></div>
            <label>Host <input name="db_host" value="<?= $escape((string) ($_POST['db_host'] ?? '127.0.0.1')) ?>"></label>
            <label>Port <input name="db_port" inputmode="numeric" value="<?= $escape((string) ($_POST['db_port'] ?? '3306')) ?>"></label>
            <label>Datenbankname <input name="db_database" value="<?= $escape((string) ($_POST['db_database'] ?? 'gymslunity')) ?>"></label>
            <label>Benutzername <input name="db_username" autocomplete="username" value="<?= $escape((string) ($_POST['db_username'] ?? 'gymslunity')) ?>"></label>
            <label class="full">Datenbankpasswort <input name="db_password" type="password" autocomplete="new-password"></label>
        </div>
        <button type="submit">Konfiguration speichern und fortfahren</button>
    </form>
</main>
</body>
</html>
