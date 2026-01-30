<?php

namespace innovatopia_jp\editorial;

require_once __DIR__ . '/../includes/init.php';

function json_response(array $data, int $status = 200): never {
    if (!\headers_sent()) {
        \header('Content-Type: application/json');
        \http_response_code($status);
    }
    echo \json_encode($data);
    exit;
}

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
