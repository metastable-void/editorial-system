<?php

namespace innovatopia_jp\editorial;

function return_error_response(string $error): never {
    if (!\headers_sent()) {
        \header('Content-Type: application/json');
        $data = [];
        $data['error'] = $error;
        echo \json_encode($data);
    }
    exit;
}

if (!\headers_sent()) {
    \header('Access-Control-Allow-Origin: *');
    \header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, OPTIONS');
    \header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    if (!\headers_sent()) {
        \header('HTTP/1.1 204 No Content');
    }
    exit;
}

if (!\is_file(__DIR__ . '/config.php')) {
    return_error_response('No config.php found');
}

require_once __DIR__ . '/config.php';

$login = Config::getAdminLogin();
$auth_user = $_SERVER['PHP_AUTH_USER'] ?? null;
$auth_pass = $_SERVER['PHP_AUTH_PW'] ?? null;
if ($auth_user === null || $auth_pass === null) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (\is_string($auth_header) && \strncmp($auth_header, 'Basic ', 6) === 0) {
        $decoded = \base64_decode(\substr($auth_header, 6), true);
        if ($decoded !== false) {
            $parts = \explode(':', $decoded, 2);
            $auth_user = $parts[0] ?? null;
            $auth_pass = $parts[1] ?? null;
        }
    }
}
if ($auth_user !== $login->username || $auth_pass !== $login->password) {
    if (!\headers_sent()) {
        \header('WWW-Authenticate: Basic realm="Editorial"');
        \header('HTTP/1.1 401 Unauthorized');
    }
    return_error_response('Unauthorized');
}

require_once __DIR__ . '/model.php';
