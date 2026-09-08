<?php
declare(strict_types=1);

/**
 * User-facing exception carrying a translation key (resolved via __() at display time).
 */
class LocalizedUserException extends RuntimeException
{
    /** @var array<string, string|int|float> */
    private array $translationParams;

    public function __construct(string $translationKey, array $translationParams = [])
    {
        $this->translationParams = $translationParams;
        parent::__construct($translationKey);
    }

    public function getTranslationKey(): string
    {
        return $this->getMessage();
    }

    /** @return array<string, string|int|float> */
    public function getTranslationParams(): array
    {
        return $this->translationParams;
    }

    public function getUserMessage(): string
    {
        return __($this->getTranslationKey(), $this->translationParams);
    }
}

/**
 * Lightweight RU/EN localization for the DELTA-transit web panel (PROMPT-45).
 *
 * Usage:
 *   __('nav.referents')              — simple lookup
 *   __('referent.mailbox_not_found', ['email' => $email]) — with placeholders
 *
 * Placeholders use {name} syntax in translation strings.
 */

const PANEL_SUPPORTED_LANGS = ['ru', 'en'];
const PANEL_DEFAULT_LANG = 'ru';
const PANEL_LANG_COOKIE = 'panel_lang';

/** @var array<string, string>|null */
$GLOBALS['_panel_translations'] = null;

/**
 * Initialize locale from ?lang=, session, cookie, or Accept-Language.
 * Call after startPanelSession() when a session exists; safe to call without session.
 */
function initPanelI18n(): void
{
    $lang = null;

    if (isset($_GET['lang'])) {
        $candidate = strtolower(trim((string)$_GET['lang']));
        if (in_array($candidate, PANEL_SUPPORTED_LANGS, true)) {
            $lang = $candidate;
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['panel_lang'] = $lang;
            }
            if (!headers_sent()) {
                setcookie(
                    PANEL_LANG_COOKIE,
                    $lang,
                    [
                        'expires' => time() + 365 * 24 * 3600,
                        'path' => '/',
                        'secure' => isHttpsRequest(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]
                );
            }
        }
    }

    if ($lang === null && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['panel_lang'])) {
        $candidate = (string)$_SESSION['panel_lang'];
        if (in_array($candidate, PANEL_SUPPORTED_LANGS, true)) {
            $lang = $candidate;
        }
    }

    if ($lang === null && !empty($_COOKIE[PANEL_LANG_COOKIE])) {
        $candidate = (string)$_COOKIE[PANEL_LANG_COOKIE];
        if (in_array($candidate, PANEL_SUPPORTED_LANGS, true)) {
            $lang = $candidate;
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['panel_lang'] = $lang;
            }
        }
    }

    if ($lang === null) {
        $lang = detectBrowserLang();
    }

    setPanelLang($lang);
}

/**
 * Best-effort browser language detection (no session required).
 */
function detectBrowserLang(): string
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($accept !== '' && str_contains($accept, 'en') && !str_contains($accept, 'ru')) {
        return 'en';
    }
    return PANEL_DEFAULT_LANG;
}

function setPanelLang(string $lang): void
{
    if (!in_array($lang, PANEL_SUPPORTED_LANGS, true)) {
        $lang = PANEL_DEFAULT_LANG;
    }
    $GLOBALS['_panel_lang'] = $lang;
    $GLOBALS['_panel_translations'] = null;
}

function currentPanelLang(): string
{
    return $GLOBALS['_panel_lang'] ?? PANEL_DEFAULT_LANG;
}

/**
 * Translate a key. Returns the key itself if missing (aids debugging).
 *
 * @param array<string, string|int|float> $params
 */
function __(string $key, array $params = []): string
{
    $lang = currentPanelLang();

    if ($GLOBALS['_panel_translations'] === null) {
        $file = __DIR__ . '/../lang/' . $lang . '.php';
        if (!is_readable($file)) {
            $file = __DIR__ . '/../lang/' . PANEL_DEFAULT_LANG . '.php';
        }
        /** @var array<string, string> $loaded */
        $loaded = is_readable($file) ? require $file : [];
        $GLOBALS['_panel_translations'] = $loaded;
    }

    $text = $GLOBALS['_panel_translations'][$key] ?? $key;

    if ($params !== []) {
        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = (string)$value;
        }
        $text = strtr($text, $replacements);
    }

    return $text;
}

/**
 * HTML lang attribute for the active locale.
 */
function panelHtmlLang(): string
{
    return currentPanelLang();
}

/**
 * Render a compact language switcher (Русский / English).
 */
function renderLanguageSelector(): void
{
    $current = currentPanelLang();
    $base = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
    $query = $_GET;
    unset($query['lang']);

    foreach (PANEL_SUPPORTED_LANGS as $code) {
        $query['lang'] = $code;
        $href = $base . '?' . http_build_query($query);
        $label = $code === 'ru' ? 'Русский' : 'English';
        $active = $code === $current;

        if ($active) {
            echo '<span class="lang-active" aria-current="true">' . h($label) . '</span>';
        } else {
            echo '<a href="' . h($href) . '" class="lang-link" hreflang="' . h($code) . '">' . h($label) . '</a>';
        }
        if ($code !== PANEL_SUPPORTED_LANGS[array_key_last(PANEL_SUPPORTED_LANGS)]) {
            echo ' <span class="lang-sep">|</span> ';
        }
    }
}
