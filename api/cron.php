<?php

namespace innovatopia_jp\editorial;

require_once __DIR__ . '/../includes/init.php';

try {
    if ((!is_cli_request()) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(['error' => 'Method not allowed.'], 405);
    }

    // Cron logic here
    Cron::run_all_jobs();

    json_response(['success' => true]);
} catch (\Throwable $error) {
    json_response(['error' => $error->getMessage()], 400);
}
