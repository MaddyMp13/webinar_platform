# Automatic local transcription

Open **http://localhost/Task/transcripts.html** and sign in using your existing dashboard administrator account.

1. In **Automatic transcription**, select a webinar’s recording and its spoken language, or use automatic detection.
2. Confirm permission to transcribe that recording. If a transcript already exists, confirm its replacement when the new job completes.
3. Click **Generate from video**. The local background worker reads the selected recording, recognizes speech, creates timestamped captions and saves transcript chunks in MySQL. The chatbot then uses those chunks. The job continues if the browser closes; reload a webinar page to pick up newly available content.
4. Review the result against the video. Under **Add or replace a transcript**, select the webinar and choose **Load current transcript for editing**. Make corrections and save. Important names and technical terms may need correction. Source labels and the transcript viewer identify automatically generated captions.

Transcribing a sample video produces that sample’s actual speech. To transcribe an original webinar, configure its real authorized recording first. Nothing is inferred from the campaign title, description, or artwork.

## Installed local runtime

The web app remains HTML, CSS, JavaScript and PHP on XAMPP. A separate Python worker runs speech recognition using [faster-whisper](https://github.com/SYSTRAN/faster-whisper). This machine uses the multilingual `base` model, CPU execution, INT8 and two CPU threads. PyAV supplies the audio decoder, so a separate FFmpeg command is not required. The decoder version is pinned to match faster-whisper’s API.

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
