<?php

namespace innovatopia_jp\editorial;

function string_strip(string $str): string {
    return preg_replace('/^\s+|\s+$/u', '', $str);
}

function is_cli_request(): bool {
    return \PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg';
}

function send_header(string $header): void {
    if (!\headers_sent() && !is_cli_request()) {
        \header($header);
    }
}

function json_response(array $data, int $status = 200): never {
    send_header('Content-Type: application/json');
    if (!\headers_sent() && !is_cli_request()) {
        \http_response_code($status);
    }
    echo \json_encode($data), "\n";
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

function return_error_response(string $error): never {
    json_response(['error' => $error], 400);
}

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$is_api_request = \is_string($request_uri) && \strpos($request_uri, '/api/') === 0;

if (!is_cli_request()) {
    if ($is_api_request) {
        send_header('Access-Control-Allow-Origin: *');
        send_header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, OPTIONS');
        send_header('Access-Control-Allow-Headers: Content-Type, Authorization');
    } else {
        send_header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
    }
}

if ($is_api_request && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS')) {
    send_header('HTTP/1.1 204 No Content');
    exit;
}

if (!\is_file(__DIR__ . '/config.php')) {
    return_error_response('No config.php found');
}

require_once __DIR__ . '/config.php';

if (!is_cli_request()) {
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
        if (is_cli_request()) {
            return_error_response('Unauthorized');
        }
        send_header('WWW-Authenticate: Basic realm="Editorial"');
        send_header('HTTP/1.1 401 Unauthorized');
        return_error_response('Unauthorized');
    }
}

require_once __DIR__ . '/wordpress.php';
require_once __DIR__ . '/model.php';
