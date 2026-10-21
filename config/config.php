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
    'ollama_url' => getenv('WEBINAR_OLLAMA_URL') ?: 'http://187.127.162.182:32769',
    'ollama_allowed_hosts' => ['127.0.0.1', 'localhost', '::1'], // Override explicitly for a remote inference server.
    'ollama_allow_insecure_http' => false, // Keep false for remote hosts unless the network path is trusted and restricted.
    'ollama_bearer_token' => getenv('WEBINAR_OLLAMA_TOKEN') ?: '', // Used only when a reverse proxy enforces bearer authentication.
    'ollama_model' => getenv('WEBINAR_OLLAMA_MODEL') ?: 'qwen2.5:1.5b',
    'ollama_timeout' => 180,
    'ollama_context_tokens' => 8192,
    'ollama_output_tokens' => 1200,
    'whisper_model' => 'base', // Multilingual; CPU / INT8 for this local machine.
    'whisper_cpu_threads' => 2,
    'whisper_max_duration_seconds' => 14400,
    'whisper_max_video_bytes' => 4294967296,
    'transcription_provider' => 'local', // 'local' or 'remote_api'.
    'transcription_api_url' => '',
    'transcription_api_allowed_hosts' => [],
    'transcription_api_key_header' => 'X-API-Key',
    'transcription_api_key' => getenv('WEBINAR_TRANSCRIPTION_API_KEY') ?: '',
    'transcription_api_upload_timeout' => 300,
    'transcription_model_label' => 'faster-whisper-vps',
    'video_directory' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'videos',
];
if (is_file(__DIR__ . '/config.local.php')) {
    $overrides = require __DIR__ . '/config.local.php';
    if (!is_array($overrides)) throw new RuntimeException('Invalid local configuration.');
    $config = array_replace($config, $overrides);
}
// Deployment-specific AI settings are separate so an upload never replaces database credentials.
if (is_file(__DIR__ . '/ollama.vps.php')) {
    $overrides = require __DIR__ . '/ollama.vps.php';
    if (!is_array($overrides)) throw new RuntimeException('Invalid Ollama VPS configuration.');
    $config = array_replace($config, $overrides);
}
if (is_file(__DIR__ . '/transcription.vps.php')) {
    $overrides = require __DIR__ . '/transcription.vps.php';
    if (!is_array($overrides)) throw new RuntimeException('Invalid transcription VPS configuration.');
    $config = array_replace($config, $overrides);
}
return $config;
