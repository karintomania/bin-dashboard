<?php

declare(strict_types=1);

require __DIR__ . '/../public/functions.php';

$failures = 0;

function check(string $name, mixed $expected, mixed $actual): void
{
    global $failures;

    if ($expected === $actual) {
        echo "PASS: {$name}\n";
        return;
    }

    $failures++;
    echo "FAIL: {$name}\n";
    echo '  expected: ' . var_export($expected, true) . "\n";
    echo '  actual:   ' . var_export($actual, true) . "\n";
}

function temp_cache_dir(): string
{
    $dir = sys_get_temp_dir() . '/bin-cache-test-' . uniqid();
    mkdir($dir);

    return $dir;
}

function remove_dir(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($dir);
}

// --- is_cache_fresh ---

// missing file
$dir = temp_cache_dir();
check('is_cache_fresh: missing file is not fresh', false, is_cache_fresh($dir . '/cache.json'));
remove_dir($dir);

// created_at 1 hour ago
$dir = temp_cache_dir();
$path = $dir . '/cache.json';
$now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
file_put_contents($path, json_encode([
    'created_at' => $now->modify('-1 hour')->format(DateTimeInterface::ATOM),
    'response' => [],
]));
check('is_cache_fresh: 1 hour ago is fresh', true, is_cache_fresh($path, CACHE_TTL_SECONDS, $now));
remove_dir($dir);

// created_at 7 hours ago
$dir = temp_cache_dir();
$path = $dir . '/cache.json';
$now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
file_put_contents($path, json_encode([
    'created_at' => $now->modify('-7 hours')->format(DateTimeInterface::ATOM),
    'response' => [],
]));
check('is_cache_fresh: 7 hours ago is stale', false, is_cache_fresh($path, CACHE_TTL_SECONDS, $now));
remove_dir($dir);

// malformed JSON
$dir = temp_cache_dir();
$path = $dir . '/cache.json';
file_put_contents($path, 'not json');
check('is_cache_fresh: malformed JSON is not fresh', false, is_cache_fresh($path));
remove_dir($dir);

// missing created_at
$dir = temp_cache_dir();
$path = $dir . '/cache.json';
file_put_contents($path, json_encode(['response' => []]));
check('is_cache_fresh: missing created_at is not fresh', false, is_cache_fresh($path));
remove_dir($dir);

// unparseable created_at string
$dir = temp_cache_dir();
$path = $dir . '/cache.json';
file_put_contents($path, json_encode(['created_at' => 'not a date', 'response' => []]));
check('is_cache_fresh: unparseable created_at is not fresh', false, is_cache_fresh($path));
remove_dir($dir);

// created_at in the future
$dir = temp_cache_dir();
$path = $dir . '/cache.json';
$now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
file_put_contents($path, json_encode([
    'created_at' => $now->modify('+1 hour')->format(DateTimeInterface::ATOM),
    'response' => [],
]));
check('is_cache_fresh: future created_at is not fresh', false, is_cache_fresh($path, CACHE_TTL_SECONDS, $now));
remove_dir($dir);

// respects injected $now and custom $ttl
$dir = temp_cache_dir();
$path = $dir . '/cache.json';
$now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
file_put_contents($path, json_encode([
    'created_at' => $now->modify('-30 minutes')->format(DateTimeInterface::ATOM),
    'response' => [],
]));
check('is_cache_fresh: respects custom ttl (too short)', false, is_cache_fresh($path, 60, $now));
check('is_cache_fresh: respects custom ttl (long enough)', true, is_cache_fresh($path, 3600, $now));
remove_dir($dir);

// --- fetch_cached_collections ---

// returns response verbatim
$dir = temp_cache_dir();
$path = $dir . '/cache.json';
$collections = [['round' => 'Food', 'upcomingCollections' => ['Your next food collection is Tuesday 25 March 2025']]];
file_put_contents($path, json_encode(['created_at' => '2026-01-01T12:00:00+00:00', 'response' => $collections]));
check('fetch_cached_collections: returns response verbatim', $collections, fetch_cached_collections($path));
remove_dir($dir);

// missing response key
$dir = temp_cache_dir();
$path = $dir . '/cache.json';
file_put_contents($path, json_encode(['created_at' => '2026-01-01T12:00:00+00:00']));
check('fetch_cached_collections: missing response is []', [], fetch_cached_collections($path));
remove_dir($dir);

// --- store_cached_collections + round trip ---

$dir = temp_cache_dir();
$path = $dir . '/cache.json';
$now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
$collections = [['round' => 'Recycling', 'upcomingCollections' => ['Your next recycling collection is Tuesday 25 March 2025']]];
store_cached_collections($collections, $path, $now);

$stored = json_decode(file_get_contents($path), true);
check('store_cached_collections: round trip returns same array', $collections, fetch_cached_collections($path));
check('store_cached_collections: created_at is injected now in ATOM format', $now->format(DateTimeInterface::ATOM), $stored['created_at']);
check('store_cached_collections: is_cache_fresh is true right after storing', true, is_cache_fresh($path, CACHE_TTL_SECONDS, $now));
remove_dir($dir);

// creates parent directory when missing
$dir = temp_cache_dir();
$nestedPath = $dir . '/nested/cache.json';
store_cached_collections([], $nestedPath, new DateTimeImmutable());
check('store_cached_collections: creates missing parent directory', true, file_exists($nestedPath));
unlink($nestedPath);
rmdir($dir . '/nested');
remove_dir($dir);

// --- fetch_collections branching ---

// fresh cache present: returns cached data, makes no HTTP call (bogus URL would otherwise throw)
$dir = temp_cache_dir();
$path = $dir . '/cache.json';
$collections = [['round' => 'Food', 'upcomingCollections' => ['Your next food collection is Tuesday 25 March 2025']]];
store_cached_collections($collections, $path, new DateTimeImmutable());
check(
    'fetch_collections: fresh cache returns cached data without HTTP call',
    $collections,
    fetch_collections('bogus://url', 'bogus-token', 'bogus-address', $path)
);
remove_dir($dir);

// stale cache + bogus URL: takes HTTP branch and throws
$dir = temp_cache_dir();
$path = $dir . '/cache.json';
store_cached_collections([], $path, (new DateTimeImmutable())->modify('-7 hours'));
$threw = false;
try {
    fetch_collections('bogus://url', 'bogus-token', 'bogus-address', $path);
} catch (Throwable) {
    $threw = true;
}
check('fetch_collections: stale cache takes HTTP branch and throws', true, $threw);
remove_dir($dir);

if ($failures > 0) {
    echo "\n{$failures} test(s) failed.\n";
    exit(1);
}

echo "\nAll tests passed.\n";
