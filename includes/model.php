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
    comment mediumtext not null default '',
    content_md mediumtext not null default '',
    state integer not null default 0, -- 0: working, 1: done, -1: aborted/deleted
    updated_date bigint not null default 1769748117,
    constraint `sources_users_id_fk` foreign key  (`author_id`) references `users` (`id`)
);

create index if not exists `sources_author_index` on `sources` (`author_id`);
create index if not exists `sources_state_index` on `sources` (`state`);
create index if not exists `sources_url_index` on `sources` (`url`);
create index if not exists `sources_title_index` on `sources` (`title`);

create table if not exists `keywords` (
    id integer primary key auto_increment,
    keyword varchar(255) not null unique
);

create unique index if not exists `keywords_keyword_index` on `keywords` (`keyword`);

create table if not exists `sources_keywords_v2` (
    id integer primary key auto_increment,
    source_id integer not null,
    keyword_id integer not null,
    constraint `sources_keywords_v2_source_id_fk` foreign key (`source_id`) references `sources` (`id`),
    constraint `sources_keywords_v2_keyword_id_fk` foreign key (`keyword_id`) references `keywords` (`id`)
);

create index if not exists `sources_keywords_v2_source_id_index` on `sources_keywords_v2` (`source_id`);
create index if not exists `sources_keywords_v2_keyword_id_index` on `sources_keywords_v2` (`keyword_id`);

create unique index if not exists `sources_keywords_v2_unique_pair` on `sources_keywords_v2` (`source_id`, `keyword_id`);

SQL;

enum SourceState: int {
    case Aborted = -1;
    case Working = 0;
    case Done = 1;
}

class Model {
    private \mysqli $conn;
    private OpenAiConfig $openai;
    private const KEYWORDS_LIMIT = 200;
    private const COMMENT_BYTES_LIMIT = 4000;
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
        $stmt = $this->conn->prepare('select s.id, s.title, s.url, s.comment, s.author_id, s.updated_date, u.name as author_name, group_concat(distinct k.keyword order by k.keyword separator \',\') as keywords from sources s join users u on u.id = s.author_id left join sources_keywords_v2 sk on sk.source_id = s.id left join keywords k on k.id = sk.keyword_id where s.url = ? and s.state = ? group by s.id, s.title, s.url, s.comment, s.author_id, s.updated_date, u.name order by s.id desc limit 1');
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
        $sql = "select s.id, s.title, s.url, s.comment, s.author_id, s.updated_date, u.name as author_name, group_concat(distinct k.keyword order by k.keyword separator ',') as keywords
            from sources s
            join users u on u.id = s.author_id
            join sources_keywords_v2 sk on sk.source_id = s.id
            join keywords k on k.id = sk.keyword_id
            where k.keyword in ($placeholders) and s.state = ?
            group by s.id, s.title, s.url, s.comment, s.author_id, s.updated_date, u.name
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
        $result = $this->conn->query('select id, name from users order by id desc limit 1000');
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
     * Returns:
     * {"id": ..., "name": "..."}
     */
    public function update_user(int $user_id, string $name): array {
        $stmt = $this->conn->prepare('update users set name = ? where id = ?');
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare update user: ' . $this->conn->error);
        }
        $stmt->bind_param('si', $name, $user_id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to update user: ' . $this->conn->error);
        }
        $stmt->close();
        return [
            'id' => $user_id,
            'name' => $name,
        ];
    }

    /**
     * Extracts important short (single semantic word) keywords
     * (who is involved, what happened, etc.) from the title and the comment related
     * using OpenAI structured output, preferring most common normalized forms as keywords,
     * requesting to include both English and Japanese forms,
     * and lowercase all alphabets and replace all spaces in the keywords to '-' (this step is done algorithmically).
     * Also returns a summarized Japanese title.
     */
    public function detect_keywords(string $title, string $comment): array {
        if ($title === '' && $comment === '') {
            return [
                'keywords' => [],
                'title_ja' => '',
            ];
        }

        $schema = [
            'name' => 'keyword_response',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'title_ja' => [
                        'type' => 'string',
                    ],
                    'keywords_en' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'keywords_ja' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['keywords_en', 'keywords_ja', 'title_ja'],
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
                    'content' => 'Extract the most important short keywords (single semantic words) that describe who is involved and what happened. Return separate lists: keywords_en (English) and keywords_ja (Japanese). Prefer the most common normalized forms. For proper nouns, whenever feasible, include both Japanese and English variants in their respective lists. When a term must appear in both lists (e.g., AI), include it in both, but prefer katakana in keywords_ja when feasible. When a broader term for something exists (e.g. Europe for Europian Union), please also include that in the lists. Avoid too generic terms (news, report, etc.). Also produce a concise Japanese title summary (title_ja). Return only the JSON object matching the schema.',
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

        $keywords_en = $decoded['keywords_en'] ?? [];
        $keywords_ja = $decoded['keywords_ja'] ?? [];
        $title_ja = $decoded['title_ja'] ?? '';
        if (!is_string($title_ja)) {
            $title_ja = '';
        }
        $title_ja = trim($title_ja);

        return [
            'keywords' => $this->normalize_keywords(array_merge(
                is_array($keywords_en) ? $keywords_en : [],
                is_array($keywords_ja) ? $keywords_ja : []
            )),
            'title_ja' => $title_ja,
        ];
    }

    /**
     * Adds a source with state=0, and adds keywords. Returns the source ID.
     */
    public function add_source(int $author_id, string $url, string $title, string $comment, string $content_md, array $keywords): int {
        if (strlen($comment) > self::COMMENT_BYTES_LIMIT) {
            throw new \RuntimeException('Comment exceeds limit.');
        }
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('insert into sources (url, title, author_id, comment, content_md, state, updated_date) values (?, ?, ?, ?, ?, 0, ?)');
            if (!$stmt) {
                throw new \RuntimeException('Failed to prepare insert source: ' . $this->conn->error);
            }
            $now = time();
            $stmt->bind_param('ssissi', $url, $title, $author_id, $comment, $content_md, $now);
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
            if (count($filtered) > self::KEYWORDS_LIMIT) {
                throw new \RuntimeException('Too many keywords.');
            }

            if ($filtered) {
                $placeholders = implode(',', array_fill(0, count($filtered), '(?)'));
                $sql = "insert ignore into keywords (keyword) values {$placeholders}";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    throw new \RuntimeException('Failed to prepare insert keywords: ' . $this->conn->error);
                }
                $types = str_repeat('s', count($filtered));
                $bind_params = [$types];
                foreach ($filtered as $index => $keyword) {
                    $bind_params[] = &$filtered[$index];
                }
                $stmt->bind_param(...$bind_params);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new \RuntimeException('Failed to insert keywords: ' . $this->conn->error);
                }
                $stmt->close();

                $placeholders = implode(',', array_fill(0, count($filtered), '?'));
                $sql = "select id, keyword from keywords where keyword in ({$placeholders})";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    throw new \RuntimeException('Failed to prepare select keyword ids: ' . $this->conn->error);
                }
                $types = str_repeat('s', count($filtered));
                $bind_params = [$types];
                foreach ($filtered as $index => $keyword) {
                    $bind_params[] = &$filtered[$index];
                }
                $stmt->bind_param(...$bind_params);
                $stmt->execute();
                $result = $stmt->get_result();
                $keyword_ids = [];
                if ($result instanceof \mysqli_result) {
                    while ($row = $result->fetch_assoc()) {
                        if (isset($row['id'])) {
                            $keyword_ids[] = (int)$row['id'];
                        }
                    }
                    $result->free();
                }
                $stmt->close();

                if ($keyword_ids) {
                    $placeholders = implode(',', array_fill(0, count($keyword_ids), '(?, ?)'));
                    $sql = "insert into sources_keywords_v2 (source_id, keyword_id) values {$placeholders}";
                    $stmt = $this->conn->prepare($sql);
                    if (!$stmt) {
                        throw new \RuntimeException('Failed to prepare insert source keywords: ' . $this->conn->error);
                    }
                    $types = str_repeat('ii', count($keyword_ids));
                    $bind_params = [$types];
                    $source_ids = array_fill(0, count($keyword_ids), $source_id);
                    foreach ($keyword_ids as $index => $keyword_id) {
                        $bind_params[] = &$source_ids[$index];
                        $bind_params[] = &$keyword_ids[$index];
                    }
                    $stmt->bind_param(...$bind_params);
                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new \RuntimeException('Failed to insert source keywords: ' . $this->conn->error);
                    }
                    $stmt->close();
                }
            }

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }

        return $source_id;
    }

    /**
     * Returns a source row or null.
     */
    public function get_source_by_id(int $source_id): ?array {
        $stmt = $this->conn->prepare('select s.id, s.url, s.title, s.author_id, s.comment, s.content_md, s.state, s.updated_date, u.name as author_name, group_concat(distinct k.keyword order by k.keyword separator \',\') as keywords from sources s join users u on u.id = s.author_id left join sources_keywords_v2 sk on sk.source_id = s.id left join keywords k on k.id = sk.keyword_id where s.id = ? group by s.id, s.url, s.title, s.author_id, s.comment, s.content_md, s.state, s.updated_date, u.name limit 1');
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare fetch source: ' . $this->conn->error);
        }
        $stmt->bind_param('i', $source_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $source = null;
        if ($result instanceof \mysqli_result) {
            $source = $result->fetch_assoc() ?: null;
            $result->free();
        }
        $stmt->close();
        return $source;
    }

    /**
     * Updates title/comment/content_md for a source.
     */
    public function update_source_content(int $source_id, string $title, string $comment, string $content_md): void {
        if (strlen($comment) > self::COMMENT_BYTES_LIMIT) {
            throw new \RuntimeException('Comment exceeds limit.');
        }
        $stmt = $this->conn->prepare('update sources set title = ?, comment = ?, content_md = ?, updated_date = ? where id = ?');
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare update source: ' . $this->conn->error);
        }
        $now = time();
        $stmt->bind_param('sssii', $title, $comment, $content_md, $now, $source_id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to update source: ' . $this->conn->error);
        }
        $stmt->close();
    }

    /**
     * Returns:
     * [{"id": ..., "url": "...", "title": "...", "comment": "...", "state": ..., "author_id": ..., "author_name": "..."}, ...]
     */
    public function get_sources(int $author_id, SourceState $state): array {
        $stmt = $this->conn->prepare('select s.id, s.url, s.title, s.comment, s.state, s.author_id, s.updated_date, u.name as author_name, group_concat(distinct k.keyword order by k.keyword separator \',\') as keywords from sources s join users u on u.id = s.author_id left join sources_keywords_v2 sk on sk.source_id = s.id left join keywords k on k.id = sk.keyword_id where s.author_id = ? and s.state = ? group by s.id, s.url, s.title, s.comment, s.state, s.author_id, s.updated_date, u.name order by s.id desc limit 1000');
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
        $sql = "select s.id, s.url, s.title, s.comment, s.state, s.author_id, s.updated_date, u.name as author_name,
                group_concat(distinct k_all.keyword order by k_all.keyword separator ',') as keywords,
                group_concat(distinct k_match.keyword order by k_match.keyword separator ',') as matched_keywords,
                count(distinct k_match.keyword) as match_count
            from sources s
            join users u on u.id = s.author_id
            join sources_keywords_v2 sk_match on sk_match.source_id = s.id
            join keywords k_match on k_match.id = sk_match.keyword_id
            left join sources_keywords_v2 sk_all on sk_all.source_id = s.id
            left join keywords k_all on k_all.id = sk_all.keyword_id
            where k_match.keyword in ($placeholders) and s.state = ?
            group by s.id, s.url, s.title, s.comment, s.state, s.author_id, s.updated_date, u.name
            order by match_count desc, s.id desc
            limit 1000";
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
                    'content' => 'Extract the most important short keywords (single semantic words) that best represent the given search query. Prefer the most common normalized forms. For proper nouns, whenever feasible, include both Japanese and English variants. Include both English and Japanese forms where applicable. When a broader term for something exists (e.g. Europe for EU), please also include that in the list. Avoid too generic terms (news, report, etc.). Return an expanded keywords list, without making up concepts. Return only the JSON object matching the schema.',
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
        $result = $this->conn->query('select k.keyword, count(distinct sk.source_id) as count from sources_keywords_v2 sk join keywords k on k.id = sk.keyword_id join sources s on s.id = sk.source_id where s.state >= 0 group by k.keyword order by count desc, k.keyword limit 1000');
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
            join sources_keywords_v2 sk on sk.source_id = s.id
            join keywords k on k.id = sk.keyword_id
            where k.keyword = ?
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

    /**
     * Returns:
     * ["working" => ..., "done" => ..., "aborted" => ...]
     */
    public function get_user_state_counts(int $author_id): array {
        $sql = 'select state, count(*) as count from sources where author_id = ? group by state';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare user state counts: ' . $this->conn->error);
        }
        $stmt->bind_param('i', $author_id);
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
        $stmt = $this->conn->prepare('update sources set state = ?, updated_date = ? where id = ?');
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare update source state: ' . $this->conn->error);
        }
        $state_value = $state->value;
        $now = time();
        $stmt->bind_param('iii', $state_value, $now, $source_id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to update source state: ' . $this->conn->error);
        }
        $stmt->close();
    }
}
