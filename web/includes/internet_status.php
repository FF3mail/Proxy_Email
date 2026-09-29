<?php
declare(strict_types=1);

/**
 * Host outbound-internet reachability for the panel (issue #39).
 *
 * Probes run on the VPS (PHP), never from the operator's browser.
 * Page render reads a small JSON cache and does not wait on sockets.
 * AJAX action=internet_status may refresh the cache when it is stale.
 *
 * Override targets in config.php:
 *   define('PANEL_INTERNET_PROBE_TARGETS', [
 *       ['host' => '1.1.1.1', 'port' => 443],
 *       ['host' => '8.8.8.8', 'port' => 443],
 *       ['host' => 'imap.gmail.com', 'port' => 993],
 *   ]);
 * Cache path: define('PANEL_INTERNET_STATUS_CACHE', '/path/to/file.json');
 */

/** @var list<array{host: string, port: int}> */
const PANEL_INTERNET_PROBE_DEFAULTS = [
    ['host' => '1.1.1.1', 'port' => 443],
    ['host' => '8.8.8.8', 'port' => 443],
    ['host' => 'imap.gmail.com', 'port' => 993],
];

if (!defined('PANEL_INTERNET_PROBE_TIMEOUT')) {
    define('PANEL_INTERNET_PROBE_TIMEOUT', 2.0);
}
if (!defined('PANEL_INTERNET_STATUS_CACHE_TTL')) {
    define('PANEL_INTERNET_STATUS_CACHE_TTL', 30);
}
if (!defined('PANEL_INTERNET_STATUS_FAIL_STREAK')) {
    define('PANEL_INTERNET_STATUS_FAIL_STREAK', 2);
}
if (!defined('PANEL_INTERNET_STATUS_POLL_MS')) {
    define('PANEL_INTERNET_STATUS_POLL_MS', 45000);
}

/**
 * @return list<array{host: string, port: int}>
 */
function internetStatusTargets(): array
{
    if (defined('PANEL_INTERNET_PROBE_TARGETS')) {
        $raw = constant('PANEL_INTERNET_PROBE_TARGETS');
        if (is_array($raw) && $raw !== []) {
            $out = [];
            foreach ($raw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $host = trim((string) ($row['host'] ?? ''));
                $port = (int) ($row['port'] ?? 0);
                if ($host === '' || $port < 1 || $port > 65535) {
                    continue;
                }
                $out[] = ['host' => $host, 'port' => $port];
            }
            if ($out !== []) {
                return $out;
            }
        }
    }
    return PANEL_INTERNET_PROBE_DEFAULTS;
}

function internetStatusCachePath(): string
{
    if (defined('PANEL_INTERNET_STATUS_CACHE') && PANEL_INTERNET_STATUS_CACHE !== '') {
        return (string) PANEL_INTERNET_STATUS_CACHE;
    }
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'mail-proxy-internet-status.json';
}

function internetStatusCacheTtl(): int
{
    $n = defined('PANEL_INTERNET_STATUS_CACHE_TTL')
        ? (int) constant('PANEL_INTERNET_STATUS_CACHE_TTL')
        : 30;
    return $n > 0 ? $n : 30;
}

function internetStatusProbeTimeout(): float
{
    $n = defined('PANEL_INTERNET_PROBE_TIMEOUT')
        ? (float) constant('PANEL_INTERNET_PROBE_TIMEOUT')
        : 2.0;
    return ($n > 0.2 && $n <= 10) ? $n : 2.0;
}

function internetStatusFailStreakThreshold(): int
{
    $n = defined('PANEL_INTERNET_STATUS_FAIL_STREAK')
        ? (int) constant('PANEL_INTERNET_STATUS_FAIL_STREAK')
        : 2;
    return $n >= 1 ? $n : 2;
}

/**
 * TCP connect only — no HTTP body, no TLS handshake required.
 */
function internetStatusProbeTcp(string $host, int $port, float $timeout): bool
{
    $host = trim($host);
    if ($host === '' || $port < 1 || $port > 65535) {
        return false;
    }

    $errno = 0;
    $errstr = '';
    $remote = 'tcp://' . $host . ':' . $port;
    $fp = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );
    if ($fp === false) {
        return false;
    }
    fclose($fp);
    return true;
}

/**
 * Majority of completed probes succeed → online candidate.
 * Majority fail → failure (offline only after consecutive cycles).
 * Zero completed probes → unknown.
 *
 * @return array{status: 'online'|'offline'|'unknown', fail_streak: int}
 */
function internetStatusDecide(int $ok, int $fail, int $prevStreak): array
{
    $total = $ok + $fail;
    if ($total < 1) {
        return ['status' => 'unknown', 'fail_streak' => max(0, $prevStreak)];
    }
    if ($ok * 2 > $total) {
        return ['status' => 'online', 'fail_streak' => 0];
    }
    $streak = max(0, $prevStreak) + 1;
    $status = $streak >= internetStatusFailStreakThreshold() ? 'offline' : 'unknown';
    return ['status' => $status, 'fail_streak' => $streak];
}

/**
 * @param callable(string, int, float): bool|null $probeFn
 * @return array{ok: int, fail: int, probed: int}
 */
function internetStatusRunProbes(?callable $probeFn = null): array
{
    $probe = $probeFn ?? 'internetStatusProbeTcp';
    $timeout = internetStatusProbeTimeout();
    $targets = internetStatusTargets();
    $n = count($targets);
    $ok = 0;
    $fail = 0;
    $needOk = intdiv($n, 2) + 1;

    foreach ($targets as $t) {
        $host = (string) $t['host'];
        $port = (int) $t['port'];
        $good = false;
        try {
            $good = (bool) $probe($host, $port, $timeout);
        } catch (Throwable $e) {
            $good = false;
        }
        if ($good) {
            $ok++;
        } else {
            $fail++;
        }
        if ($ok >= $needOk) {
            break;
        }
        if ($fail > $n - $needOk) {
            break;
        }
    }

    return ['ok' => $ok, 'fail' => $fail, 'probed' => $ok + $fail];
}

/**
 * @return array{status: string, checked_at: int, fail_streak: int, ok: int, fail: int, probed: int}|null
 */
function internetStatusReadCache(): ?array
{
    $path = internetStatusCachePath();
    if (!is_readable($path)) {
        return null;
    }
    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        return null;
    }
    $raw = '';
    try {
        if (!flock($fp, LOCK_SH)) {
            return null;
        }
        $raw = (string) stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $status = (string) ($data['status'] ?? '');
    if (!in_array($status, ['online', 'offline', 'unknown'], true)) {
        return null;
    }
    return [
        'status' => $status,
        'checked_at' => (int) ($data['checked_at'] ?? 0),
        'fail_streak' => max(0, (int) ($data['fail_streak'] ?? 0)),
        'ok' => max(0, (int) ($data['ok'] ?? 0)),
        'fail' => max(0, (int) ($data['fail'] ?? 0)),
        'probed' => max(0, (int) ($data['probed'] ?? 0)),
    ];
}

/**
 * @param array{status: string, checked_at: int, fail_streak: int, ok: int, fail: int, probed: int} $state
 */
function internetStatusWriteCache(array $state): void
{
    $path = internetStatusCachePath();
    $dir = dirname($path);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $json = json_encode($state, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return;
    }
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return;
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            return;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

/**
 * @return array{status: string, checked_at: int, fail_streak: int, ok: int, fail: int, probed: int}
 */
function internetStatusEmptyState(): array
{
    return [
        'status' => 'unknown',
        'checked_at' => 0,
        'fail_streak' => 0,
        'ok' => 0,
        'fail' => 0,
        'probed' => 0,
    ];
}

/**
 * @param callable(string, int, float): bool|null $probeFn
 * @return array{status: string, checked_at: int, fail_streak: int, ok: int, fail: int, probed: int}
 */
function internetStatusRefresh(?callable $probeFn = null): array
{
    $prev = internetStatusReadCache() ?? internetStatusEmptyState();
    try {
        $counts = internetStatusRunProbes($probeFn);
    } catch (Throwable $e) {
        $state = internetStatusEmptyState();
        $state['fail_streak'] = (int) $prev['fail_streak'];
        $state['checked_at'] = time();
        internetStatusWriteCache($state);
        return $state;
    }
    $decided = internetStatusDecide(
        (int) $counts['ok'],
        (int) $counts['fail'],
        (int) $prev['fail_streak']
    );
    $state = [
        'status' => $decided['status'],
        'checked_at' => time(),
        'fail_streak' => $decided['fail_streak'],
        'ok' => (int) $counts['ok'],
        'fail' => (int) $counts['fail'],
        'probed' => (int) $counts['probed'],
    ];
    internetStatusWriteCache($state);
    return $state;
}

/**
 * @return array{status: string, checked_at: int, fail_streak: int, ok: int, fail: int, probed: int}
 */
function internetStatusGet(bool $allowProbe = false, ?callable $probeFn = null): array
{
    $cached = internetStatusReadCache();
    if ($cached !== null) {
        $age = time() - (int) $cached['checked_at'];
        if ($age >= 0 && $age < internetStatusCacheTtl()) {
            return $cached;
        }
        if (!$allowProbe) {
            return $cached;
        }
    } elseif (!$allowProbe) {
        return internetStatusEmptyState();
    }
    return internetStatusRefresh($probeFn);
}

function internetStatusChipClass(string $status): string
{
    return match ($status) {
        'online' => 'pm-chip-ok',
        'offline' => 'pm-chip-bad',
        default => 'pm-chip-warn',
    };
}

function internetStatusDotClass(string $status): string
{
    return match ($status) {
        'online' => 'pm-inet-dot-ok',
        'offline' => 'pm-inet-dot-bad',
        default => 'pm-inet-dot-warn',
    };
}

function internetStatusStateLabel(string $status): string
{
    return match ($status) {
        'online' => __('dashboard.internet_online'),
        'offline' => __('dashboard.internet_offline'),
        default => __('dashboard.internet_unknown'),
    };
}

/**
 * @param array{status?: string, checked_at?: int} $state
 * @return array{
 *   status: string,
 *   checked_at: int,
 *   label: string,
 *   chip_class: string,
 *   dot_class: string,
 *   tooltip: string,
 *   warning: string
 * }
 */
function internetStatusView(array $state): array
{
    $status = (string) ($state['status'] ?? 'unknown');
    if (!in_array($status, ['online', 'offline', 'unknown'], true)) {
        $status = 'unknown';
    }
    $checkedAt = (int) ($state['checked_at'] ?? 0);
    $when = $checkedAt > 0
        ? gmdate('Y-m-d H:i:s', $checkedAt) . ' UTC'
        : __('dashboard.internet_never');
    $tooltip = __('dashboard.internet_checked', ['time' => $when]);
    $warning = $status === 'offline' ? __('dashboard.internet_offline_warning') : '';
    if ($warning !== '') {
        $tooltip .= ' — ' . $warning;
    }
    return [
        'status' => $status,
        'checked_at' => $checkedAt,
        'label' => internetStatusStateLabel($status),
        'chip_class' => internetStatusChipClass($status),
        'dot_class' => internetStatusDotClass($status),
        'tooltip' => $tooltip,
        'warning' => $warning,
    ];
}

/**
 * @return array<string, mixed>
 */
function internetStatusJsonPayload(bool $allowProbe = true): array
{
    return internetStatusView(internetStatusGet($allowProbe));
}

function handleInternetStatusJson(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(internetStatusJsonPayload(true), JSON_UNESCAPED_UNICODE);
    exit();
}

function renderInternetStatusChip(array $view, string $variant = 'dashboard'): void
{
    $chipClass = 'pm-chip ' . $view['chip_class'];
    if ($variant === 'sidebar') {
        ?>
        <div class="pm-inet" title="<?= h((string) $view['tooltip']) ?>">
            <span class="pm-inet-text">
                <span class="pm-inet-lbl"><?= h(__('dashboard.internet')) ?></span>
                <span class="<?= h($chipClass) ?>"
                      data-internet-chip
                      aria-live="polite"
                      title="<?= h((string) $view['tooltip']) ?>"><?= h((string) $view['label']) ?></span>
            </span>
            <span class="pm-inet-dot <?= h((string) $view['dot_class']) ?>"
                  data-internet-dot
                  title="<?= h((string) $view['tooltip']) ?>"
                  aria-hidden="true"></span>
        </div>
        <?php
        return;
    }
    ?>
    <span class="<?= h($chipClass) ?>"
          data-internet-chip
          aria-live="polite"
          title="<?= h((string) $view['tooltip']) ?>"><?= h((string) $view['label']) ?></span>
    <?php
}

function renderInternetStatusPollScript(): void
{
    $url = '/index.php?action=internet_status';
    $ms = PANEL_INTERNET_STATUS_POLL_MS;
    ?>
    <script>
    (function () {
      var url = <?= json_encode($url, JSON_UNESCAPED_SLASHES) ?>;
      var ms = <?= (int) $ms ?>;
      function apply(data) {
        if (!data || typeof data.status !== 'string') return;
        var chips = document.querySelectorAll('[data-internet-chip]');
        for (var i = 0; i < chips.length; i++) {
          chips[i].className = 'pm-chip ' + (data.chip_class || 'pm-chip-warn');
          chips[i].textContent = data.label || '';
          chips[i].setAttribute('title', data.tooltip || '');
        }
        var dots = document.querySelectorAll('[data-internet-dot]');
        for (var d = 0; d < dots.length; d++) {
          dots[d].className = 'pm-inet-dot ' + (data.dot_class || 'pm-inet-dot-warn');
          dots[d].setAttribute('title', data.tooltip || '');
        }
        var wraps = document.querySelectorAll('.pm-inet');
        for (var w = 0; w < wraps.length; w++) {
          wraps[w].setAttribute('title', data.tooltip || '');
        }
        var warns = document.querySelectorAll('[data-internet-warning]');
        var offline = data.status === 'offline';
        for (var k = 0; k < warns.length; k++) {
          warns[k].hidden = !offline;
          if (offline) {
            warns[k].textContent = data.warning || '';
          }
        }
      }
      function poll() {
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(apply)
          .catch(function () {});
      }
      poll();
      setInterval(poll, ms);
    })();
    </script>
    <?php
}
