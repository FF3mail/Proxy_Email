<?php
declare(strict_types=1);

/**
 * Доверенный базовый URL веб-панели.
 * Используется для OAuth redirect_uri — НЕ строится из HTTP_HOST.
 *
 * Перед production-деплоем задайте реальный адрес панели, например:
 * define('APP_BASE_URL', 'https://mail-proxy.example.local');
 */
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', 'https://mail-proxy.local');
}

// Проверка конфигурации для production.
// Значение APP_BASE_URL обязательно должно быть заменено администратором
// на реальный публичный URL веб-панели до production-запуска,
// иначе OAuth redirect_uri будет работать некорректно.
if (
    strpos(APP_BASE_URL, 'mail-proxy.local') !== false ||
    strpos(APP_BASE_URL, 'localhost') !== false ||
    strpos(APP_BASE_URL, '127.0.0.1') !== false
) {
    error_log('[DELTA-transit] ПРЕДУПРЕЖДЕНИЕ: APP_BASE_URL содержит заглушку или локальный адрес. '
        . 'Укажите реальный публичный URL перед production-запуском.');
}

// Обратная совместимость с существующим кодом
if (!defined('PUBLIC_BASE_URL')) {
    define('PUBLIC_BASE_URL', APP_BASE_URL);
}

// Optional: TCP probe targets for the panel internet-availability chip (issue #39).
// Uncomment to replace the defaults (Cloudflare 1.1.1.1:443, Google 8.8.8.8:443, imap.gmail.com:993).
// define('PANEL_INTERNET_PROBE_TARGETS', [
//     ['host' => '1.1.1.1', 'port' => 443],
//     ['host' => '8.8.8.8', 'port' => 443],
//     ['host' => 'imap.gmail.com', 'port' => 993],
// ]);