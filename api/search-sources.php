<?php

namespace innovatopia_jp\editorial;

require_once __DIR__ . '/../includes/init.php';

function parse_state(mixed $value): SourceState {
    if (\is_string($value)) {
        $trimmed = \trim($value);
        if ($trimmed === '') {
            return SourceState::Working;
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
    if ($value === null) {
        return SourceState::Working;
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
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_response(['error' => 'Method not allowed.'], 405);
    }
    $keywords = parse_keywords($_GET['keywords'] ?? $_GET['keywords[]'] ?? []);
    if (!$keywords) {
        json_response(['error' => 'Missing keywords.'], 400);
    }
    $state = parse_state($_GET['state'] ?? null);
    $model = new Model(Config::getMysqlConfig(), Config::getOpenAiConfig());
    $sources = $model->search_sources($keywords, $state);
    json_response(['sources' => $sources]);
} catch (\Throwable $error) {
    json_response(['error' => $error->getMessage()], 400);
}
