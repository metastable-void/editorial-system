<?php

namespace innovatopia_jp\editorial;

require_once __DIR__ . '/../includes/init.php';

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
    $result = $model->detect_keywords($title, $comment);
    $keywords = $result['keywords'] ?? [];
    $title_ja = $result['title_ja'] ?? '';
    json_response(['keywords' => $keywords, 'title_ja' => $title_ja]);
} catch (\Throwable $error) {
    json_response(['error' => $error->getMessage()], 400);
}
