<?php

declare(strict_types=1);

/**
 * MacroRisk project dump generator.
 *
 * Specification source of truth:
 * MacroRisk 1.7.1-FINAL
 *
 * Purpose:
 * - Generate compact but complete project_dump.txt.
 * - Exclude heavy/noisy files.
 * - Avoid dumping secrets from vendor/composer.lock.
 * - Include project source, schema, config, public entrypoint, CLI, setup scripts.
 *
 * Usage:
 * - Open /tree.php in browser, or run: php tree.php
 */

set_time_limit(0);
ini_set('memory_limit', '-1');

$root = realpath(__DIR__);

if ($root === false) {
    exit("Cannot resolve project root.\n");
}

$outputFile = $root . DIRECTORY_SEPARATOR . 'project_dump.txt';

$excludedDirs = [
    '.git',
    '.idea',
    '.vscode',
    'node_modules',
    'vendor',
    'storage/logs',
    'storage/cache',
    'storage/exports',
    'storage/locks',
];

$excludedFiles = [
    'composer.lock',
    'project_dump.txt',
    'project_dump2.txt',
];

$excludedPathParts = [
    DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeds' . DIRECTORY_SEPARATOR,
];

$includedExtensions = [
    'php',
    'sql',
    'json',
    'env',
    'example',
    'md',
    'txt',
    'htaccess',
];

$alwaysIncludeNames = [
    '.env.example',
    '.htaccess',
    'composer.json',
    'phinx.php',
    'setup.php',
    'install-schema.php',
    'tree.php',
];

$maxFileSizeBytes = 250000;

$out = fopen($outputFile, 'wb');

if ($out === false) {
    exit("Cannot create output file.\n");
}

fwrite($out, "PROJECT ROOT\n");
fwrite($out, $root . PHP_EOL);
fwrite($out, str_repeat("=", 120) . PHP_EOL);
fwrite($out, "GENERATED AT UTC: " . gmdate('Y-m-d H:i:s') . PHP_EOL);
fwrite($out, "MACRORISK SPECIFICATION: 1.7.1-FINAL" . PHP_EOL);
fwrite($out, str_repeat("=", 120) . PHP_EOL . PHP_EOL);

$files = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS
        ),
        function (SplFileInfo $current) use ($root, $excludedDirs): bool {
            if (!$current->isDir()) {
                return true;
            }

            $path = $current->getRealPath();

            if ($path === false) {
                return false;
            }

            $relativePath = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
            $relativePath = str_replace('\\', '/', $relativePath);

            foreach ($excludedDirs as $excludedDir) {
                if ($relativePath === $excludedDir || str_starts_with($relativePath, $excludedDir . '/')) {
                    return false;
                }
            }

            return true;
        }
    ),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    $path = $file->getRealPath();

    if ($path === false) {
        continue;
    }

    if ($path === $outputFile) {
        continue;
    }

    $fileName = $file->getFilename();

    if (in_array($fileName, $excludedFiles, true)) {
        continue;
    }

    $skipByPath = false;

    foreach ($excludedPathParts as $excludedPathPart) {
        if (str_contains($path, $excludedPathPart)) {
            $skipByPath = true;
            break;
        }
    }

    if ($skipByPath) {
        continue;
    }

    if ($file->getSize() > $maxFileSizeBytes) {
        continue;
    }

    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $include = in_array($fileName, $alwaysIncludeNames, true)
        || in_array($extension, $includedExtensions, true)
        || str_starts_with($fileName, '.htaccess');

    if (!$include) {
        continue;
    }

    $relativePath = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
    $relativePath = str_replace('\\', '/', $relativePath);

    $files[$relativePath] = $path;
}

ksort($files, SORT_STRING);

fwrite($out, "FILE INDEX\n");
fwrite($out, str_repeat("-", 120) . PHP_EOL);

foreach ($files as $relativePath => $path) {
    fwrite($out, $relativePath . PHP_EOL);
}

fwrite($out, PHP_EOL . str_repeat("=", 120) . PHP_EOL . PHP_EOL);

foreach ($files as $relativePath => $path) {
    $contents = @file_get_contents($path);

    if ($contents === false) {
        continue;
    }

    if (str_contains($contents, "\0")) {
        continue;
    }

    $safeContents = normalizeSecretsForDump($contents, $relativePath);

    fwrite($out, str_repeat("=", 120) . PHP_EOL);
    fwrite($out, "FULL PATH : " . $path . PHP_EOL);
    fwrite($out, "REL PATH  : " . $relativePath . PHP_EOL);
    fwrite($out, "FILE NAME : " . basename($path) . PHP_EOL);
    fwrite($out, "SIZE      : " . number_format(strlen($contents)) . " bytes" . PHP_EOL);
    fwrite($out, str_repeat("-", 120) . PHP_EOL);
    fwrite($out, $safeContents);

    if (!str_ends_with($safeContents, PHP_EOL)) {
        fwrite($out, PHP_EOL);
    }

    fwrite($out, PHP_EOL);
}

fclose($out);

$message = "Done.\nOutput: {$outputFile}\nFiles dumped: " . count($files) . "\n";

if (PHP_SAPI === 'cli') {
    echo $message;
} else {
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
}

function normalizeSecretsForDump(string $contents, string $relativePath): string
{
    $contents = preg_replace(
        "/('password'\\s*=>\\s*)'[^']*'/",
        "$1'***REDACTED***'",
        $contents
    ) ?? $contents;

    $contents = preg_replace(
        '/("password"\\s*:\\s*)"[^"]*"/',
        '$1"***REDACTED***"',
        $contents
    ) ?? $contents;

    $contents = preg_replace(
        '/(MACRORISK_DB_PASSWORD=).*/',
        '$1***REDACTED***',
        $contents
    ) ?? $contents;

    $contents = preg_replace(
        "/('pass'\\s*=>\\s*)'[^']*'/",
        "$1'***REDACTED***'",
        $contents
    ) ?? $contents;

    $contents = preg_replace(
        '/(Bearer\\s+)[A-Za-z0-9._\\-]+/',
        '$1***REDACTED***',
        $contents
    ) ?? $contents;

    if (str_contains($relativePath, 'config/database.php') || str_contains($relativePath, 'phinx.php')) {
        $contents = preg_replace(
            "/('username'\\s*=>\\s*)'[^']*'/",
            "$1'***REDACTED***'",
            $contents
        ) ?? $contents;

        $contents = preg_replace(
            "/('user'\\s*=>\\s*)'[^']*'/",
            "$1'***REDACTED***'",
            $contents
        ) ?? $contents;
    }

    return $contents;
}