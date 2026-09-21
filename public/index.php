<?php

declare(strict_types=1);

require __DIR__ . '/functions.php';

log_message(LOG_LEVEL_INFO, 'HTTP request made', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
]);

$error = null;
$grouped = [];

try {
    $config = config();
    $collections = fetch_collections($config['url'], $config['token'], $config['addressId']);
    $grouped = group_by_date($collections);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bin Collections</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main>
    <h1>Bin Collections</h1>

    <?php if ($error !== null): ?>
        <div class="error">
            <strong>Couldn't load collections.</strong>
            <p><?= htmlspecialchars($error) ?></p>
        </div>
    <?php elseif (empty($grouped)): ?>
        <div class="error">
            <strong>No upcoming collections found.</strong>
        </div>
    <?php else: ?>
        <div class="cards">
            <?php foreach ($grouped as $date => $labels): ?>
                <?php $weekday = DateTimeImmutable::createFromFormat('!Y/m/d', $date)->format('l'); ?>
                <div class="card">
                    <div class="date"><?= htmlspecialchars($date) ?></div>
                    <div class="weekday"><?= htmlspecialchars($weekday) ?></div>
                    <div class="badges">
                        <?php foreach ($labels as $label): ?>
                            <span class="badge badge-<?= htmlspecialchars(strtolower(explode(' ', $label)[0])) ?>">
                                <?= htmlspecialchars($label) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
