# Local webinar dashboard

Open **http://localhost/Task/dashboard.html**. The library and every webinar page also have a Dashboard link.

The first visit asks you to create your own local admin account: name, email, and a password of at least 12 characters. There is no default password. Setup closes after the first admin is created. Existing admins sign in with their own credentials. The page shell is public, but personal data, reports, exports, and activity details require an authenticated admin session.

## The three views

- **Registrations:** name, email, phone, company, job title, country, selected webinar, registration date, marketing/activity consent, and counts of associated viewing sessions and conversations.
- **Video activity:** viewer, webinar, observed watch time, last recorded position, furthest playback progress, session status, and a chronological event timeline. Events include start, playing, pause, resume, seeking, periodic progress, stop, completion, and session end.
- **Chatbot activity:** conversation owner, webinar, question/response counts, last update, chronological saved messages, and transcript references.

Search by contact name, email, or company. Filter by webinar and IST calendar dates; video sessions can also be filtered by status. Results are paginated server-side. Refresh manually or every 30 seconds while the dashboard is open. CSV export applies the same filters, exports all matching rows up to 50,000, and neutralizes spreadsheet formulas in text fields. Identity searches are sent in a POST body and are not added to the URL.

## Start collecting activity

1. Open a webinar and register, or choose **Edit registration** on an existing registration.
2. Check **Save my video engagement and transcript-chat history for this webinar** and submit. This is separate from marketing consent and optional; video access still works without it.
3. Play the on-page video, pause, resume, or seek. Open the dashboard’s Video activity view to inspect recorded events. Opening only the raw/direct MP4 link does not run the on-page event collector.
4. For chat, add an authorized transcript to that webinar’s `webinar_transcripts` table. The transcript assistant enables questions when real chunks are available. It saves each question, response, and cited source to the existing normalized chat tables.

Existing registrations default to activity consent off. Their earlier video/chat activity cannot be reconstructed. The first schema migration timestamp marks when tracking became available. Marketing consent is not required for activity consent or viewing.

## Transcript assistant capability

The current assistant searches this webinar’s stored transcript and returns matching excerpts with source references. This is extractive transcript retrieval, not an AI-generated answer. When there is no matching evidence, it says that the transcript contains insufficient information. When no transcript is configured, the question form is disabled with a clear status.

No AI provider, API key, transcription service, fabricated transcript, or invented conversation is configured. An AI-generation integration and an admin transcript-upload interface remain later enhancements. Authorized transcript chunks can currently be imported with SQL/phpMyAdmin using `webinar_id`, `chunk_index`, `chunk_text`, and optional source/timestamps.

## Metric definitions and limits

| Metric | Definition / source |
| --- | --- |
| Registered users | Distinct `leads.id` among filtered `lead_webinars` records |
| Registrations | One `(lead_id, webinar_id)` row; filtered by registration date |
| Observed watch time | Sum of sampled real playing intervals saved in `playback_sessions.watched_seconds`; pauses, buffering, seek jumps, and unobserved suspended gaps do not add time; repeat viewing adds time |
| Viewing sessions | Player sessions created on first actual playing event, after activity consent; filtered by session start |
| Progress | Furthest observed position / duration, including seeking; this is not unique content watched |
| Reached end | Native player completion event recorded for that viewing session; seeking near the end can also reach the end |
| Inactive | A session last recorded as playing/created that has not updated for 45 seconds; inferred at report time |
| Conversations | Saved `chat_conversations` rows, filtered by conversation creation date |
| Questions | Saved user-role `chat_messages` belonging to the filtered conversations |

Events use unique UUIDs, so retries do not add watch time twice. The server caps credited intervals, verifies registration ownership/consent, and does not let out-of-order events regress the last position. Player measurements are observational client telemetry, not independently verified or billing-grade measurements. Batches are sent every five seconds and on key events. A page-hide keepalive is attempted, but exact browser-close time and delivery after crashes or loss of connectivity cannot be guaranteed. The last successfully saved position is authoritative. The retry queue is bounded and lives only in the open page.

Dates are stored in UTC and displayed in Asia/Kolkata. A global date filter applies independently to registration time, playback-session start, or conversation start for the respective metric. Registration and conversation counts are not interchangeable with unique contacts. No historical tracking data is inferred from registrations alone.

Switching activity consent off stops future collection but preserves prior consented records. Removing the registration deletes its associated playback/chat records. Retention cleanup removes registrations after 90 days without registration changes or recorded video/chat activity, with the existing cascade/deletion policy.

## Backend and schema changes

- `dashboard.html`, `dashboard.css`, `dashboard.js`: separate dashboard, sign-in/setup, filters, tables, details, and exports.
- `api/admin.php`: password hashing, first local admin setup, eight-hour HttpOnly admin sessions, CSRF/origin checks, and sign-out.
- `api/dashboard.php`: prepared SQL report/filter queries, distinct counts, CSV, event detail, and conversation detail.
- `api/playback.php` / `playback.js`: consented playback sessions, validated event ingestion, idempotency, and sampling.
- `api/chat.php` / `chat.js`: persistent transcript-excerpt conversations scoped to the selected registration/webinar.
- `tools/migrate-dashboard.php`: additive migration `002_activity_dashboard`; preserves existing data.

The schema now has 17 tables, including `admin_sessions`. The migration adds separate activity consent, playback duration/last-event fields, credited event intervals, and a unique chat-request UUID. Run it on an older copy:

```powershell
cd 'C:\xampp\htdocs\Task'
& 'C:\xampp\php\php.exe' '.\tools\migrate-dashboard.php'
```

Fresh `database/webinar_platform.sql` imports include the updated schema. `tools/setup-database.php` also runs the additive migration. On this machine, migration 002 is already applied. Code is synchronized to the Desktop project and XAMPP app. Existing Node/SQLite files remain legacy references.

## Validation

With XAMPP running, the API/database suite needs only Node:

```powershell
node --test tests/php-workflow.test.js
```

DOM integration and player-sampling tests use development-only jsdom:

```powershell
npm install --prefix .qa --no-audit --no-fund --ignore-scripts --package-lock=false jsdom@26.1.0
node --test tests/dashboard.test.js tests/form.test.js
```

Tests use uniquely named temporary QA contacts/admins and a temporary transcript fixture, then remove them. The dashboard tests verify first-time setup/login, unauthorized access, consent, watch-time exclusion for seeks/pauses, duplicate/out-of-order events, report filters, CSV safety, persistent sourced chat, conversation/event drill-downs, logout, and deletion. No test records remain in the delivered dashboard.

PHP/JavaScript syntax, Apache HTTP responses, database reconciliation, and DOM/player logic were checked. A live browser visual review and actual media decoding were not performed because browser preview access had previously been declined.
