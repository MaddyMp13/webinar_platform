# Local webinar dashboard

Open **http://localhost/Task/dashboard.html**. The library and every webinar page also have a Dashboard link.

The first visit asks you to create your own local admin account: name, email, and a password of at least 12 characters. There is no default password. Setup closes after the first admin is created. Existing admins sign in with their own credentials. The page shell is public, but personal data, reports, exports, and activity details require an authenticated admin session.

## The three views

- **Registrations:** name, email, phone, company, job title, country, selected webinar, registration date, marketing/activity consent, and counts of associated viewing sessions and conversations.
- **Video activity:** viewer, webinar, observed watch time, last recorded position, furthest playback progress, session status, and a chronological event timeline. Events include start, playing, pause, resume, seeking, periodic progress, stop, completion, and session end.
- **Chatbot activity:** conversation owner, webinar, question/response counts, last update, chronological saved messages, and transcript references.

Search by contact name, email, or company. Filter by webinar and IST calendar dates; video sessions can also be filtered by status. Results are paginated server-side. Refresh manually or every 30 seconds while the dashboard is open. CSV export applies the same filters, exports all matching rows up to 50,000, and neutralizes spreadsheet formulas in text fields. Chat CSV exports individual messages, model/token fields, and source quotes, up to 50,000 messages. Identity searches are sent in a POST body and are not added to the URL.

## Start collecting activity

1. Open a webinar and register, or choose **Edit registration** on an existing registration.
2. Check **Save my video engagement and transcript-chat history for this webinar** and submit. This is separate from marketing consent and optional; video access still works without it.
3. Play the on-page video, pause, resume, or seek. Open the dashboard’s Video activity view to inspect recorded events. Opening only the raw/direct MP4 link does not run the on-page event collector.
4. Choose **Manage transcripts** in the dashboard sidebar. Add authorized TXT/VTT/SRT captions matching the webinar’s recording and start local Ollama. The assistant saves validated questions, responses, model/token counts, and source quotes in the normalized chat tables.

Existing registrations default to activity consent off. Their earlier video/chat activity cannot be reconstructed. The first schema migration timestamp marks when tracking became available. Marketing consent is not required for activity consent or viewing.

## Transcript assistant capability

The assistant retrieves ranked passages from the selected webinar and uses the configured local Ollama model to answer. Citation IDs and exact supporting quotes are checked before saving. When there is insufficient evidence, it says so. Missing transcripts, an offline provider, invalid model output, and generation limits produce explicit status/errors. Existing history and transcript reading remain accessible when the provider is offline. Excerpt mode is available only when intentionally selected in PHP configuration and is labelled in the UI.

Ollama and `qwen2.5:1.5b` are installed on this machine. No API key is needed. Actual webinar transcripts still need to be uploaded; no transcript is fabricated from a title or unrelated sample footage. Transcript reading/search, sourced summaries/key takeaways/topics/actions, new conversations and saved history are implemented. See CHATBOT-SETUP.md for setup and limits. Summary generation does not increment the dashboard’s saved-question metric.

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
- `api/chat.php` / `chat.js`: persistent, sourced conversations scoped to the selected registration/webinar, history selection and transcript/summary tabs.
- `api/transcripts.php`, `api/assistant.php`, `transcripts.html/js/css`: authenticated imports, caption processing, local model integration, retrieval, evidence validation and cached summaries.
- `tools/migrate-dashboard.php`: additive migration `002_activity_dashboard`; preserves existing data.

The schema now has 19 tables, including `admin_sessions`, `webinar_transcript_documents` and `webinar_insights`. Migration 002 adds activity consent, playback duration/last-event fields, credited event intervals, and a unique chat-request UUID. Migration 003 adds the chatbot metadata/cache tables and saved source quotes. Run both on an older copy:

```powershell
cd 'C:\xampp\htdocs\Task'
& 'C:\xampp\php\php.exe' '.\tools\migrate-dashboard.php'
& 'C:\xampp\php\php.exe' '.\tools\migrate-chatbot.php'
```

Fresh `database/webinar_platform.sql` imports include the updated schema. `tools/setup-database.php` also runs the additive migrations. On this machine, migrations 002 and 003 are applied. Code is synchronized to the Desktop project and XAMPP app. Existing Node/SQLite files remain legacy references.

## Validation

With XAMPP running, the API/database suite needs only Node:

```powershell
node --test tests/php-workflow.test.js
```

DOM integration and player-sampling tests use development-only jsdom:

```powershell
npm install --prefix .qa --no-audit --no-fund --ignore-scripts --package-lock=false jsdom@26.1.0
node --test tests/dashboard.test.js tests/form.test.js
node --test tests/chatbot.test.js tests/ollama-contract.test.js
C:\xampp\php\php.exe tests/chatbot-unit.php
```

Tests use uniquely named temporary QA contacts/admins and a temporary transcript fixture, then remove them. The dashboard tests verify first-time setup/login, unauthorized access, consent, watch-time exclusion for seeks/pauses, duplicate/out-of-order events, report filters, CSV safety, persistent sourced chat, conversation/event drill-downs, logout, and deletion. No test records remain in the delivered dashboard.

PHP/JavaScript syntax, Apache HTTP responses, database reconciliation, and DOM/player logic were checked. A live browser visual review and actual media decoding were not performed because browser preview access had previously been declined.

Local chatbot setup: see [CHATBOT-SETUP.md](CHATBOT-SETUP.md). The dashboard sidebar links to the authenticated transcript manager at http://localhost/Task/transcripts.html. The PHP configuration now selects local Ollama; the manager reports actual connection and model availability.
