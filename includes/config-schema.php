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
}
