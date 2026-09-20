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

// Groups multiple rounds under the same date.
check(
    'groups rounds sharing a date',
    ['2025/03/25' => ['Food 🍏', 'Recycling ♻️']],
    group_by_date([
        ['round' => 'Food', 'upcomingCollections' => ['Your next food collection is Tuesday 25 March 2025']],
        ['round' => 'Recycling', 'upcomingCollections' => ['Your next recycling collection is Tuesday 25 March 2025']],
    ])
);

// Sorts dates ascending, regardless of input order.
check(
    'sorts dates ascending',
    ['2025/03/25' => ['Food 🍏'], '2025/04/01' => ['Food 🍏']],
    group_by_date([
        ['round' => 'Food', 'upcomingCollections' => [
            'Your next food collection is Tuesday 1 April 2025',
            'Your next food collection is Tuesday 25 March 2025',
        ]],
    ])
);

// Skips sentences that don't match the expected " is <date>" shape.
check(
    'skips unparseable sentences',
    ['2025/03/25' => ['Food 🍏']],
    group_by_date([
        ['round' => 'Food', 'upcomingCollections' => [
            'No collection scheduled',
            'Your next food collection is Tuesday 25 March 2025',
        ]],
    ])
);

// Falls back to the raw round name for an unrecognised round.
check(
    'falls back to raw round name for unknown rounds',
    ['2025/03/25' => ['Garden waste']],
    group_by_date([
        ['round' => 'Garden waste', 'upcomingCollections' => ['Your next garden waste collection is Tuesday 25 March 2025']],
    ])
);

// Empty input yields an empty result.
check('empty input yields empty result', [], group_by_date([]));

if ($failures > 0) {
    echo "\n{$failures} test(s) failed.\n";
    exit(1);
}

echo "\nAll tests passed.\n";
