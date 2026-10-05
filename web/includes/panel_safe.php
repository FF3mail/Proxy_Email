<?php
declare(strict_types=1);

/**
 * Fail-soft helpers for panel blocks (dashboard and related UI).
 */

function panelSafeLogThrowable(Throwable $e, string $context): void
{
    error_log(sprintf(
        'panel block %s: %s at %s:%d',
        $context,
        $e::class,
        $e->getFile(),
        $e->getLine()
    ));
}

/**
 * @template T
 * @param callable(): T $fn
 * @return T|null
 */
function panelSafeBlock(callable $fn, string $context, mixed $fallback = null): mixed
{
    try {
        return $fn();
    } catch (Throwable $e) {
        panelSafeLogThrowable($e, $context);
        return $fallback;
    }
}

/**
 * Render a dashboard subsection; on failure show localized n/a hint.
 *
 * @param callable(): void $render
 */
function panelSafeRenderBlock(string $context, callable $render): void
{
    try {
        $render();
    } catch (Throwable $e) {
        panelSafeLogThrowable($e, $context);
        echo '<p class="pm-hint">' . h(__('dashboard.block_unavailable')) . '</p>';
    }
}

function panelSafeNa(string $value): string
{
    return $value !== '' ? $value : __('dashboard.block_unavailable');
}
