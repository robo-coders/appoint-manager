<?php

/**
 * The rule that keeps `pest --parallel` from being a different suite.
 *
 * A plain `function` in a test file is defined only once that file has been
 * loaded. Run serially, every file is loaded eventually and in a stable order,
 * so a helper borrowed across files works by accident. Run in parallel, the two
 * files land in different workers and the borrower dies with `Call to undefined
 * function` — a fatal, not a failure, so it takes the worker with it. That is
 * how this suite came to be green and fatal at the same time on the same code.
 *
 * Two rules, both checked here:
 *
 *   1. A helper called from another test file must be declared in tests/Pest.php,
 *      which every worker loads before any test file.
 *   2. No two files may declare the same helper name. A redeclaration is a hard
 *      fatal in whichever worker happens to load both, and it is invisible in
 *      the workers that load one.
 *
 * Read with the tokenizer rather than a regex, because prose is not code: three
 * of the eight matches a naive grep finds in this suite are helper names inside
 * docblocks explaining this exact problem.
 */
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

/**
 * @return array{declared: list<string>, called: list<string>}
 */
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

        // `function name` — a declaration. Anonymous functions have `(` next
        // and never reach here.
        if (is_array($before) && $before[0] === T_FUNCTION) {
            $declared[] = $token[1];

            continue;
        }

        // `name(` — a call, unless it is a method or a class constant reference.
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
                continue; // A framework or PHP function, not a test helper.
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
