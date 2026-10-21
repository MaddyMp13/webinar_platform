<?php
// Copy these entries into the existing protected config/config.local.php on the hosted site.
// Keep the database settings already configured there.
return [
    'allowed_hosts' => ['arkentechpublishing.com', 'www.arkentechpublishing.com'],
    'cookie_secure' => true,
    'chat_provider' => 'ollama',
    'ollama_url' => 'http://187.127.162.182:32769',
    'ollama_allowed_hosts' => ['187.127.162.182'],
    'ollama_allow_insecure_http' => true,
    'ollama_model' => 'qwen3:1.7b',
    'ollama_timeout' => 180,
    'ollama_context_tokens' => 8192,
    'ollama_output_tokens' => 1200,
];
