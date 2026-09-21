<?php

declare(strict_types=1);

const LOG_LEVEL_INFO = 'INFO';
const LOG_LEVEL_ERROR = 'ERROR';

function log_message(string $level, string $message, array $context = []): void
{
    $entry = [
        'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
        'level' => $level,
        'message' => $message,
    ];

    if ($context !== []) {
        $entry['context'] = $context;
    }

    fwrite(STDOUT, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n");
}

const API_URL = '%s/w/webpage/waste-collection-days'
    . '?webpage_subpage_id=PAG0000570FEFFB1&webpage_token=%s&widget_action=handle_event';

const CACHE_PATH = __DIR__ . '/../cache.json';
const CACHE_TTL_SECONDS = 21600; // 6 hours

function config(): array
{
    $token = getenv('BIN_TOKEN');
    $addressId = getenv('BIN_ADDRESS_ID');
    $url = getenv('BIN_URL');

    if (empty($token) || empty($addressId) || empty($url)) {
        throw new RuntimeException('BIN_TOKEN, BIN_ADDRESS_ID and BIN_URL must be set');
    }

    return ['token' => $token, 'addressId' => $addressId, 'url' => $url];
}

function is_cache_fresh(
    string $cachePath = CACHE_PATH,
    int $ttl = CACHE_TTL_SECONDS,
    ?DateTimeImmutable $now = null
): bool {
    if (!is_readable($cachePath)) {
        return false;
    }

    $contents = file_get_contents($cachePath);
    if ($contents === false) {
        return false;
    }

    $data = json_decode($contents, true);
    if (!is_array($data)) {
        return false;
    }

    if (!isset($data['created_at']) || !is_string($data['created_at'])) {
        return false;
    }

    if (!array_key_exists('response', $data)) {
        return false;
    }

    try {
        $createdAt = new DateTimeImmutable($data['created_at']);
    } catch (Exception) {
        return false;
    }

    $now ??= new DateTimeImmutable();

    if ($createdAt->getTimestamp() > $now->getTimestamp()) {
        return false;
    }

    return $now->getTimestamp() - $createdAt->getTimestamp() < $ttl;
}

function fetch_cached_collections(string $cachePath = CACHE_PATH): array
{
    $contents = file_get_contents($cachePath);
    $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    return $data['response'] ?? [];
}

function store_cached_collections(array $collections, string $cachePath = CACHE_PATH, ?DateTimeImmutable $now = null): void
{
    $now ??= new DateTimeImmutable();

    $dir = dirname($cachePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $json = json_encode(
        ['created_at' => $now->format(DateTimeInterface::ATOM), 'response' => $collections],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    $tmpPath = $cachePath . '.tmp';
    file_put_contents($tmpPath, $json);
    rename($tmpPath, $cachePath);
}

function fetch_collections_http(string $url, string $token, string $addressId): array
{
    $body = http_build_query([
        'code_action' => 'find_rounds',
        'code_params' => json_encode(['addressId' => $addressId]),
        'action_cell_id' => 'PCL0003988FEFFB1',
        'action_page_id' => 'PAG0000570FEFFB1',
    ]);

    log_message(LOG_LEVEL_INFO, 'HTTP call made', ['url' => sprintf(API_URL, $url, '***')]);

    $ch = curl_init(sprintf(API_URL, $url, $token));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With: XMLHttpRequest',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        ],
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException('Request failed: ' . curl_error($ch));
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($status === 401 || $status === 403) {
        log_message(LOG_LEVEL_ERROR, 'Token is no longer valid', ['status' => $status]);
        throw new RuntimeException("Unexpected HTTP status: {$status}");
    }

    if ($status !== 200) {
        throw new RuntimeException("Unexpected HTTP status: {$status}");
    }

    $data = json_decode($response, true, flags: JSON_THROW_ON_ERROR);

    return $data['response']['collections'] ?? [];
}

function fetch_collections(string $url, string $token, string $addressId, string $cachePath = CACHE_PATH): array
{
    if (is_cache_fresh($cachePath)) {
        log_message(LOG_LEVEL_INFO, 'Cache used', ['path' => $cachePath]);

        return fetch_cached_collections($cachePath);
    }

    $collections = fetch_collections_http($url, $token, $addressId);
    store_cached_collections($collections, $cachePath);

    return $collections;
}

function round_label(string $round): string
{
    return match ($round) {
        'Food' => 'Food 🍏',
        'General waste' => 'General 🗑️',
        'Recycling' => 'Recycling ♻️',
        default => $round,
    };
}

function collection_date(string $sentence): ?string
{
    if (!preg_match('/ is (.*)/', $sentence, $m)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!l j F Y', trim($m[1]));
    if ($date === false) {
        return null;
    }

    return $date->format('Y/m/d');
}

function group_by_date(array $collections): array
{
    $grouped = [];

    foreach ($collections as $collection) {
        $label = round_label($collection['round'] ?? '');
        foreach ($collection['upcomingCollections'] ?? [] as $sentence) {
            $date = collection_date($sentence);
            if ($date === null) {
                continue;
            }
            $grouped[$date][] = $label;
        }
    }

    ksort($grouped);

    return $grouped;
}
