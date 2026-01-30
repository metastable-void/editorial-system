<?php

namespace innovatopia_jp\editorial;

require_once __DIR__ . '/../includes/init.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_response(['error' => 'Method not allowed.'], 405);
    }
    $user_id = $_GET['user_id'] ?? null;
    if (\is_string($user_id) && \is_numeric($user_id)) {
        $user_id = (int)$user_id;
    }
    if (!\is_int($user_id) || $user_id <= 0) {
        json_response(['error' => 'Missing or invalid user_id.'], 400);
    }
    $model = new Model(Config::getMysqlConfig(), Config::getOpenAiConfig());
    $counts = $model->get_user_state_counts($user_id);
    json_response(['user_id' => $user_id, 'counts' => $counts]);
} catch (\Throwable $error) {
    json_response(['error' => $error->getMessage()], 400);
}
