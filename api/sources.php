<?php

namespace innovatopia_jp\editorial;

require_once __DIR__ . '/../includes/init.php';

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

try {
    $model = new Model(Config::getMysqlConfig(), Config::getOpenAiConfig());
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $source_id = $_GET['source_id'] ?? null;
        if (\is_string($source_id) && \is_numeric($source_id)) {
            $source_id = (int)$source_id;
        }
        if (\is_int($source_id) && $source_id > 0) {
            $source = $model->get_source_by_id($source_id);
            if ($source === null) {
                json_response(['error' => 'Not found.'], 404);
            }
            json_response(['source' => $source]);
        }

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
        $content_md = $data['content_md'] ?? '';
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
        if ($content_md === null) {
            $content_md = '';
        }
        if (!\is_string($content_md)) {
            json_response(['error' => 'Invalid content_md.'], 400);
        }
        if (!\is_array($keywords)) {
            json_response(['error' => 'Invalid keywords.'], 400);
        }
        $source_id = $model->add_source($author_id, $url, $title, $comment, $content_md, $keywords);
        json_response(['id' => $source_id], 201);
    }

    if ($method === 'PATCH' || $method === 'PUT') {
        $data = read_json_body();
        $source_id = $data['source_id'] ?? null;
        if (!\is_int($source_id)) {
            json_response(['error' => 'Missing or invalid source_id.'], 400);
        }
        $did_update = false;

        if (\array_key_exists('state', $data)) {
            $state_enum = parse_state($data['state']);
            $model->change_source_state($source_id, $state_enum);
            $did_update = true;
        }

        $has_content_update = \array_key_exists('title', $data)
            || \array_key_exists('comment', $data)
            || \array_key_exists('content_md', $data);
        if ($has_content_update) {
            $source = $model->get_source_by_id($source_id);
            if ($source === null) {
                json_response(['error' => 'Not found.'], 404);
            }
            $title = $source['title'] ?? '';
            $comment = $source['comment'] ?? '';
            $content_md = $source['content_md'] ?? '';

            if (\array_key_exists('title', $data)) {
                if (!\is_string($data['title'])) {
                    json_response(['error' => 'Invalid title.'], 400);
                }
                $title = $data['title'];
            }
            if (\array_key_exists('comment', $data)) {
                if (!\is_string($data['comment'])) {
                    json_response(['error' => 'Invalid comment.'], 400);
                }
                $comment = $data['comment'];
            }
            if (\array_key_exists('content_md', $data)) {
                if ($data['content_md'] === null) {
                    $content_md = '';
                } elseif (!\is_string($data['content_md'])) {
                    json_response(['error' => 'Invalid content_md.'], 400);
                } else {
                    $content_md = $data['content_md'];
                }
            }

            $model->update_source_content($source_id, $title, $comment, $content_md);
            $did_update = true;
        }

        if (!$did_update) {
            json_response(['error' => 'No updates provided.'], 400);
        }
        json_response(['ok' => true]);
    }

    json_response(['error' => 'Method not allowed.'], 405);
} catch (\Throwable $error) {
    json_response(['error' => $error->getMessage()], 400);
}
