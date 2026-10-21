<?php
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'webinar_platform',
    'db_user' => 'root',
    'db_password' => '', // Local XAMPP default only; change if your MySQL has a password.
    'chat_provider' => 'ollama',
    'ollama_url' => 'http://127.0.0.1:11434',
    'ollama_model' => 'qwen2.5:1.5b', // Must appear in `ollama list`.
    'whisper_model' => 'base', // tiny, base, small, medium, or large-v3. Download using setup-transcription.ps1.
    'whisper_cpu_threads' => 2,
];
