<?php

declare(strict_types=1);

// Active remote faster-whisper connection. Keep the API key in config.local.php
// or the WEBINAR_TRANSCRIPTION_API_KEY environment variable, never in this file.
return [
    'transcription_provider' => 'remote_api',
    'transcription_api_url' => 'https://transcribe.arkentechsolutions.com',
    'transcription_api_allowed_hosts' => ['transcribe.arkentechsolutions.com'],
    'transcription_api_upload_timeout' => 300,
    'transcription_model_label' => 'faster-whisper-vps',
];
