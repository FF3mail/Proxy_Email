<?php
declare(strict_types=1);

/**
 * Default panel web root for CLI tests: repository `web/` unless PANEL_WEB_ROOT is set.
 */
function panel_test_repo_root(): string
{
    return dirname(__DIR__);
}

function panel_test_web_root(): string
{
    $override = getenv('PANEL_WEB_ROOT');
    if ($override !== false && $override !== '') {
        return $override;
    }

    return panel_test_repo_root() . '/web';
}
