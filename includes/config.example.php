<?php

// ここには何も追記しないこと
namespace innovatopia_jp\editorial;

if (\defined('EDITORIAL_CONFIG')) {
    return;
}

require_once __DIR__ . '/config-schema.php';

\define('EDITORIAL_CONFIG', 1);

// 設定はこの下に書くこと (PHP8)

class Config extends ConfigSchema {
    public static function getMysqlConfig(): MysqlConfig
    {
        $config = new MysqlConfig();

        // MySQL 設定
        $config->host = '127.0.0.1';
        $config->user = 'user';
        $config->pass = 'password';
        $config->db = 'editorial_db';
        return $config;
    }

    public static function getAdminLogin(): LoginConfig
    {
        $config = new LoginConfig;
        $config->username = 'user';
        $config->password = 'password';
        return $config;
    }

    public static function getOpenAiConfig(): OpenAiConfig
    {
        $config = new OpenAiConfig;
        $config->token = '...';
        return $config;
    }

    public static function getWordPressConfig(): WordPressConfig
    {
        $config = new WordPressConfig;
        $config->url = 'https://innovatopia.jp';
        return $config;
    }

    public static function getFirecrawlConfig(): FirecrawlConfig
    {
        $config = new FirecrawlConfig;
        $config->api_key = '...';
        return $config;
    }
}
