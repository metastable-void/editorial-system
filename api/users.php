<?php

namespace innovatopia_jp\editorial;

require_once __DIR__ . '/../includes/init.php';

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

    if ($method === 'PATCH' || $method === 'PUT') {
        $data = read_json_body();
        $user_id = $data['user_id'] ?? null;
        $name = $data['name'] ?? null;
        if (!\is_int($user_id)) {
            json_response(['error' => 'Missing or invalid user_id.'], 400);
        }
        if (!\is_string($name) || \trim($name) === '') {
            json_response(['error' => 'Missing or invalid name.'], 400);
        }
        $user = $model->update_user($user_id, $name);
        json_response($user);
    }

    json_response(['error' => 'Method not allowed.'], 405);
} catch (\Throwable $error) {
    json_response(['error' => $error->getMessage()], 400);
}
