#!/usr/bin/env php
<?php
/**
 * PROMPT-72: benchmark findRelationshipMaildirPathCollision() against fixture DB.
 * Requires PDO mysql and mail_proxy_scale_test populated by scale_verification_harness.py.
 *
 * Env: SCALE_TEST_DB_HOST, SCALE_TEST_DB_PORT, SCALE_TEST_DB_USER,
 *      SCALE_TEST_DB_PASS, SCALE_TEST_DB_NAME
 */
declare(strict_types=1);

require_once __DIR__ . '/../web/includes/i18n.php';
initPanelI18n();
require_once __DIR__ . '/../web/includes/relationship_editor.php';

$host = getenv('SCALE_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('SCALE_TEST_DB_PORT') ?: '3306';
$user = getenv('SCALE_TEST_DB_USER') ?: 'root';
$pass = getenv('SCALE_TEST_DB_PASS') ?: '';
$dbName = getenv('SCALE_TEST_DB_NAME') ?: 'mail_proxy_scale_test';
$unixSocket = getenv('SCALE_TEST_DB_UNIX_SOCKET') ?: '';
if ($unixSocket === '' && is_readable('/var/run/mysqld/mysqld.sock')) {
    $unixSocket = '/var/run/mysqld/mysqld.sock';
}

if ($unixSocket !== '') {
    $dsn = "mysql:unix_socket={$unixSocket};dbname={$dbName};charset=utf8mb4";
} else {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
}

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'error' => 'pdo_connect_failed',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$stmt = $pdo->query(
    'SELECT id, referent_id, local_client_maildir FROM clients ORDER BY id LIMIT 1'
);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "No clients in {$dbName}\n");
    exit(1);
}

$referentId = (int)$row['referent_id'];
$excludeId = (int)$row['id'];
$maildir = (string)$row['local_client_maildir'];

$countStmt = $pdo->query('SELECT COUNT(*) FROM clients');
$clientCount = (int)$countStmt->fetchColumn();

$iterations = 200;

$start = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    findRelationshipMaildirPathCollision($pdo, $referentId, $excludeId, $maildir);
}
$maildirMs = (hrtime(true) - $start) / 1_000_000;

$uniqueData = [
    'external_client_email' => 'bench-unique@partner.scale.test',
    'local_client_email' => 'bench-local@scale.test',
    'local_referent_email' => 'bench-ref@scale.test',
    'local_client_maildir' => '/var/vmail/bench/unique/Maildir',
    'external_account_id' => 999999,
];
$start = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    findRelationshipUniqueCollision($pdo, $referentId, $excludeId, $uniqueData);
}
$uniqueMs = (hrtime(true) - $start) / 1_000_000;

echo json_encode([
    'clients_rows' => $clientCount,
    'iterations' => $iterations,
    'findRelationshipMaildirPathCollision' => [
        'total_ms' => round($maildirMs, 3),
        'avg_ms_per_call' => round($maildirMs / $iterations, 4),
        'interactive_save_ok' => ($maildirMs / $iterations) < 50,
    ],
    'findRelationshipUniqueCollision' => [
        'total_ms' => round($uniqueMs, 3),
        'avg_ms_per_call' => round($uniqueMs / $iterations, 4),
        'interactive_save_ok' => ($uniqueMs / $iterations) < 50,
    ],
], JSON_PRETTY_PRINT) . "\n";
