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
    $model = new Model(Config::getMysqlConfig(), Config::getOpenAiConfig());
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $users = $model->get_users();
        json_response(['users' => $users]);
    }

    if ($method === 'POST') {
        $data = read_json_body();
        $name = $data['name'] ?? null;
        if (!\is_string($name) || \trim($name) === '') {
            json_response(['error' => 'Missing or invalid name.'], 400);
        }
        $user = $model->add_user($name);
        json_response($user, 201);
    }

    json_response(['error' => 'Method not allowed.'], 405);
} catch (\Throwable $error) {
    json_response(['error' => $error->getMessage()], 400);
}
