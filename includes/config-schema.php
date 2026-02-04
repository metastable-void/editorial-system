<?php

namespace innovatopia_jp\editorial;

// このファイルは編集すべき設定ファイルではない。 config.php を編集のこと。

class MysqlConfig {
    public string $host = '127.0.0.1';
    public string $user;
    public string $pass;
    public string $db;
}

class LoginConfig {
    public string $username;
    public string $password; // TODO: Hash with sane config
}

class WordPressConfig {
    public string $url;
}

class FirecrawlConfig {
    public string $api_key;

    public function scrape(string $url): FirecrawlResult {
        $payload = json_encode([
            'url' => $url,
            'formats' => ['markdown'],
            'onlyMainContent' => true,
            'excludeTags' => ['img'],
        ], \JSON_UNESCAPED_UNICODE);
        $ch = curl_init('https://api.firecrawl.dev/v2/scrape');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_key,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            $ch = null;
            throw new \RuntimeException('Firecrawl request failed: ' . $error);
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Failed to decode Firecrawl response.');
        }
        if (!key_exists('success', $decoded)) {
            throw new \RuntimeException('Invalid Firecrawl response.');
        }
        if ($decoded['success'] !== true) {
            throw new \RuntimeException('Error Firecrawl response.');
        }
        if (!key_exists('data', $decoded)) {
            throw new \RuntimeException('Invalid Firecrawl response.');
        }
        $data = $decoded['data'];
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid Firecrawl response data.');
        }
        
        if (!key_exists('markdown', $data)) {
            throw new \RuntimeException('Firecrawl response missing markdown result.');
        }
        $full_content = (string) ($data['markdown'] ?? '');
        $has_content = string_strip($full_content) != '';
        if (!key_exists('metadata', $data)) {
            throw new \RuntimeException('Firecrawl response missing metadata result.');
        }
        $metadata = $data['metadata'];
        if (!is_array($metadata)) {
            throw new \RuntimeException('Invalid Firecrawl response metadata.');
        }
        $title = string_strip($metadata['og:title'] ?? $metadata['ogTitle'] ?? $metadata['twitter:title'] ?? $metadata['title'] ?? '');
        $description = string_strip($metadata['og:description'] ?? $metadata['ogDescription'] ?? $metadata['twitter:description'] ?? $metadata['description'] ?? '');
        if ($title == '' && $has_content) {
            $title = preg_match('/^#\s+(.+)$/um', $full_content, $m) ? $m[1] : '';
        }
        if ($description == '' && $has_content) {
            $description = preg_match('/\A.*?(?:^#{1,6}\h+.*\R+)?((?:(?!^(?:#{1,6}\h+|```|~~~|\s*$)).*(?:\R(?!\R)(?!^(?:#{1,6}\h+|```|~~~|\s*$)).*)*)/ums', $full_content, $m)
                ? preg_replace("/\R(?!\R)/u", " ", trim($m[1]))
                : '';
        }

        $res = new FirecrawlResult;
        $res->description = $description;
        $res->full_content = $full_content;
        $res->title = $title;
        return $res;
    }
}

class FirecrawlResult {
    public string $title;
    public string $description;
    public string $full_content;
}

class OpenAiConfig {
    public string $endpoint = 'https://api.openai.com/v1';
    public string $token;

    public function chat_completions(array $data): array {
        $url = rtrim($this->endpoint, '/') . '/chat/completions';
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode request payload.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            $ch = null;
            throw new \RuntimeException('OpenAI request failed: ' . $error);
        }
        $ch = null;
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Failed to decode OpenAI response.');
        }
        return $decoded;
    }
}

abstract class ConfigSchema {
    abstract public static function getMysqlConfig(): MysqlConfig;
    abstract public static function getAdminLogin(): LoginConfig;
    abstract public static function getOpenAiConfig(): OpenAiConfig;
    abstract public static function getWordPressConfig(): WordPressConfig;
    abstract public static function getFirecrawlConfig(): FirecrawlConfig;
}
