<?php
declare(strict_types=1);

// Active Ollama settings for the hosted webinar platform.
// This file intentionally contains no database or visitor credentials.
return [
    'chat_provider' => 'ollama',
    'ollama_url' => 'http://187.127.162.182:32769',
    'ollama_allowed_hosts' => ['187.127.162.182'],
    'ollama_allow_insecure_http' => true,
    'ollama_model' => 'qwen3:1.7b',
    'ollama_timeout' => 180,
    'ollama_context_tokens' => 8192,
    'ollama_output_tokens' => 1200,
];
