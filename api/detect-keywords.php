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

function read_json_body(): array {
    $raw = \file_get_contents('php://input');
    if (!\is_string($raw) || \trim($raw) === '') {
        return [];
    }
    $decoded = \json_decode($raw, true);
    if (!\is_array($decoded)) {
        json_response(['error' => 'Invalid JSON body.'], 400);
    }
    return $decoded;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(['error' => 'Method not allowed.'], 405);
    }

    $data = read_json_body();
    $title = $data['title'] ?? '';
    $comment = $data['comment'] ?? '';
    if (!\is_string($title) || !\is_string($comment)) {
        json_response(['error' => 'Invalid title or comment.'], 400);
    }

    $model = new Model(Config::getMysqlConfig(), Config::getOpenAiConfig());
    $keywords = $model->detect_keywords($title, $comment);
    json_response(['keywords' => $keywords]);
} catch (\Throwable $error) {
    json_response(['error' => $error->getMessage()], 400);
}
