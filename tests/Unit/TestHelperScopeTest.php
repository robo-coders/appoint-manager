<?php

$phpFilesUnder = function (string $directory): array {
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
};

/** @return array{declared: list<string>, called: list<string>} */
$scan = function (string $path): array {
    $tokens = array_values(array_filter(
        token_get_all(file_get_contents($path)),
        fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $declared = [];
    $called = [];

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $before = $tokens[$i - 1] ?? null;
        $after = $tokens[$i + 1] ?? null;

        if (is_array($before) && $before[0] === T_FUNCTION) {
            $declared[] = $token[1];

            continue;
        }

        if ($after === '(' && ! in_array($before, ['->', '::'], true)
            && ! (is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION], true))) {
            $called[] = $token[1];
        }
    }

    return ['declared' => array_values(array_unique($declared)), 'called' => array_values(array_unique($called))];
};

$root = dirname(__DIR__);
$testFiles = array_merge($phpFilesUnder($root.'/Feature'), $phpFilesUnder($root.'/Unit'));

$declaredIn = [];
$scans = [];

foreach ($testFiles as $file) {
    $scans[$file] = $scan($file);

    foreach ($scans[$file]['declared'] as $name) {
        $declaredIn[$name][] = $file;
    }
}

$shared = $scan($root.'/Pest.php')['declared'];

it('declares every cross-file test helper in tests/Pest.php', function () use ($scans, $declaredIn, $shared, $root) {
    $strays = [];

    foreach ($scans as $file => $scanned) {
        foreach ($scanned['called'] as $name) {
            if (in_array($name, $shared, true) || in_array($name, $scanned['declared'], true)) {
                continue;
            }

            if (! isset($declaredIn[$name])) {
                continue;
            }

            $owner = str_replace($root.'/', '', $declaredIn[$name][0]);
            $borrower = str_replace($root.'/', '', $file);

            $strays[] = "{$name}() is declared in {$owner} and called from {$borrower}";
        }
    }

    expect($strays)->toBe([], "these helpers are fatal under --parallel; move them to tests/Pest.php:\n  ".implode("\n  ", $strays));
});

it('never declares the same test helper in two files', function () use ($declaredIn, $shared, $root) {
    $collisions = [];

    foreach ($declaredIn as $name => $files) {
        $all = in_array($name, $shared, true) ? [...$files, $root.'/Pest.php'] : $files;

        if (count($all) > 1) {
            $collisions[] = $name.'(): '.implode(', ', array_map(fn ($f) => str_replace($root.'/', '', $f), $all));
        }
    }

    expect($collisions)->toBe([], "a redeclared helper is a fatal, not a failure:\n  ".implode("\n  ", $collisions));
});
