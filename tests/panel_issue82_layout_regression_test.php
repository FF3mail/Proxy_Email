#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Issue #82 follow-up: layout behavior, not class-name presence.
 * The daemon-log action must stay outside the scrolling issue list.
 * Collapsed and narrow sidebars must show the logo mark, not a clipped name.
 * The expanded sidebar must show the wordmarks only.
 * At 1366px the dashboard must not keep three columns of non-wrapping rows.
 */
$failures = 0;
$root = dirname(__DIR__);

function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $failures++;
        return;
    }
    echo "OK: {$msg}\n";
}

/**
 * Walk a PHP template with the PHP removed and return elements of interest,
 * including the class lists of their ancestors (nearest first).
 *
 * @return array{log: list<array<string, mixed>>, steps: list<array<string, mixed>>, brand: list<array<string, mixed>>}
 */
function interestingTemplateNodes(string $php): array
{
    $html = preg_replace('/<\?(?:php|=)[\s\S]*?\?>/', '', $php) ?? $php;
    preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>/', $html, $matches, PREG_SET_ORDER);
    $void = array_flip(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);
    $stack = [];
    $found = ['log' => [], 'steps' => [], 'brand' => []];
    foreach ($matches as $m) {
        $full = $m[0];
        $name = strtolower($m[1]);
        $attrs = $m[2];
        if (str_starts_with($full, '</')) {
            while ($stack !== []) {
                $top = array_pop($stack);
                if ($top['name'] === $name) {
                    break;
                }
            }
            continue;
        }
        $classes = [];
        if (preg_match('/\bclass\s*=\s*"([^"]*)"/', $attrs, $cm)) {
            $classes = preg_split('/\s+/', trim($cm[1])) ?: [];
        }
        $href = '';
        if (preg_match('/\bhref\s*=\s*"([^"]*)"/', $attrs, $hm)) {
            $href = html_entity_decode($hm[1], ENT_QUOTES);
        }
        $aria = '';
        if (preg_match('/\baria-label\s*=\s*"([^"]*)"/', $attrs, $am)) {
            $aria = $am[1];
        }
        $near = [];
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $near[] = $stack[$i]['classes'];
        }
        $node = [
            'name' => $name,
            'classes' => $classes,
            'href' => $href,
            'aria' => $aria,
            'ancestors' => $near,
            'parentClasses' => $stack === [] ? [] : $stack[count($stack) - 1]['classes'],
        ];
        if (str_contains($href, 'logs.php?source=daemon')) {
            $found['log'][] = $node;
        }
        if ($name === 'ul' && in_array('pm-steps', $classes, true)) {
            $found['steps'][] = $node;
        }
        $underBrand = false;
        foreach ($stack as $open) {
            if (in_array('pm-brand-link', $open['classes'], true)) {
                $underBrand = true;
                break;
            }
        }
        if ($name === 'a' && in_array('pm-brand-link', $classes, true)) {
            $found['brand'][] = $node + ['wordmarks' => 0, 'fallback' => 0, 'mark' => 0];
        } elseif ($underBrand && $found['brand'] !== []) {
            $idx = count($found['brand']) - 1;
            if (in_array('pm-brand-wordmarks', $classes, true)) {
                $found['brand'][$idx]['wordmarks']++;
            }
            if (in_array('pm-brand-fallback', $classes, true)) {
                $found['brand'][$idx]['fallback']++;
            }
            if ($name === 'img' && in_array('pm-brand-mark', $classes, true)) {
                $found['brand'][$idx]['mark']++;
            }
        }
        $selfClose = str_ends_with(rtrim($full), '/>') || isset($void[$name]);
        if (!$selfClose) {
            $stack[] = $node;
        }
    }

    return $found;
}

function ancestorsInclude(array $node, string $className): bool
{
    foreach ($node['ancestors'] as $classes) {
        if (in_array($className, $classes, true)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<array{media: ?string, selectors: list<string>, decls: array<string, string>}>
 */
function cssRules(string $css): array
{
    $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;
    $rules = [];
    $len = strlen($css);
    $i = 0;
    $walk = function (int &$i, ?string $media) use (&$walk, &$rules, $css, $len): void {
        while ($i < $len) {
            while ($i < $len && ctype_space($css[$i])) {
                $i++;
            }
            if ($i >= $len) {
                return;
            }
            if ($css[$i] === '}') {
                $i++;
                return;
            }
            if ($css[$i] === '@') {
                $start = $i;
                while ($i < $len && $css[$i] !== '{') {
                    $i++;
                }
                $header = trim(substr($css, $start, $i - $start));
                $i++;
                if (str_starts_with($header, '@media')) {
                    $walk($i, trim(substr($header, strlen('@media'))));
                } else {
                    $depth = 1;
                    while ($i < $len && $depth > 0) {
                        if ($css[$i] === '{') {
                            $depth++;
                        } elseif ($css[$i] === '}') {
                            $depth--;
                        }
                        $i++;
                    }
                }
                continue;
            }
            $selStart = $i;
            while ($i < $len && $css[$i] !== '{') {
                $i++;
            }
            $selector = trim(substr($css, $selStart, $i - $selStart));
            $i++;
            $bodyStart = $i;
            $depth = 1;
            while ($i < $len && $depth > 0) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}') {
                    $depth--;
                }
                if ($depth > 0) {
                    $i++;
                }
            }
            $body = substr($css, $bodyStart, $i - $bodyStart);
            if ($i < $len && $css[$i] === '}') {
                $i++;
            }
            $decls = [];
            foreach (explode(';', $body) as $part) {
                $part = trim($part);
                if ($part === '' || !str_contains($part, ':')) {
                    continue;
                }
                [$prop, $value] = explode(':', $part, 2);
                $decls[strtolower(trim($prop))] = trim(str_ireplace('!important', '', $value));
            }
            $selectors = array_values(array_filter(array_map('trim', explode(',', $selector)), static fn(string $s): bool => $s !== ''));
            $rules[] = ['media' => $media, 'selectors' => $selectors, 'decls' => $decls];
        }
    };
    $walk($i, null);

    return $rules;
}

function mediaMatches(?string $query, int $width, int $height): bool
{
    if ($query === null || trim($query) === '') {
        return true;
    }
    if (!preg_match_all('/\((min|max)-(width|height)\s*:\s*(\d+)px\)/', $query, $parts, PREG_SET_ORDER)) {
        return false;
    }
    foreach ($parts as $part) {
        $limit = (int) $part[3];
        $axis = $part[2] === 'width' ? $width : $height;
        if ($part[1] === 'min' && $axis < $limit) {
            return false;
        }
        if ($part[1] === 'max' && $axis > $limit) {
            return false;
        }
    }

    return true;
}

function compoundMatches(string $compound, array $classes): bool
{
    $compound = trim($compound);
    if ($compound === '' || !str_contains($compound, '.')) {
        return false;
    }
    if (!str_starts_with($compound, '.')) {
        $compound = substr($compound, (int) strpos($compound, '.'));
    }
    if (str_contains($compound, '[') || str_contains($compound, '>')) {
        return false;
    }
    $need = array_values(array_filter(explode('.', $compound), static fn(string $c): bool => $c !== ''));
    foreach ($need as $className) {
        if (!in_array($className, $classes, true)) {
            return false;
        }
    }

    return $need !== [];
}

function selectorMatches(string $selector, array $elClasses, array $ancestorsNearFirst): bool
{
    $selector = preg_replace('/::[a-zA-Z0-9_-]+/', '', $selector) ?? $selector;
    $selector = preg_replace('/:[a-zA-Z0-9_-]+(\([^)]*\))?/', '', $selector) ?? $selector;
    $parts = preg_split('/\s+/', trim($selector), -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === false || $parts === []) {
        return false;
    }
    $last = array_pop($parts);
    if (!compoundMatches($last, $elClasses)) {
        return false;
    }
    $ai = 0;
    for ($p = count($parts) - 1; $p >= 0; $p--) {
        $found = false;
        while ($ai < count($ancestorsNearFirst)) {
            $candidate = $ancestorsNearFirst[$ai];
            $ai++;
            if (compoundMatches($parts[$p], $candidate)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return false;
        }
    }

    return true;
}

/**
 * @param list<array{media: ?string, selectors: list<string>, decls: array<string, string>}> $rules
 * @param list<string> $elClasses
 * @param list<list<string>> $ancestorsNearFirst
 * @return array<string, string>
 */
function computedStyle(array $rules, array $elClasses, array $ancestorsNearFirst, int $width, int $height): array
{
    $props = [];
    foreach ($rules as $rule) {
        if (!mediaMatches($rule['media'], $width, $height)) {
            continue;
        }
        foreach ($rule['selectors'] as $selector) {
            if (!selectorMatches($selector, $elClasses, $ancestorsNearFirst)) {
                continue;
            }
            foreach ($rule['decls'] as $prop => $value) {
                $props[$prop] = $value;
            }
            break;
        }
    }

    return $props;
}

$dashNodes = interestingTemplateNodes((string) file_get_contents($root . '/web/includes/dashboard_ui.php'));
assert_true(count($dashNodes['log']) === 1, 'one daemon-log action in the dashboard template');
$logLink = $dashNodes['log'][0] ?? null;
assert_true(is_array($logLink), 'daemon-log action was parsed');
if (is_array($logLink)) {
    assert_true(in_array('pm-dash-issues-link', $logLink['parentClasses'], true), 'daemon-log action sits in pm-dash-issues-link');
    assert_true(!ancestorsInclude($logLink, 'pm-dash-issues-scroll'), 'daemon-log action is outside the scrolling issue list');
    assert_true(ancestorsInclude($logLink, 'pm-dash-host'), 'daemon-log action stays in the Host card');
}
$stepsInsideScroll = 0;
foreach ($dashNodes['steps'] as $steps) {
    if (ancestorsInclude($steps, 'pm-dash-issues-scroll')) {
        $stepsInsideScroll++;
    }
}
assert_true($stepsInsideScroll === 1, 'issue rows still scroll inside pm-dash-issues-scroll');

$css = (string) file_get_contents($root . '/web/assets/panel-modal.css');
$rules = cssRules($css);
$scrollStyle = computedStyle($rules, ['pm-dash-issues-scroll'], [], 1920, 1080);
assert_true(($scrollStyle['max-height'] ?? '') === '72px', 'issue list keeps a 72px cap');
assert_true(($scrollStyle['overflow-y'] ?? '') === 'auto', 'issue list still scrolls instead of growing the page');

$brandAncestors = [
    ['pm-brand-link'],
    ['pm-brand'],
    ['pm-side'],
];
$collapsedAncestors = [
    ['pm-brand-link'],
    ['pm-brand'],
    ['pm-side', 'collapsed'],
];
$fallbackClasses = ['pm-brand-text', 'pm-brand-fallback'];
$wordmarkClasses = ['pm-brand-wordmarks'];
$markClasses = ['pm-brand-mark'];

$expandedFallback = computedStyle($rules, $fallbackClasses, $brandAncestors, 1920, 1080);
$expandedMarks = computedStyle($rules, $wordmarkClasses, $brandAncestors, 1920, 1080);
$expandedMark = computedStyle($rules, $markClasses, $brandAncestors, 1920, 1080);
assert_true(($expandedFallback['display'] ?? '') === 'none', 'expanded sidebar hides the text fallback');
assert_true(($expandedMarks['display'] ?? '') === 'flex', 'expanded sidebar shows the wordmarks');
assert_true(($expandedMark['display'] ?? '') === 'none', 'expanded sidebar hides the compact mark');

$collapsedFallback = computedStyle($rules, $fallbackClasses, $collapsedAncestors, 1920, 1080);
$collapsedMarks = computedStyle($rules, $wordmarkClasses, $collapsedAncestors, 1920, 1080);
$collapsedMark = computedStyle($rules, $markClasses, $collapsedAncestors, 1920, 1080);
$collapsedLink = computedStyle($rules, ['pm-brand-link'], [['pm-brand'], ['pm-side', 'collapsed']], 1920, 1080);
assert_true(($collapsedFallback['display'] ?? '') === 'none', 'collapsed sidebar does not clip the app name into a text box');
assert_true(($collapsedFallback['width'] ?? '') !== '22px', 'collapsed brand is not the 22px clipped-name box');
assert_true(($collapsedMarks['display'] ?? '') === 'none', 'collapsed sidebar hides the wordmarks');
assert_true(($collapsedMark['display'] ?? '') === 'block', 'collapsed sidebar shows the compact mark');
assert_true(($collapsedMark['object-fit'] ?? '') === 'contain', 'compact mark keeps its aspect ratio');
assert_true(($collapsedMark['width'] ?? '') === '24px' && ($collapsedMark['height'] ?? '') === '19px', 'compact mark has a designed 24×19 box');
assert_true(($collapsedMark['overflow'] ?? '') !== 'hidden', 'compact mark is not clipped by its own box');
assert_true(($collapsedLink['width'] ?? '') !== '22px' && ($collapsedLink['overflow'] ?? '') === 'visible', 'collapsed brand link does not clip its contents');

$narrowFallback = computedStyle($rules, $fallbackClasses, $brandAncestors, 720, 900);
$narrowMarks = computedStyle($rules, $wordmarkClasses, $brandAncestors, 720, 900);
$narrowMark = computedStyle($rules, $markClasses, $brandAncestors, 720, 900);
$narrowLink = computedStyle($rules, ['pm-brand-link'], [['pm-brand'], ['pm-side']], 720, 900);
assert_true(($narrowFallback['display'] ?? '') === 'none', 'narrow sidebar does not clip the app name into a text box');
assert_true(($narrowFallback['width'] ?? '') !== '22px', 'narrow brand is not the 22px clipped-name box');
assert_true(($narrowMarks['display'] ?? '') === 'none', 'narrow sidebar hides the wordmarks');
assert_true(($narrowMark['display'] ?? '') === 'block', 'narrow sidebar shows the compact mark');
assert_true(($narrowMark['object-fit'] ?? '') === 'contain', 'narrow compact mark keeps its aspect ratio');
assert_true(($narrowLink['width'] ?? '') !== '22px' && ($narrowLink['overflow'] ?? '') === 'visible', 'narrow brand link does not clip its contents');
assert_true(
    ($expandedFallback['display'] ?? '') === 'none'
        && ($expandedMark['display'] ?? '') === 'none'
        && ($expandedMarks['display'] ?? '') !== 'none',
    'expanded sidebar shows the wordmarks only'
);

$grid1920 = computedStyle($rules, ['pm-dash-grid-main'], [], 1920, 1080);
$grid1366 = computedStyle($rules, ['pm-dash-grid-main'], [], 1366, 768);
$grid1000 = computedStyle($rules, ['pm-dash-grid-main'], [], 1000, 800);
assert_true(str_contains($grid1920['grid-template-columns'] ?? '', 'repeat(3,'), '1920 dashboard keeps three columns');
assert_true(str_contains($grid1366['grid-template-columns'] ?? '', 'repeat(2,'), '1366 dashboard uses two columns');
assert_true(($grid1000['grid-template-columns'] ?? '') === '1fr', 'narrow dashboard stacks to one column');

$svcItem = computedStyle($rules, ['pm-svc-item'], [], 1366, 768);
$svcLabel = computedStyle($rules, ['pm-svc-label'], [['pm-svc-item'], ['pm-svc-grid']], 1366, 768);
assert_true(($svcItem['flex-wrap'] ?? '') === 'wrap', 'service rows wrap instead of overflowing');
assert_true(($svcLabel['font-size'] ?? '') === '13px', 'service labels stay at readable 13px');
assert_true(!isset($svcLabel['text-overflow']), 'service labels are not ellipsized');
assert_true(($svcLabel['overflow-wrap'] ?? '') === 'break-word', 'long service labels can break instead of painting outside the card');

$nav = (string) file_get_contents($root . '/web/includes/panel_nav.php');
$navNodes = interestingTemplateNodes($nav);
assert_true(count($navNodes['brand']) === 1, 'sidebar brand link exists');
$brandLink = $navNodes['brand'][0] ?? null;
if (is_array($brandLink)) {
    assert_true(
        str_contains($nav, 'aria-label="<?= h(__(\'app.name\')) ?>"'),
        'brand link accessible name is the application name'
    );
    assert_true($brandLink['wordmarks'] === 1, 'wordmarks remain in the brand link');
    assert_true($brandLink['fallback'] === 1, 'text fallback remains in the brand link');
    assert_true($brandLink['mark'] === 1, 'brand link has one dedicated compact mark');
    $markAt = strpos($nav, '<img class="pm-brand-mark"');
    $markTag = $markAt === false ? '' : substr($nav, $markAt, 180);
    assert_true(
        str_contains($markTag, "panelBrandUrl('logo-mark.png')"),
        'compact mark uses the existing logo-mark asset'
    );
}

$auth = (string) file_get_contents($root . '/web/includes/panel_auth_ui.php');
$loginStart = strpos($auth, 'function renderLoginForm');
$loginEnd = strpos($auth, 'function handleLoginSubmit');
assert_true($loginStart !== false && $loginEnd !== false && $loginEnd > $loginStart, 'login form function is present');
if ($loginStart !== false && $loginEnd !== false) {
    $loginFn = substr($auth, $loginStart, $loginEnd - $loginStart);
    assert_true(!str_contains($loginFn, '<h2'), 'login form has no heading element');
    assert_true(str_contains($loginFn, 'name="username"') && str_contains($loginFn, 'name="password"'), 'login fields remain');
}

$removedHeadingSelector = '.login-card' . ' h2';
$scanRoots = [$root . '/web', $root . '/tests', $root . '/docs'];
$headingHits = [];
foreach ($scanRoots as $scanRoot) {
    if (!is_dir($scanRoot)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (str_ends_with($path, 'panel_issue82_layout_regression_test.php')) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['php', 'css', 'js', 'html', 'md'], true)) {
            continue;
        }
        $text = (string) file_get_contents($path);
        if (str_contains($text, $removedHeadingSelector)) {
            $headingHits[] = substr($path, strlen($root) + 1);
        }
    }
}
assert_true($headingHits === [], 'nothing still depends on the login-card heading rule' . ($headingHits === [] ? '' : ': ' . implode(', ', $headingHits)));

if ($failures > 0) {
    exit(1);
}
echo "OK: issue 82 layout regression tests passed\n";
