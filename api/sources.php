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

function parse_state(mixed $value): SourceState {
    if (\is_string($value)) {
        $trimmed = \trim($value);
        if ($trimmed === '') {
            json_response(['error' => 'Missing state.'], 400);
        }
        if (\is_numeric($trimmed)) {
            $value = (int)$trimmed;
        } else {
            $normalized = \strtolower($trimmed);
            if ($normalized === 'working') {
                return SourceState::Working;
            }
            if ($normalized === 'done') {
                return SourceState::Done;
            }
            if ($normalized === 'aborted') {
                return SourceState::Aborted;
            }
            json_response(['error' => 'Invalid state.'], 400);
        }
    }
    if (!\is_int($value)) {
        json_response(['error' => 'Invalid state.'], 400);
    }
    try {
        return SourceState::from($value);
    } catch (\ValueError) {
        json_response(['error' => 'Invalid state.'], 400);
    }
}

function parse_keywords(mixed $value): array {
    if (\is_array($value)) {
        return $value;
    }
    if (\is_string($value)) {
        $trimmed = \trim($value);
        if ($trimmed === '') {
            return [];
        }
        if ($trimmed[0] === '[') {
            $decoded = \json_decode($trimmed, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }
        return \array_filter(\array_map('trim', \explode(',', $trimmed)), static fn($keyword) => $keyword !== '');
    }
    return [];
}

$model = new Model(Config::getMysqlConfig(), Config::getOpenAiConfig());
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $url = $_GET['url'] ?? null;
    if (\is_string($url) && \trim($url) !== '') {
        $keywords = parse_keywords($_GET['keywords'] ?? []);
        $state = parse_state($_GET['state'] ?? null);
        $matches = $model->check_sources($url, $keywords, $state);
        json_response($matches);
    }

    $author_id = $_GET['author_id'] ?? null;
    if (\is_string($author_id) && \is_numeric($author_id)) {
        $author_id = (int)$author_id;
    }
    if (!\is_int($author_id) || $author_id <= 0) {
        json_response(['error' => 'Missing or invalid author_id.'], 400);
    }
    $state = parse_state($_GET['state'] ?? null);
    $sources = $model->get_sources($author_id, $state);
    json_response(['sources' => $sources]);
}

if ($method === 'POST') {
    $data = read_json_body();
    $author_id = $data['author_id'] ?? null;
    $url = $data['url'] ?? null;
    $title = $data['title'] ?? '';
    $comment = $data['comment'] ?? '';
    $keywords = $data['keywords'] ?? [];
    if (!\is_int($author_id)) {
        json_response(['error' => 'Missing or invalid author_id.'], 400);
    }
    if (!\is_string($url) || \trim($url) === '') {
        json_response(['error' => 'Missing or invalid url.'], 400);
    }
    if (!\is_string($title) || !\is_string($comment)) {
        json_response(['error' => 'Invalid title or comment.'], 400);
    }
    if (!\is_array($keywords)) {
        json_response(['error' => 'Invalid keywords.'], 400);
    }
    $source_id = $model->add_source($author_id, $url, $title, $comment, $keywords);
    json_response(['id' => $source_id], 201);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $data = read_json_body();
    $source_id = $data['source_id'] ?? null;
    $state = $data['state'] ?? null;
    if (!\is_int($source_id)) {
        json_response(['error' => 'Missing or invalid source_id.'], 400);
    }
    $state_enum = parse_state($state);
    $model->change_source_state($source_id, $state_enum);
    json_response(['ok' => true]);
}

json_response(['error' => 'Method not allowed.'], 405);
