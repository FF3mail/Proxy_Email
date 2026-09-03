<?php
declare(strict_types=1);

session_start();

// ============================================================
// Подключение общих зависимостей панели управления
// ============================================================
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

// Проверяем доступ только из локальной сети (используем существующую функцию)
checkLocalNetworkAccess();
requirePanelAdmin();

// ============================================================
// Константы страницы мониторинга
// ============================================================

// Максимальное число строк, считываемых с хвоста каждого лог-файла
const MONITOR_TAIL_LINES = 200;

// Максимальное число событий, отображаемых в каждой секции
const MONITOR_EVENTS_PER_SECTION = 30;

// Директория с лог-файлами демона и веб-панели
const LOG_DIR = '/var/log/mail-proxy';

// Путь к pid-файлу демона для определения его статуса
const DAEMON_PID_FILE = '/var/run/mail-proxy/mail-proxy.pid';

// Имя systemd-юнита для проверки через systemctl
const DAEMON_SERVICE_NAME = 'mail-proxy';

// ============================================================
// Вспомогательные функции парсинга логов
// ============================================================

/**
 * Читает последние $lines строк файла без загрузки всего файла в память.
 * Использует побайтовый обход с конца файла — эффективно для больших логов.
 *
 * @param string $filePath  Полный путь к лог-файлу
 * @param int    $lines     Количество строк с хвоста
 * @return string[]         Массив строк (без завершающего \n)
 */
function tailFile(string $filePath, int $lines): array
{
    if (!is_readable($filePath)) {
        return [];
    }

    $fp = @fopen($filePath, 'rb');
    if ($fp === false) {
        return [];
    }

    $result  = [];
    $buffer  = '';
    $found   = 0;

    // Перемещаемся в конец файла
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);

    // Читаем блоками по 4096 байт с конца
    while ($pos > 0 && $found < $lines) {
        $chunkSize = min(4096, $pos);
        $pos      -= $chunkSize;
        fseek($fp, $pos);
        $chunk  = fread($fp, $chunkSize);
        $buffer = $chunk . $buffer;

        // Разбиваем накопленный буфер по переносам строк
        $parts = explode("\n", $buffer);

        // Последний (незавершённый) фрагмент оставляем в буфере
        $buffer = array_shift($parts);

        // Добавляем завершённые строки в начало результата
        foreach (array_reverse($parts) as $line) {
            if ($found >= $lines) {
                break;
            }
            $result[] = $line;
            $found++;
        }
    }

    // Если в буфере остался последний фрагмент — добавляем его
    if ($buffer !== '' && $found < $lines) {
        $result[] = $buffer;
    }

    fclose($fp);

    // Возвращаем строки в хронологическом порядке (старые → новые)
    return array_reverse($result);
}

/**
 * Парсит одну строку лога и возвращает структурированный массив.
 * Обрабатывает оба формата: PHP-панель и Python-демон.
 *
 * PHP-формат:  [2025-01-15 14:23:01] сообщение
 * Python-формат: 2025-01-15 14:23:01,123 ERROR mail_proxy.daemon: сообщение
 *
 * @param string $line  Строка лога
 * @return array{ts: string, level: string, message: string}|null
 */
function parseLogLine(string $line): ?array
{
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    // Формат PHP-панели: [YYYY-MM-DD HH:MM:SS] сообщение
    if (preg_match(
        '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(.+)$/s',
        $line,
        $m
    )) {
        $msg   = $m[2];
        $level = detectLevel($msg);
        return ['ts' => $m[1], 'level' => $level, 'message' => $msg];
    }

    // Формат Python-демона: YYYY-MM-DD HH:MM:SS,mmm LEVEL logger: сообщение
    if (preg_match(
        '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}),\d+\s+(DEBUG|INFO|WARNING|ERROR|CRITICAL)\s+\S+:\s+(.+)$/s',
        $line,
        $m
    )) {
        return ['ts' => $m[1], 'level' => strtoupper($m[2]), 'message' => $m[3]];
    }

    return null;
}

/**
 * Определяет уровень события по ключевым словам в тексте сообщения.
 * Используется для PHP-формата, где уровень не выводится явно.
 *
 * @param string $msg  Текст сообщения
 * @return string      Уровень: ERROR | WARNING | INFO
 */
function detectLevel(string $msg): string
{
    $upper = strtoupper($msg);
    if (str_contains($upper, 'ERROR') || str_contains($upper, 'CRITICAL')) {
        return 'ERROR';
    }
    if (str_contains($upper, 'WARN') || str_contains($upper, 'FAIL')) {
        return 'WARNING';
    }
    return 'INFO';
}

/**
 * Загружает и парсит все строки из лог-файла.
 * Возвращает только строки соответствующие фильтру уровней/ключевых слов.
 *
 * @param string   $filePath  Путь к файлу
 * @param string[] $keywords  Ключевые слова для фильтрации (регистр игнорируется)
 * @param string[] $levels    Уровни для фильтрации (ERROR, WARNING, CRITICAL и т.д.)
 * @param int      $limit     Максимум результирующих записей
 * @return array[]            Массив распарсенных записей
 */
function loadLogEvents(
    string $filePath,
    array $keywords = [],
    array $levels   = [],
    int   $limit    = MONITOR_EVENTS_PER_SECTION
): array {
    $lines  = tailFile($filePath, MONITOR_TAIL_LINES);
    $events = [];

    foreach (array_reverse($lines) as $line) {
        $parsed = parseLogLine($line);
        if ($parsed === null) {
            continue;
        }

        // Фильтрация по уровню
        if (!empty($levels) && !in_array($parsed['level'], $levels, true)) {
            continue;
        }

        // Фильтрация по ключевым словам (хотя бы одно должно присутствовать)
        if (!empty($keywords)) {
            $found = false;
            $upper = strtoupper($parsed['message']);
            foreach ($keywords as $kw) {
                if (str_contains($upper, strtoupper($kw))) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                continue;
            }
        }

        $events[] = $parsed;

        if (count($events) >= $limit) {
            break;
        }
    }

    return $events;
}

// ============================================================
// Определение статуса демона
// ============================================================

/**
 * Проверяет состояние демона через systemctl is-active.
 * Возвращает массив с полем status (active|inactive|failed|unknown)
 * и uptime если демон активен.
 *
 * @return array{status: string, uptime: string, pid: int|null}
 */
function getDaemonStatus(): array
{
    // Получаем статус через systemctl
    $statusOutput = shell_exec(
        'systemctl is-active ' . escapeshellarg(DAEMON_SERVICE_NAME) . ' 2>/dev/null'
    );
    $status = trim((string)($statusOutput ?? ''));

    // Допустимые значения: active, inactive, failed, activating, deactivating
    if (!in_array($status, ['active', 'inactive', 'failed', 'activating'], true)) {
        $status = 'unknown';
    }

    $uptime = '';
    $pid    = null;

    if ($status === 'active') {
        // Получаем время запуска через systemctl show
        $activeEnter = shell_exec(
            'systemctl show ' . escapeshellarg(DAEMON_SERVICE_NAME)
            . ' --property=ActiveEnterTimestamp --value 2>/dev/null'
        );
        $activeEnter = trim((string)($activeEnter ?? ''));

        if ($activeEnter !== '' && $activeEnter !== 'n/a') {
            $startTime = strtotime($activeEnter);
            if ($startTime !== false) {
                $seconds = time() - $startTime;
                $uptime  = formatUptime($seconds);
            }
        }

        // Получаем PID главного процесса
        $pidOutput = shell_exec(
            'systemctl show ' . escapeshellarg(DAEMON_SERVICE_NAME)
            . ' --property=MainPID --value 2>/dev/null'
        );
        $pidVal = (int)trim((string)($pidOutput ?? ''));
        if ($pidVal > 0) {
            $pid = $pidVal;
        }
    }

    return ['status' => $status, 'uptime' => $uptime, 'pid' => $pid];
}

/**
 * Форматирует количество секунд в читаемую строку вида "3д 5ч 12м".
 *
 * @param int $seconds  Количество секунд
 * @return string
 */
function formatUptime(int $seconds): string
{
    $days    = intdiv($seconds, 86400);
    $hours   = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    $parts = [];
    if ($days > 0)    $parts[] = "{$days}д";
    if ($hours > 0)   $parts[] = "{$hours}ч";
    $parts[] = "{$minutes}м";

    return implode(' ', $parts);
}
// ============================================================
// Сбор данных для всех секций мониторинга
// ============================================================

$daemonLog  = LOG_DIR . '/mail-proxy-daemon.log';
$webAdminLog = LOG_DIR . '/web_admin.log';

// Статус демона
$daemonStatus = getDaemonStatus();

// Последние ошибки OAuth2 — ищем в логе демона
$oauthErrors = loadLogEvents(
    $daemonLog,
    keywords: ['OAuth2', 'XOAUTH2', 'token'],
    levels:   ['ERROR', 'CRITICAL', 'WARNING']
);

// Последние ошибки SMTP — ищем в логе демона
$smtpErrors = loadLogEvents(
    $daemonLog,
    keywords: ['SMTP', 'smtp', 'sendmail', 'MAIL FROM', 'RCPT TO', 'DATA'],
    levels:   ['ERROR', 'CRITICAL', 'WARNING']
);

// Последние ошибки IMAP — ищем в логе демона
$imapErrors = loadLogEvents(
    $daemonLog,
    keywords: ['IMAP', 'imap', 'INBOX', 'UNSEEN'],
    levels:   ['ERROR', 'CRITICAL', 'WARNING']
);

// Критические события — любые CRITICAL и ERROR из обоих логов
$criticalEvents = array_merge(
    loadLogEvents($daemonLog,   levels: ['CRITICAL', 'ERROR'], limit: 15),
    loadLogEvents($webAdminLog, levels: ['ERROR'],             limit: 15)
);

// Сортируем критические события по времени — новые первыми
usort($criticalEvents, fn($a, $b) => strcmp($b['ts'], $a['ts']));
$criticalEvents = array_slice($criticalEvents, 0, MONITOR_EVENTS_PER_SECTION);

// ============================================================
// Вспомогательная функция рендеринга таблицы событий
// ============================================================

/**
 * Рендерит HTML-таблицу событий лога.
 * Каждая строка раскрашивается по уровню важности.
 *
 * @param array[] $events  Массив распарсенных событий
 * @param string  $empty   Текст при пустом списке
 */
function renderEventsTable(array $events, string $empty = 'Событий не найдено'): void
{
    if (empty($events)) {
        echo '<p class="no-events">' . h($empty) . '</p>';
        return;
    }

    echo '<table class="events-table">';
    echo '<thead><tr><th>Время</th><th>Уровень</th><th>Сообщение</th></tr></thead>';
    echo '<tbody>';

    foreach ($events as $ev) {
        $levelClass = match($ev['level']) {
            'CRITICAL' => 'level-critical',
            'ERROR'    => 'level-error',
            'WARNING'  => 'level-warning',
            default    => 'level-info',
        };

        echo '<tr class="' . $levelClass . '">';
        echo '<td class="ts">'  . h($ev['ts'])      . '</td>';
        echo '<td class="lvl">' . h($ev['level'])   . '</td>';
        // Ограничиваем длину сообщения для читаемости таблицы
        $msg = mb_strlen($ev['message']) > 300
            ? mb_substr($ev['message'], 0, 297) . '...'
            : $ev['message'];
        echo '<td class="msg">' . h($msg) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

// ============================================================
// Рендеринг HTML-страницы
// ============================================================

$statusLabel = match($daemonStatus['status']) {
    'active'     => '<span class="badge badge-ok">● Работает</span>',
    'inactive'   => '<span class="badge badge-warn">○ Остановлен</span>',
    'failed'     => '<span class="badge badge-err">✗ Ошибка</span>',
    'activating' => '<span class="badge badge-warn">⟳ Запускается</span>',
    default      => '<span class="badge badge-unknown">? Неизвестно</span>',
};
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mail-Proxy — Мониторинг</title>
<style>
    /* Базовые стили — согласованы с существующим интерфейсом панели управления */
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-size: 14px;
        background: #f4f6f8;
        color: #333;
    }

    .container { max-width: 1400px; margin: 0 auto; padding: 20px; }

    h1 { font-size: 22px; margin-bottom: 20px; color: #2c3e50; }
    h2 { font-size: 16px; margin: 24px 0 10px; color: #2c3e50;
         border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }

    /* Карточка статуса демона */
    .status-card {
        background: #fff;
        border-radius: 6px;
        padding: 16px 20px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        display: flex;
        align-items: center;
        gap: 32px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }

    .status-card .label { color: #666; font-size: 12px; text-transform: uppercase; }
    .status-card .value { font-size: 16px; font-weight: 600; margin-top: 2px; }

    /* Бейджи статуса */
    .badge { display: inline-block; padding: 4px 12px;
             border-radius: 12px; font-size: 13px; font-weight: 600; }
    .badge-ok      { background: #d4edda; color: #155724; }
    .badge-warn    { background: #fff3cd; color: #856404; }
    .badge-err     { background: #f8d7da; color: #721c24; }
    .badge-unknown { background: #e2e3e5; color: #383d41; }

    /* Секция событий */
    .section {
        background: #fff;
        border-radius: 6px;
        padding: 16px 20px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        margin-bottom: 20px;
    }

    /* Таблица событий */
    .events-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .events-table th {
        background: #f0f2f5;
        text-align: left;
        padding: 7px 10px;
        border-bottom: 2px solid #ddd;
        font-weight: 600;
        white-space: nowrap;
    }

    .events-table td {
        padding: 6px 10px;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: top;
    }

    .events-table td.ts  { white-space: nowrap; color: #555; width: 160px; }
    .events-table td.lvl { white-space: nowrap; font-weight: 600; width: 90px; }
    .events-table td.msg { word-break: break-word; font-family: monospace; }

    /* Раскраска строк по уровню */
    tr.level-critical { background: #fff0f0; }
    tr.level-critical td.lvl { color: #c0392b; }
    tr.level-error    { background: #fff8f8; }
    tr.level-error    td.lvl { color: #e74c3c; }
    tr.level-warning  { background: #fffdf0; }
    tr.level-warning  td.lvl { color: #e67e22; }
    tr.level-info     td.lvl { color: #27ae60; }

    .no-events { color: #888; font-style: italic; padding: 8px 0; }

    /* Навигационная панель */
    .nav { background: #2c3e50; padding: 10px 20px;
           display: flex; gap: 20px; align-items: center; }
    .nav a { color: #ecf0f1; text-decoration: none; font-size: 14px; }
    .nav a:hover { color: #3498db; }
    .nav a.active { color: #3498db; font-weight: 600; }

    /* Метаинформация страницы */
    .page-meta { color: #999; font-size: 12px; margin-bottom: 16px; }

    /* Кнопка обновления */
    .btn-refresh {
        display: inline-block;
        padding: 6px 14px;
        background: #3498db;
        color: #fff;
        border-radius: 4px;
        text-decoration: none;
        font-size: 13px;
        margin-left: auto;
    }
    .btn-refresh:hover { background: #2980b9; }
</style>
</head>
<body>

<!-- Навигационное меню — интегрируется с существующей панелью управления -->
<nav class="nav">
    <a href="/index.php">Панель управления</a>
    <a href="/index.php?action=referents">Референты</a>
    <a href="/index.php?action=accounts">Аккаунты</a>
    <a href="/index.php?action=providers">Провайдеры</a>
    <a href="/monitor.php" class="active">Мониторинг</a>
</nav>

<div class="container">
    <div style="display:flex; align-items:center; margin: 20px 0 4px;">
        <h1>Мониторинг системы</h1>
        <a href="/monitor.php" class="btn-refresh">↻ Обновить</a>
    </div>
    <p class="page-meta">
        Данные из: <?= h(LOG_DIR) ?> &nbsp;|&nbsp;
        Обновлено: <?= h(date('Y-m-d H:i:s')) ?>
    </p>

    <!-- Секция 1: Статус демона -->
    <div class="status-card">
        <div>
            <div class="label">Служба</div>
            <div class="value"><?= $statusLabel ?></div>
        </div>
        <?php if ($daemonStatus['uptime'] !== ''): ?>
        <div>
            <div class="label">Uptime</div>
            <div class="value"><?= h($daemonStatus['uptime']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($daemonStatus['pid'] !== null): ?>
        <div>
            <div class="label">PID</div>
            <div class="value"><?= (int)$daemonStatus['pid'] ?></div>
        </div>
        <?php endif; ?>
        <div>
            <div class="label">Лог демона</div>
            <div class="value" style="font-size:13px; font-weight:400;">
                <?= h($daemonLog) ?>
                <?php if (!is_readable($daemonLog)): ?>
                    <span style="color:#e74c3c"> (недоступен)</span>
                <?php else: ?>
                    <span style="color:#27ae60"> (доступен)</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Секция 2: Критические события -->
    <div class="section">
        <h2>Критические события (ERROR + CRITICAL, оба лога)</h2>
        <?php renderEventsTable($criticalEvents, 'Критических событий не обнаружено'); ?>
    </div>

    <!-- Секция 3: Ошибки OAuth2 -->
    <div class="section">
        <h2>Ошибки OAuth2</h2>
        <?php renderEventsTable($oauthErrors, 'Ошибок OAuth2 не обнаружено'); ?>
    </div>

    <!-- Секция 4: Ошибки SMTP -->
    <div class="section">
        <h2>Ошибки SMTP</h2>
        <?php renderEventsTable($smtpErrors, 'Ошибок SMTP не обнаружено'); ?>
    </div>

    <!-- Секция 5: Ошибки IMAP -->
    <div class="section">
        <h2>Ошибки IMAP</h2>
        <?php renderEventsTable($imapErrors, 'Ошибок IMAP не обнаружено'); ?>
    </div>

</div><!-- /container -->
</body>
</html>