# Local XAMPP setup

The current backend is **PHP + MySQL/MariaDB**. The interface remains HTML, CSS, and JavaScript. Node is not needed to run this version.

The app has been copied to `C:\xampp\htdocs\Task`. Its source copy is also in `C:\Users\mandar.patil\Desktop\Task`.

## Start and open

1. Open the XAMPP Control Panel and start **Apache** and **MySQL**. If they already show running, leave them running.
2. Open [the app](http://localhost/Task/).
3. Open [phpMyAdmin](http://localhost/phpmyadmin/) to inspect the `webinar_platform` database.

For this machine, the database and six campaigns have already been imported and tested. There is no need to re-import to start using it.

## Import on a fresh XAMPP installation

1. Copy the app to `C:\xampp\htdocs\Task`, including `api`, `config`, `videos`, and the root `.htaccess` file.
2. Start Apache and MySQL.
3. Open phpMyAdmin, choose **Import**, and select `database/webinar_platform.sql`. This single file creates the database, all 21 tables, and six original campaigns. It seeds no leads, transcript content, or admin passwords.
4. Check the connection values in `config/config.php`. Defaults are host `127.0.0.1`, port `3306`, database `webinar_platform`, user `root`, and a blank password for a default local XAMPP install. If your MySQL has a password, copy `config/config.local.example.php` to `config/config.local.php` and put it there. Keep credentials out of frontend JavaScript.
5. Open `http://localhost/Task/` through Apache. Opening the HTML file directly does not execute PHP.

Alternatively, import from PowerShell:

```powershell
cd 'C:\xampp\htdocs\Task'
& 'C:\xampp\php\php.exe' '.\tools\setup-database.php'
```

`database/schema.sql` and `database/seed.sql` are also provided separately. The combined SQL is their concatenation; import either the combined file or those two files in that order. Imports use `CREATE TABLE IF NOT EXISTS` and `INSERT IGNORE`, preserving existing rows. Future schema changes require versioned migrations; re-importing this initial schema does not alter an existing table’s structure.

## Tables and relationships

| Area | Tables | Purpose |
| --- | --- | --- |
| Campaigns | `webinars`, `webinar_assets` | Campaign details and the correct protected recording/document |
| Leads | `leads`, `lead_webinars` | One contact per email, separate registration for each webinar |
| Consent | `consent_events` | Privacy acceptance and changes to optional marketing preferences |
| Access | `visitor_sessions`, `session_registrations` | Hashed session tokens, CSRF tokens, campaign-specific access |
| Video analytics | `playback_sessions`, `playback_events` | Separate viewing sessions and deduplicated playback events |
| Transcripts | `webinar_transcripts`, `webinar_transcript_documents`, `webinar_insights` | Timestamped chunks, authorized import metadata, and cached sourced summaries |
| Speech recognition | `transcription_jobs`, `transcription_worker_state` | Durable faster-whisper jobs, source fingerprints, progress, and worker status |
| Chat | `chat_conversations`, `chat_messages`, `chat_message_sources` | Conversation history and normalized transcript references |
| Administration | `admin_users`, `admin_sessions` | Admin password hashes and private dashboard sessions; no default password |
| Operations | `schema_migrations`, `api_rate_limits` | Schema version and shared write rate limits |

The registration foreign key links a lead to a webinar. Playback sessions link to that registration. Events link to playback sessions. Conversations link to the webinar and registration; messages and sources have their own tables. Foreign keys, indexes, and uniqueness constraints are included. Database timestamps use UTC; country is an ISO two-letter code.

Implemented now: database campaign pages, related webinars, validated registration, separate marketing/activity consent, protected sample videos, observed playback tracking, local Ollama chat, transcript upload/search, sourced summaries and saved history, and the private three-view admin dashboard. See **DASHBOARD.md** and **CHATBOT-SETUP.md**. Each recording still needs an authorized matching transcript; no transcript or chatbot records are fabricated.

## PHP files

| File | Responsibility |
| --- | --- |
| `config/config.php` | Local database settings and app configuration |
| `api/db.php` | PDO connection with prepared statements, UTF-8, UTC, and exceptions |
| `api/index.php` | API routing and safe JSON error handling |
| `api/bootstrap.php` | Cookies, CSRF/origin checks, rate limiting, retention cleanup |
| `api/validation.php` | Server-side field/consent validation |
| `api/webinars.php` | Fetch campaigns and related webinars from MySQL |
| `api/registration.php` | Transactional lead/registration/consent writes and removal |
| `api/video.php` | Authorization, local-file confinement, and MP4 range streaming |
| `backend.js` | Frontend API client; supports a folder such as `/Task/` |

Example endpoints:

```text
GET    /Task/api/index.php?resource=webinars
GET    /Task/api/index.php?resource=webinar&slug=ai-data-resilience
GET    /Task/api/index.php?resource=registration&webinar_id=1
POST   /Task/api/index.php?resource=registration&webinar_id=1
DELETE /Task/api/index.php?resource=registration&webinar_id=1
GET    /Task/api/index.php?resource=video&webinar_id=1
```

Registration GET returns the browser session’s registration or null and a CSRF token. The frontend automatically includes that token for POST/DELETE. Writes require a matching Origin header and are limited to 30 attempts per minute per IP. Personal details are sent as JSON, never added to a URL. Case-insensitive email uniqueness and the `(lead_id, webinar_id)` unique key prevent duplicate lead/registration rows.

The session cookie is HttpOnly, SameSite=Lax, scoped to the app folder, and valid for up to 30 days. The database stores a hash of its access token. The root `.htaccess` uses a public-file allowlist; configuration, SQL, raw video files, SQLite files, backups, and tests cannot be downloaded through Apache. Keep that file when copying the app.

Retention cleanup runs during registration/video API requests: expired sessions are deleted and registrations inactive for 90 days are removed with related records. Orphan contact records are also removed. With no visitors, cleanup runs on the next such API request. Removing a registration through the UI immediately revokes its access.

## Check the workflow

1. Open a campaign and try missing fields, an invalid email/phone, and unchecked privacy consent.
2. Submit valid details with marketing unchecked. Inspect `leads`, `lead_webinars`, and `consent_events` in phpMyAdmin.
3. Play and seek the sample video; use the direct video link if desired.
4. Reload: access should be remembered. Open another campaign: it should need its own registration.
5. Edit preferences and submit the same email: the registration should update rather than duplicate.
6. Remove the registration: video access should be revoked and the lead deleted if no other registrations remain.

Automated API/database tests, while Apache/MySQL are running:

```powershell
node --test tests/php-workflow.test.js
```

Optional DOM/form tests (Node is only needed for tests):

```powershell
npm install --prefix .qa --no-audit --no-fund --ignore-scripts --package-lock=false jsdom@26.1.0
node --test tests/form.test.js
```

The older `server.js`, `video-library.js`, SQLite files, and `tests/registration.test.js` are retained in the Desktop/workspace as legacy references. The active frontend uses the PHP API. The one existing SQLite registration on this machine was copied into MySQL, including its consent choices and timestamps. The source SQLite file was preserved. Old Node browser sessions do not carry over; registering again with the same email updates the migrated registration.

For another legacy SQLite file, use the optional CLI migration. It skips already-existing registrations and does not overwrite newer MySQL details:

```powershell
& 'C:\xampp\php\php.exe' '.\tools\import-sqlite.php' 'C:\Users\mandar.patil\Desktop\Task\.data\webinars.sqlite'
```

This setup runs locally on XAMPP with Ollama. It sends no marketing emails. For public deployment, configure the hosting environment and access controls separately; the local Ollama address is available only on the machine running PHP.

Local chatbot setup: see [CHATBOT-SETUP.md](CHATBOT-SETUP.md). The dashboard sidebar links to the authenticated transcript manager at http://localhost/Task/transcripts.html. The PHP configuration now selects local Ollama; the manager reports actual connection and model availability.

Automatic transcription: [FASTER-WHISPER.md](FASTER-WHISPER.md). The transcript manager provides Generate from video, background job progress, cancellation, and review/edit controls. Speech recognition uses local faster-whisper; the existing Ollama integration uses the saved captions for answers and summaries.
