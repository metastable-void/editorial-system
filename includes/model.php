<?php

namespace innovatopia_jp\editorial;

const DB_SCHEMA = <<<'SQL'
-- MySQL/MariaDB schema

create table if not exists `users` (
    id integer primary key auto_increment,
    name varchar(255) not null unique
);

create unique index if not exists `users_name_unique` on `users` (`name`);

create table if not exists `sources` (
    id integer primary key auto_increment,
    url varchar(2048) not null,
    title varchar(1024) not null default '',
    author_id integer not null,
    comment blob not null default '',
    state integer not null default 0, -- 0: working, 1: done, -1: aborted/deleted
    constraint `sources_users_id_fk` foreign key  (`author_id`) references `users` (`id`)
);

create index if not exists `sources_author_index` on `sources` (`author_id`);
create index if not exists `sources_state_index` on `sources` (`state`);
create index if not exists `sources_url_index` on `sources` (`url`);

create table if not exists `sources_keywords` (
    id integer primary key auto_increment,
    keyword varchar(255) not null, -- not unique
    source_id integer not null,
    constraint `sources_keywords_source_id_fk` foreign key (`source_id`) references `sources` (`id`)
);

create index if not exists `sources_title_index` on `sources` (`title`);
create index if not exists `sources_keywords_source_id_index` on `sources_keywords` (`source_id`);
create index if not exists `sources_keywords_keyword_index` on `sources_keywords` (`keyword`);

SQL;

enum SourceState: int {
    case Aborted = -1;
    case Working = 0;
    case Done = 1;
}

class Model {
    private \mysqli $conn;
    private OpenAiConfig $openai;
    public function __construct(MysqlConfig $config, OpenAiConfig $openai)
    {
        $this->openai = $openai;
        $this->conn = new \mysqli($config->host, $config->user, $config->pass, $config->db);
        if (!$this->conn->multi_query(DB_SCHEMA)) {
            throw new \RuntimeException('Failed to initialize schema: ' . $this->conn->error);
        }
        do {
            $result = $this->conn->store_result();
            if ($result instanceof \mysqli_result) {
                $result->free();
            }
        } while ($this->conn->more_results() && $this->conn->next_result());
    }

    /**
     * Returns (JSON form):
     * {
     *  "url_matches": [{"title": "...", "url": "...", "comment": "..."}, ...],
     *  "keyword_matches": [{"title": "...", "url": "...", "comment": "..."}, ...],
     * }
     */
    public function check_sources(string $url, array $keywords, SourceState $state): array {
        $matches = [
            'url_matches' => [],
            'keyword_matches' => [],
        ];

        $state_value = $state->value;
        $stmt = $this->conn->prepare('select s.title, s.url, s.comment, s.author_id, u.name as author_name, group_concat(distinct sk.keyword order by sk.keyword separator \',\') as keywords from sources s join users u on u.id = s.author_id left join sources_keywords sk on sk.source_id = s.id where s.url = ? and s.state = ? group by s.id, s.title, s.url, s.comment, s.author_id, u.name order by s.id desc limit 1');
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare URL query: ' . $this->conn->error);
        }
        $stmt->bind_param('si', $url, $state_value);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $matches['url_matches'][] = $row;
            }
            $result->free();
        }
        $stmt->close();

        $keywords = array_values(array_unique(array_filter($keywords, static function ($keyword) {
            return $keyword !== null && $keyword !== '';
        })));

        if (!$keywords) {
            return $matches;
        }

        $placeholders = implode(',', array_fill(0, count($keywords), '?'));
        $sql = "select s.id, s.title, s.url, s.comment, s.author_id, u.name as author_name, group_concat(distinct sk.keyword order by sk.keyword separator ',') as keywords
            from sources s
            join users u on u.id = s.author_id
            join sources_keywords sk on sk.source_id = s.id
            where sk.keyword in ($placeholders) and s.state = ?
            group by s.id, s.title, s.url, s.comment, s.author_id, u.name
            order by s.id desc";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare keyword query: ' . $this->conn->error);
        }
        $types = str_repeat('s', count($keywords)) . 'i';
        $bind_params = [$types];
        foreach ($keywords as $index => $keyword) {
            $bind_params[] = &$keywords[$index];
        }
        $bind_params[] = &$state_value;
        $stmt->bind_param(...$bind_params);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $matches['keyword_matches'][] = $row;
            }
            $result->free();
        }
        $stmt->close();

        return $matches;
    }

    /**
     * Returns:
     * [{"id": ..., "name": "..."}]
     */
    public function get_users(): array {
        $result = $this->conn->query('select id, name from users');
        if (!$result) {
            throw new \RuntimeException('Failed to fetch users: ' . $this->conn->error);
        }
        $users = [];
        if ($result instanceof \mysqli_result) {
            $users = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
        }
        return $users;
    }

    /**
     * Returns:
     * {"id": ..., "name": "..."}
     * or throws.
     */
    public function add_user(string $name): array {
        $stmt = $this->conn->prepare('insert into users (name) values (?)');
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare insert user: ' . $this->conn->error);
        }
        $stmt->bind_param('s', $name);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to insert user: ' . $this->conn->error);
        }
        $id = $this->conn->insert_id;
        $stmt->close();
        return [
            'id' => $id,
            'name' => $name,
        ];
    }

    /**
     * Extracts important short (single semantic word) keywords
     * (who is involved, what happened, etc.) from the title and the comment related
     * using OpenAI structured output, preferring most common normalized forms as keywords,
     * requesting to include both English and Japanese forms,
     * and lowercase all alphabets and replace all spaces in the keywords to '-' (this step is done algorithmically).
     */
    public function detect_keywords(string $title, string $comment): array {
        if ($title === '' && $comment === '') {
            return [];
        }

        $schema = [
            'name' => 'keyword_response',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'keywords' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['keywords'],
                'additionalProperties' => false,
            ],
        ];

        $response = $this->openai->chat_completions([
            'model' => 'gpt-4o-mini',
            'temperature' => 0,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => $schema,
            ],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Extract the most important short keywords (single semantic words) that describe who is involved and what happened. Prefer the most common normalized forms. Include both English and Japanese forms where applicable. Return only the JSON object matching the schema.',
                ],
                [
                    'role' => 'user',
                    'content' => "Title:\n{$title}\n\nComment:\n{$comment}",
                ],
            ],
        ]);

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new \RuntimeException('OpenAI response missing content.');
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Failed to decode OpenAI response content.');
        }

        $keywords = $decoded['keywords'] ?? [];
        return $this->normalize_keywords($keywords);
    }

    /**
     * Adds a source with state=0, and adds keywords. Returns the source ID.
     */
    public function add_source(int $author_id, string $url, string $title, string $comment, array $keywords): int {
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('insert into sources (url, title, author_id, comment, state) values (?, ?, ?, ?, 0)');
            if (!$stmt) {
                throw new \RuntimeException('Failed to prepare insert source: ' . $this->conn->error);
            }
            $stmt->bind_param('ssis', $url, $title, $author_id, $comment);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new \RuntimeException('Failed to insert source: ' . $this->conn->error);
            }
            $source_id = (int)$this->conn->insert_id;
            $stmt->close();

            $filtered = [];
            foreach ($keywords as $keyword) {
                if (!is_string($keyword)) {
                    continue;
                }
                $value = trim($keyword);
                if ($value === '') {
                    continue;
                }
                $filtered[] = $value;
            }
            $filtered = array_values(array_unique($filtered));

            if ($filtered) {
                $placeholders = implode(',', array_fill(0, count($filtered), '(?, ?)'));
                $sql = "insert into sources_keywords (keyword, source_id) values {$placeholders}";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    throw new \RuntimeException('Failed to prepare insert keywords: ' . $this->conn->error);
                }
                $types = str_repeat('si', count($filtered));
                $bind_params = [$types];
                $source_ids = array_fill(0, count($filtered), $source_id);
                foreach ($filtered as $index => $keyword) {
                    $bind_params[] = &$filtered[$index];
                    $bind_params[] = &$source_ids[$index];
                }
                $stmt->bind_param(...$bind_params);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new \RuntimeException('Failed to insert keywords: ' . $this->conn->error);
                }
                $stmt->close();
            }

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }

        return $source_id;
    }

    /**
     * Returns:
     * [{"id": ..., "url": "...", "title": "...", "comment": "...", "state": ..., "author_id": ..., "author_name": "..."}, ...]
     */
    public function get_sources(int $author_id, SourceState $state): array {
        $stmt = $this->conn->prepare('select s.id, s.url, s.title, s.comment, s.state, s.author_id, u.name as author_name, group_concat(distinct sk.keyword order by sk.keyword separator \',\') as keywords from sources s join users u on u.id = s.author_id left join sources_keywords sk on sk.source_id = s.id where s.author_id = ? and s.state = ? group by s.id, s.url, s.title, s.comment, s.state, s.author_id, u.name order by s.id desc');
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare fetch sources: ' . $this->conn->error);
        }
        $state_value = $state->value;
        $stmt->bind_param('ii', $author_id, $state_value);
        $stmt->execute();
        $result = $stmt->get_result();
        $sources = [];
        if ($result instanceof \mysqli_result) {
            $sources = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
        }
        $stmt->close();
        return $sources;
    }

    /**
     * Returns:
     * [{"id": ..., "url": "...", "title": "...", "comment": "...", "state": ..., "author_id": ..., "author_name": "...", "matched_keywords": "...", "match_count": ...}, ...]
     */
    public function search_sources(array $keywords, SourceState $state): array {
        $keywords = array_values(array_unique(array_filter($keywords, static function ($keyword) {
            return $keyword !== null && $keyword !== '';
        })));
        if (!$keywords) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keywords), '?'));
        $sql = "select s.id, s.url, s.title, s.comment, s.state, s.author_id, u.name as author_name,
                group_concat(distinct sk.keyword order by sk.keyword separator ',') as matched_keywords,
                count(distinct sk.keyword) as match_count
            from sources s
            join users u on u.id = s.author_id
            join sources_keywords sk on sk.source_id = s.id
            where sk.keyword in ($placeholders) and s.state = ?
            group by s.id, s.url, s.title, s.comment, s.state, s.author_id, u.name
            order by match_count desc, s.id desc";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare search sources: ' . $this->conn->error);
        }
        $types = str_repeat('s', count($keywords)) . 'i';
        $bind_params = [$types];
        foreach ($keywords as $index => $keyword) {
            $bind_params[] = &$keywords[$index];
        }
        $state_value = $state->value;
        $bind_params[] = &$state_value;
        $stmt->bind_param(...$bind_params);
        $stmt->execute();
        $result = $stmt->get_result();
        $sources = [];
        if ($result instanceof \mysqli_result) {
            $sources = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
        }
        $stmt->close();
        return $sources;
    }

    /**
     * Returns:
     * ["keyword1", "keyword2", ...]
     */
    public function search_keywords(string $query): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $schema = [
            'name' => 'keyword_response',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'keywords' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['keywords'],
                'additionalProperties' => false,
            ],
        ];

        $response = $this->openai->chat_completions([
            'model' => 'gpt-4o-mini',
            'temperature' => 0,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => $schema,
            ],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Extract the most important short keywords (single semantic words) that describe who is involved and what happened. Prefer the most common normalized forms. Include both English and Japanese forms where applicable. Return only the JSON object matching the schema.',
                ],
                [
                    'role' => 'user',
                    'content' => "Query:\n{$query}",
                ],
            ],
        ]);

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new \RuntimeException('OpenAI response missing content.');
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Failed to decode OpenAI response content.');
        }

        $keywords = $decoded['keywords'] ?? [];
        return $this->normalize_keywords($keywords);
    }

    /**
     * @param mixed $keywords
     * @return string[]
     */
    private function normalize_keywords(mixed $keywords): array {
        if (!is_array($keywords)) {
            $keywords = [];
        }
        $normalized = [];
        foreach ($keywords as $keyword) {
            if (!is_string($keyword)) {
                continue;
            }
            $value = trim($keyword);
            if ($value === '') {
                continue;
            }
            $value = strtolower($value);
            $value = preg_replace('/[[:punct:]\x{3000}]+/u', '-', $value);
            $value = preg_replace('/\s+/', '-', $value);
            $value = preg_replace('/-+/', '-', $value);
            $value = trim($value, '-');
            if ($value !== '') {
                $normalized[] = $value;
            }
        }
        return array_values(array_unique($normalized));
    }

    /**
     * Returns:
     * [{"keyword": "...", "count": ...}, ...]
     */
    public function get_unique_keywords(): array {
        $result = $this->conn->query('select sk.keyword, count(distinct sk.source_id) as count from sources_keywords sk join sources s on s.id = sk.source_id where s.state >= 0 group by sk.keyword order by count desc, sk.keyword');
        if (!$result) {
            throw new \RuntimeException('Failed to fetch keywords: ' . $this->conn->error);
        }
        $keywords = [];
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                if (isset($row['keyword'])) {
                    $keywords[] = [
                        'keyword' => $row['keyword'],
                        'count' => (int)($row['count'] ?? 0),
                    ];
                }
            }
            $result->free();
        }
        return $keywords;
    }

    /**
     * Returns:
     * ["working" => ..., "done" => ..., "aborted" => ...]
     */
    public function get_keyword_state_counts(string $keyword): array {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['working' => 0, 'done' => 0, 'aborted' => 0];
        }
        $sql = 'select s.state, count(distinct s.id) as count
            from sources s
            join sources_keywords sk on sk.source_id = s.id
            where sk.keyword = ?
            group by s.state';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare keyword state counts: ' . $this->conn->error);
        }
        $stmt->bind_param('s', $keyword);
        $stmt->execute();
        $result = $stmt->get_result();
        $counts = [
            'working' => 0,
            'done' => 0,
            'aborted' => 0,
        ];
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $state = (int)($row['state'] ?? 0);
                $count = (int)($row['count'] ?? 0);
                if ($state === SourceState::Working->value) {
                    $counts['working'] = $count;
                } elseif ($state === SourceState::Done->value) {
                    $counts['done'] = $count;
                } elseif ($state === SourceState::Aborted->value) {
                    $counts['aborted'] = $count;
                }
            }
            $result->free();
        }
        $stmt->close();
        return $counts;
    }

    public function change_source_state(int $source_id, SourceState $state): void {
        $stmt = $this->conn->prepare('update sources set state = ? where id = ?');
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare update source state: ' . $this->conn->error);
        }
        $state_value = $state->value;
        $stmt->bind_param('ii', $state_value, $source_id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to update source state: ' . $this->conn->error);
        }
        $stmt->close();
    }
}
