#!/usr/bin/env php
<?php
/**
 * PROMPT-72 synthetic log-tail benchmark — superseded by PROMPT-79.1.
 *
 * Legacy shadow log parsing was removed from relationship_status.php.
 * Reintroduce a bench harness in PROMPT-79.2 when live observability returns.
 */
declare(strict_types=1);

fwrite(STDERR, "scale_log_coverage_bench: skipped (PROMPT-79.1 — observability stub)\n");
exit(0);
