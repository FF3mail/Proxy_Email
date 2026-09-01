<?php
/**
 * ТРЕБОВАНИЯ К НАСТРОЙКЕ NGINX ДЛЯ КОРРЕКТНОЙ РАБОТЫ getClientIp()
 *
 * Функция getClientIp() доверяет заголовкам X-Real-IP и
 * X-Forwarded-For только в том случае, если запрос поступил
 * от локального reverse proxy на localhost
 * (REMOTE_ADDR = 127.0.0.1 или ::1).
 *
 * Если используется схема Nginx -> proxy_pass -> backend,
 * необходимо передавать реальный IP клиента:
 *
 *     proxy_set_header X-Real-IP $remote_addr;
 *     proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
 *
 * Никогда не использовать значения заголовков,
 * полученные от внешнего клиента:
 *
 *     proxy_set_header X-Real-IP $http_x_real_ip;
 *
 * Это позволит злоумышленнику подменить исходный IP-адрес.
 *
 * Если используется схема Nginx -> PHP-FPM через fastcgi_pass,
 * заголовки необходимо передавать через FastCGI-параметры:
 *
 *     fastcgi_param HTTP_X_REAL_IP $remote_addr;
 *     fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
 *
 * Минимальный пример location для PHP-FPM:
 *
 *     location ~ \.php$ {
 *         include fastcgi_params;
 *         fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
 *         fastcgi_param HTTP_X_REAL_IP $remote_addr;
 *         fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
 *         fastcgi_pass unix:/run/php/php-fpm.sock;
 *     }
 */
declare(strict_types=1);

use PDO;
use RuntimeException;

function getClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($remoteAddr !== '127.0.0.1' && $remoteAddr !== '::1') {
        return $remoteAddr;
    }

    $candidate = null;

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidate = trim(explode(',', (string)$_SERVER['HTTP_X_REAL_IP'])[0]);
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $candidate = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }

    if ($candidate !== null) {
        $validated = filter_var($candidate, FILTER_VALIDATE_IP);

        if ($validated !== false) {
            return $validated;
        }
    }

    return $remoteAddr;
}

function checkLocalNetworkAccess(): void
{
    $ip = getClientIp();

    if ($ip === '::1') {
        return;
    }

    $ipv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

    if ($ipv4 !== false) {
        $longIp = ip2long($ipv4);

        $networks = [
            ['127.0.0.0', '255.0.0.0'],
            ['10.0.0.0', '255.0.0.0'],
            ['172.16.0.0', '255.240.0.0'],
            ['192.168.0.0', '255.255.0.0'],
        ];

        foreach ($networks as [$network, $mask]) {
            if (
                ($longIp & ip2long($mask))
                ===
                (ip2long($network) & ip2long($mask))
            ) {
                return;
            }
        }
    }

    http_response_code(403);

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>403 Access Denied</title>
</head>
<body>
<h1>403 Access Denied</h1>
</body>
</html>
HTML;

    exit();
}

function writeLog(string $message): void
{
    $directory = '/var/log/mail-proxy';
    $file = $directory . '/web_admin.log';

    try {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException('Failed to create log directory');
            }

            @chmod($directory, 0750);
        }

        if (!file_exists($file)) {
            if (@file_put_contents($file, '') === false) {
                throw new RuntimeException('Failed to create log file');
            }

            @chmod($file, 0640);
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

        $result = @file_put_contents(
            $file,
            $line,
            FILE_APPEND | LOCK_EX
        );

        if ($result === false) {
            throw new RuntimeException('Failed to write log');
        }
    } catch (Throwable $e) {
        error_log('MAIL-PROXY LOG ERROR: ' . $e->getMessage());
    }
}

/**
 * Parses one INI section without parse_ini_file() (often disabled on hardened PHP-FPM).
 *
 * Compatible with ConfigParser-style db.conf written by delta-transit-install.sh.
 *
 * @return array<string, string>
 */
function parseIniSection(string $content, string $section): array
{
    $result = [];
    $inSection = false;
    $sectionLower = strtolower($section);

    foreach (preg_split('/\R/', $content) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#')) {
            continue;
        }

        if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
            $inSection = (strtolower(trim($matches[1])) === $sectionLower);
            continue;
        }

        if (!$inSection) {
            continue;
        }

        $equalsPos = strpos($line, '=');
        if ($equalsPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $equalsPos));
        $value = trim(substr($line, $equalsPos + 1));

        if ($key === '') {
            continue;
        }

        if (
            strlen($value) >= 2
            && (
                ($value[0] === '"' && $value[strlen($value) - 1] === '"')
                || ($value[0] === "'" && $value[strlen($value) - 1] === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        $result[$key] = $value;
    }

    return $result;
}

/**
 * Загружает секцию [db] из INI-файла конфигурации MariaDB.
 *
 * @return array<string, string>
 */
function loadDbConfig(string $configFile = '/etc/mail-proxy/db.conf'): array
{
    if (!file_exists($configFile)) {
        throw new RuntimeException('DB config file not found: ' . $configFile);
    }

    $content = file_get_contents($configFile);
    if ($content === false) {
        throw new RuntimeException('Unable to read DB config file: ' . $configFile);
    }

    $db = parseIniSection($content, 'db');

    if ($db === [] || empty($db['db_user']) || !array_key_exists('db_pass', $db)) {
        throw new RuntimeException(
            'Invalid DB config: missing [db] section in ' . $configFile
        );
    }

    return $db;
}

function getPdo(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $db = loadDbConfig();

    $host = (string)($db['db_host'] ?? '127.0.0.1');
    $port = (int)($db['db_port'] ?? 3306);
    $name = (string)($db['db_name'] ?? 'mail_proxy');

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $name
    );

    $pdo = new PDO($dsn, (string)$db['db_user'], (string)$db['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

/**
 * Проверяет CSRF-токен POST-запроса и регенерирует его после успешной проверки.
 */
function requireValidCsrfToken(): void
{
    $csrfPost = $_POST['csrf_token'] ?? '';
    $csrfSession = $_SESSION['csrf_token'] ?? '';

    if ($csrfPost === '' || $csrfSession === '' || !hash_equals($csrfSession, $csrfPost)) {
        writeLog('CSRF token mismatch for action ' . ($_POST['action'] ?? $_GET['action'] ?? 'unknown'));
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => 'Ошибка безопасности: недействительный токен CSRF',
        ];
        header('Location: /index.php');
        exit();
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Возвращает HTML-атрибут value для скрытого поля CSRF.
 */
function csrfField(): string
{
    return h($_SESSION['csrf_token'] ?? '');
}

/**
 * Проверяет, что OAuth endpoint безопасен (защита от SSRF).
 * Разрешены только публичные HTTPS-URL.
 */
function assertSafeOAuthEndpoint(string $url, string $fieldName = 'endpoint'): void
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException("Некорректный URL ($fieldName)");
    }

    $parsed = parse_url($url);

    if (!isset($parsed['scheme']) || strtolower($parsed['scheme']) !== 'https') {
        throw new RuntimeException("URL ($fieldName) должен использовать схему HTTPS");
    }

    $host = strtolower($parsed['host'] ?? '');

    if ($host === '') {
        throw new RuntimeException("URL ($fieldName) не содержит hostname");
    }

    $blockedHosts = [
        'localhost',
        'metadata.google.internal',
        'metadata.google',
        '169.254.169.254',
    ];

    if (in_array($host, $blockedHosts, true) || str_contains($host, 'metadata')) {
        throw new RuntimeException("Запрещённый hostname в $fieldName: $host");
    }

    $ips = [];

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($records === false || $records === []) {
            throw new RuntimeException("Не удалось разрешить hostname $fieldName: $host");
        }
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
    }

    foreach ($ips as $ip) {
        if (isBlockedOAuthIp($ip)) {
            throw new RuntimeException(
                "Запрещённый IP-адрес для $fieldName ($host → $ip): частные, loopback и metadata-сети недопустимы"
            );
        }
    }
}

function isBlockedOAuthIp(string $ip): bool
{
    if ($ip === '169.254.169.254') {
        return true;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $lower = strtolower($ip);
        if ($lower === '::1') {
            return true;
        }
        if (str_starts_with($lower, 'fe80:')) {
            return true;
        }
        if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
            return true;
        }
    }

    return false;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}