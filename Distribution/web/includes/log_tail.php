<?php
declare(strict_types=1);

/**
 * Read last N lines from a file without loading the entire file.
 *
 * @return string[]
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

    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);

    while ($pos > 0 && $found < $lines) {
        $chunkSize = min(4096, $pos);
        $pos      -= $chunkSize;
        fseek($fp, $pos);
        $chunk  = fread($fp, $chunkSize);
        $buffer = $chunk . $buffer;

        $parts = explode("\n", $buffer);
        $buffer = array_shift($parts);

        foreach (array_reverse($parts) as $line) {
            if ($found >= $lines) {
                break;
            }
            $result[] = $line;
            $found++;
        }
    }

    if ($buffer !== '' && $found < $lines) {
        $result[] = $buffer;
    }

    fclose($fp);

    return array_reverse($result);
}
