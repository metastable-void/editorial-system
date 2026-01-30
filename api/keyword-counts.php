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
    $keyword = $_GET['keyword'] ?? '';
    if (!\is_string($keyword) || \trim($keyword) === '') {
        json_response(['error' => 'Missing or invalid keyword.'], 400);
    }
    $model = new Model(Config::getMysqlConfig(), Config::getOpenAiConfig());
    $counts = $model->get_keyword_state_counts($keyword);
    json_response(['keyword' => $keyword, 'counts' => $counts]);
} catch (\Throwable $error) {
    json_response(['error' => $error->getMessage()], 400);
}
