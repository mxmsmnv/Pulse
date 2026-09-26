<?php

declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/src/PulseSubmissions.php');

if(preg_match('/SUM\s*\(\s*(?!CASE\b)[^)]*(?:\s=\s|\sIS\s+(?:NOT\s+)?NULL)/i', $source)) {
    fwrite(STDERR, "Pulse submissions still sums a boolean SQL expression.\n");
    exit(1);
}

if(strpos($source, 'SUM(CASE WHEN complete=1 THEN 1 ELSE 0 END) AS completed') === false) {
    fwrite(STDERR, "Portable completed-submission aggregate is missing.\n");
    exit(1);
}

foreach(['Pulse.module.php', 'ProcessPulse.module.php', 'TextformatterPulse.module.php'] as $moduleFile) {
    $module = (string) file_get_contents(dirname(__DIR__) . '/' . $moduleFile);
    if(!preg_match("/'version'\\s*=>\\s*106/", $module)) {
        fwrite(STDERR, "{$moduleFile} version is not 1.0.6.\n");
        exit(1);
    }
}

echo "OK: Pulse database aggregates are portable.\n";
