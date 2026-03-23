<?php

/**
 * CLI tool to verify that version numbers are consistent across the project.
 *
 * Usage:
 *   php tools/check-versions.php
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);

$pluginHeader = file_get_contents($rootDir . '/plugin.php');
preg_match('/^\s*\*\s*Version:\s*(.+)$/m', $pluginHeader, $matches);
$pluginVersion = trim($matches[1] ?? '');

$readme = file_get_contents($rootDir . '/readme.txt');
preg_match('/^Stable tag:\s*(.+)$/m', $readme, $matches);
$readmeVersion = trim($matches[1] ?? '');

$composerJson = json_decode(file_get_contents($rootDir . '/composer.json'), true);
$composerVersion = $composerJson['version'] ?? '';

$providerFile = file_get_contents($rootDir . '/src/Provider/AiGatewayProvider.php');
preg_match("/const\s+VERSION\s*=\s*'([^']+)'/", $providerFile, $matches);
$constantVersion = $matches[1] ?? '';

$versions = [
    'composer.json (version)'                  => $composerVersion,
    'plugin.php (Version header)'              => $pluginVersion,
    'readme.txt (Stable tag)'                  => $readmeVersion,
    'AiGatewayProvider.php (VERSION constant)' => $constantVersion,
];

$unique = array_unique(array_values($versions));

if (count($unique) === 1) {
    echo "All versions match: {$unique[0]}" . PHP_EOL;
    exit(0);
}

fwrite(STDERR, "Version mismatch detected:" . PHP_EOL);
foreach ($versions as $source => $version) {
    fwrite(STDERR, "  {$source}: {$version}" . PHP_EOL);
}
exit(1);
