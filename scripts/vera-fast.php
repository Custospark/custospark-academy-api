#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Vera Fast for Custospark Academy backend.
 * - php -l on changed .php files (staged + unstaged vs HEAD, fallback: all app/ + tests/)
 * - logic gates: file size <= 500 lines, no em/en dashes
 */

$root = dirname(__DIR__);
chdir($root);

function run(string $cmd): array
{
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}

// 1) changed files
[$code, $changed] = run('git diff --name-only HEAD 2>/dev/null; git diff --cached --name-only HEAD 2>/dev/null');
$changed = preg_split('/\r?\n/', $changed, -1, PREG_SPLIT_NO_EMPTY) ?: [];

$phpFiles = array_values(array_filter($changed, fn ($f) => str_ends_with($f, '.php') && is_file($f)));
if ($phpFiles === []) {
    // fallback: all app/ + tests/ (fresh repo without commits)
    $phpFiles = array_merge(
        glob('app/**/*.php') ?: [],
        glob('database/**/*.php') ?: [],
        glob('routes/*.php') ?: [],
        glob('tests/**/*.php') ?: [],
        glob('config/*.php') ?: [],
        glob('bootstrap/*.php') ?: [],
    );
}

// 2) php -l
$lintErrors = [];
foreach ($phpFiles as $file) {
    [$c, $out] = run(PHP_BINARY.' -l '.escapeshellarg($file));
    if ($c !== 0) {
        $lintErrors[] = $out;
    }
}

// 3) logic: file size
$sizeErrors = [];
foreach ($phpFiles as $file) {
    $lines = count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    if ($lines > 500) {
        $sizeErrors[] = "[file-size-500] {$file} has {$lines} lines (max 500)";
    }
}

// 4) logic: no long dashes
$dashErrors = [];
foreach ($phpFiles as $file) {
    $content = file_get_contents($file);
    if (preg_match('/[\xE2\x80\x93\xE2\x80\x94]/u', $content)) {
        $dashErrors[] = "[no-long-dashes] {$file} - use a plain hyphen instead";
    }
}

$fail = false;
foreach ($lintErrors as $e) {
    echo $e, "\n";
    $fail = true;
}
foreach ($sizeErrors as $e) {
    echo $e, "\n";
    $fail = true;
}
foreach ($dashErrors as $e) {
    echo $e, "\n";
    $fail = true;
}

echo $fail
    ? "\n❌ Vera fast: FAILED\n"
    : "\n✅ Vera fast: passed (php -l ".count($phpFiles)." file(s) + logic)\n";

exit($fail ? 1 : 0);