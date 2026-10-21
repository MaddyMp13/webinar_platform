# Automatic faster-whisper transcription

The hosted application uses `https://transcribe.arkentechsolutions.com` through the server-side adapter in `api/transcription.php`. The browser never receives the API key. The application uploads the selected private recording, stores the returned job ID, polls the job status from the authenticated transcript manager, validates the result, and saves timestamped transcript chunks automatically. The chatbot becomes available as soon as that transcript is saved.

Add the API key to the hosted site's protected `config/config.local.php`:

```php
'transcription_api_key' => 'YOUR_API_KEY',
```

The configured default header is `X-API-Key`. If the VPS expects another header, set `transcription_api_key_header` in the same protected file. Do not place the key in JavaScript, HTML, `config/transcription.vps.php`, or an upload archive.

The API's current OpenAPI schema accepts only the multipart `file` field. It does not expose language selection or a translation task, so the hosted workflow uses automatic spoken-language detection. Adding translation to English requires the VPS API to accept a parameter such as `task=translate` or `target_language=en` and return it in the documented schema.

After a visitor registers, the webinar page opens the right-side assistant. If the selected webinar has no transcript, PHP uploads that webinar's private recording to the VPS once, stores the job in `transcription_jobs`, and polls it from the drawer. When Faster-Whisper finishes, the drawer unlocks transcript search and download, asks Ollama for the source-backed summary, and enables the question field. Closing the drawer does not cancel the server job; reopening it resumes status checks.

Open the hosted `transcripts.html` page and sign in using your existing dashboard administrator account.

1. In **Automatic transcription**, select a webinar’s recording and its spoken language, or use automatic detection.
2. Confirm permission to transcribe that recording. If a transcript already exists, confirm its replacement when the new job completes.
3. Click **Generate from video**. PHP uploads the selected recording to the faster-whisper VPS. The transcript manager polls the returned job and saves transcript chunks in MySQL when processing completes. Reload a webinar page to pick up newly available content.
4. Review the result against the video. Under **Add or replace a transcript**, select the webinar and choose **Load current transcript for editing**. Make corrections and save. Important names and technical terms may need correction. Source labels and the transcript viewer identify automatically generated captions.

Transcribing a sample video produces that sample’s actual speech. To transcribe an original webinar, configure its real authorized recording first. Nothing is inferred from the campaign title, description, or artwork.

## Optional local runtime

The local Python worker remains available for XAMPP development by removing `config/transcription.vps.php` or changing `transcription_provider` back to `local`. It runs speech recognition using [faster-whisper](https://github.com/SYSTRAN/faster-whisper) with the multilingual `base` model, CPU execution and INT8.

Runtime and downloaded model files are outside the website, at:

```text
C:\Users\mandar.patil\.webinar-transcriber\
  venv\
  models\
  worker.log
```

No cloud speech service or API key is used. Model installation downloads public model weights; transcription uses the local cache with `local_files_only=True`. The worker receives only the configured recording path and job metadata. Registration contact details are not passed to it. It opens no HTTP listening port.

## Starting after reboot

Start XAMPP MySQL and Apache, then run:

```powershell
cd 'C:\xampp\htdocs\Task'
powershell -NoProfile -ExecutionPolicy Bypass -File tools/start-transcription.ps1
```

The worker runs in the background. Its live status appears in the transcript manager. Queued jobs remain in the database while it is offline. Ollama is still needed for generated answers and summaries; faster-whisper supplies the transcript.

For installation on another computer with Python 3.9 or newer:

```powershell
cd 'C:\xampp\htdocs\Task'
powershell -NoProfile -ExecutionPolicy Bypass -File tools/setup-transcription.ps1
```

The setup installs the isolated Python dependencies, downloads the `base` model, applies the queue migration and starts the worker. To use another supported model, prepare it and set the same `whisper_model` in `config/config.local.php`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/setup-transcription.ps1 -Model small
```

Models supported by this integration: `tiny`, `base`, `small`, `medium`, `large-v3`, `turbo`. Larger models need more processing time and memory. The current configuration deliberately uses CPU/INT8 on this computer.

## Database and processing

Run `C:\xampp\php\php.exe tools/migrate-transcription.php` on an existing installation, or import `database/transcription.sql` in phpMyAdmin. A fresh `database/webinar_platform.sql` includes all 21 tables. Existing registrations, admins, conversations and transcripts are preserved.

| Table | Purpose |
| --- | --- |
| `transcription_jobs` | Webinar/asset/admin association, queue status, language, model, source fingerprints, progress, timestamps and errors |
| `transcription_worker_state` | Worker heartbeat and package version for the admin status display |

Completed jobs use the existing transactional transcript importer. Source references include the speech engine/model and recording name. Replacing chunks clears cached summaries while retaining old conversation text and quoted evidence. The worker checks the source asset/path, file size, modification time and SHA-256; if the video changed, it refuses to publish. If someone edits the transcript while recognition runs, that edit is preserved and the job reports a conflict. A stale editing form is also rejected rather than overwriting a newer version.

The Python worker talks to a private PHP CLI bridge through JSON on standard input/output. PHP reuses the existing PDO configuration; database passwords are not copied into Python or placed in command arguments. HTTP queue/status/cancel actions require admin authentication; writes require CSRF and same-origin checks. Job lease tokens and private file paths are never returned by the admin HTTP status API. Media must resolve inside the configured private video directory; remote URLs and arbitrary file paths are rejected.

## Limits and errors

- One recording is processed at a time. Queue submissions are rate limited; an active job for the same webinar is reused.
- Default input limits are 4 GB and four hours; the transcript importer also caps VTT/text at 120,000 characters. A result over that cap fails with a request to split the session. It is never silently truncated.
- Voice activity detection removes silence. Missing audio, no recognized speech, decoder/model errors and source changes leave the previous transcript intact.
- Progress follows the last recognized speech position. Model loading and audio decoding can take time before it advances.
- **Cancel job** prevents publishing the result. An inference step already running may finish before the worker notices cancellation. Completed jobs cannot be cancelled.
- A job without a heartbeat for three minutes is marked interrupted when the worker next checks the queue; retry it from the manager. Queued jobs survive restarts.
- Recordings and transcript text are not written to the worker log. The log contains job IDs, processing metadata and diagnostic exceptions.

## Verification

```powershell
python -m unittest tests/transcription_unit.py
node --test tests/transcription.test.js
```

The live integration test generates a temporary synthetic speech recording, passes it through real faster-whisper, checks MySQL timestamps/provenance and chatbot citations, and checks queue permissions, idempotency, cancellation, review/edit controls and conflict handling. It deletes only its uniquely named QA records and media. Existing app tests cover registration, protected videos, chatbot history and the admin dashboard. DOM tests do not replace browser rendering or media-decoding review.
