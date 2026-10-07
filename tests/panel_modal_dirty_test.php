<?php
declare(strict_types=1);

/**
 * Static checks for panel modal dirty-state logic (PROMPT-84).
 */
$js = (string) file_get_contents(dirname(__DIR__) . '/web/assets/panel-modal.js');

function assert_contains(string $haystack, string $needle, string $msg): void
{
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

assert_contains($js, '_serializeForm', 'form snapshot serializer');
assert_contains($js, 'captureFormBaseline', 'baseline capture on open');
assert_contains($js, 'isDialogFormDirty', 'dirty compare uses baseline');
assert_contains($js, 'requestAnimationFrame', 'baseline after focus/layout');
assert_contains($js, "addEventListener('input'", 'must not mark dirty on every input event', false);
if (str_contains($js, "addEventListener('input'")) {
    fwrite(STDERR, "FAIL: input listener should not set dirty flag globally\n");
    exit(1);
}

echo "OK: panel modal dirty checks passed\n";
