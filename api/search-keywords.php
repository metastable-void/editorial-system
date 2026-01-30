<?php

namespace innovatopia_jp\editorial;

require_once __DIR__ . '/../includes/init.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_response(['error' => 'Method not allowed.'], 405);
    }
    $query = $_GET['query'] ?? '';
    if (!\is_string($query)) {
        json_response(['error' => 'Invalid query.'], 400);
    }
    $model = new Model(Config::getMysqlConfig(), Config::getOpenAiConfig());
    $keywords = $model->search_keywords($query);
    json_response(['keywords' => $keywords]);
} catch (\Throwable $error) {
    json_response(['error' => $error->getMessage()], 400);
}
