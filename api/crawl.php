<?php

namespace innovatopia_jp\editorial;

require_once __DIR__ . '/../includes/init.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(['error' => 'Method not allowed.'], 405);
    }

    $data = read_json_body();
    $url = string_strip((string) ($data['url'] ?? ''));
    $is_valid = ($u = filter_var($url, FILTER_VALIDATE_URL)) && in_array(parse_url($u, PHP_URL_SCHEME), ['http','https'], true);
    if (!$is_valid) {
        throw new \Exception('Invalid URL');
    }
    $fc = Config::getFirecrawlConfig();
    $res = $fc->scrape($url);

    json_response([
        'md_content' => $res->full_content,
        'title' => $res->title,
        'description' => $res->description,
    ]);
} catch (\Throwable $error) {
    json_response(['error' => $error->getMessage()], 400);
}
