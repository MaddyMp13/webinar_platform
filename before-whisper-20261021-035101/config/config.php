<?php
declare(strict_types=1);

// Local XAMPP defaults. Never put database credentials in frontend JavaScript.
// config.local.php overrides these values and is excluded from Git.
$config = [
    'db_host' => getenv('WEBINAR_DB_HOST') ?: '127.0.0.1',
    'db_port' => (int) (getenv('WEBINAR_DB_PORT') ?: 3306),
    'db_name' => getenv('WEBINAR_DB_NAME') ?: 'webinar_platform',
    'db_user' => getenv('WEBINAR_DB_USER') ?: 'root',
    'db_password' => getenv('WEBINAR_DB_PASSWORD') ?: '',
    'allowed_hosts' => ['localhost', '127.0.0.1'],
    'cookie_secure' => false, // Set true when serving through HTTPS.
    'session_days' => 30,
    'retention_days' => 90,
    'chat_provider' => 'ollama', // 'excerpts' explicitly disables AI generation.
    'ollama_url' => 'http://127.0.0.1:11434', // Loopback only; never supplied by a visitor.
    'ollama_model' => getenv('WEBINAR_OLLAMA_MODEL') ?: 'qwen2.5:1.5b',
    'ollama_timeout' => 180,
    'ollama_context_tokens' => 8192,
    'ollama_output_tokens' => 1200,
    'video_directory' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'videos',
];
if (is_file(__DIR__ . '/config.local.php')) {
    $overrides = require __DIR__ . '/config.local.php';
    if (!is_array($overrides)) throw new RuntimeException('Invalid local configuration.');
    $config = array_replace($config, $overrides);
}
return $config;
