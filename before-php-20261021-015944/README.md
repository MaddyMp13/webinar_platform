# Webinar library — registration and video access

Plain HTML, CSS, and browser JavaScript, with a small JavaScript server using Node’s built-in SQLite. No frontend framework, build step, or npm runtime dependencies.

## Run

Requires Node.js 22.13 or newer; tested with Node 22.20.0. Start the JavaScript server:

```powershell
cd 'C:\Users\mandar.patil\Desktop\Task'
node server.js
```

Visit **http://127.0.0.1:8001**. Keep the server running while using the app; stop it with Ctrl+C. Node may print an experimental SQLite notice; the app still runs. To change ports, set `$env:PORT = '8002'` before starting.

Registration requires this JavaScript server. Opening `index.html` directly or using a static Python server displays campaign pages but cannot save registrations or grant video access. `first.html` remains a compatible library entry point.

## Original project inspection

Source inspected: `C:\Users\mandar.patil\Desktop\Task`.

- `first.html`: listing container, Google logo, inline card styles.
- `script.js`: six campaign records and card rendering; cards linked directly to external publishers.
- `Google-Logo-Tech-Company.png`: existing branding asset.
- No framework, package dependencies, backend, database, authentication, or application routes were present.

Both this workspace and the Desktop project contain the updated app. Backup folders in the Desktop project preserve earlier versions. All six campaign descriptions, image URLs, publisher URLs, IDs, and dates are retained in `campaigns.js`.

## Implemented

- Responsive campaign cards with title, category, description, original artwork, and details links.
- Search across title, description, and category; topic filtering and title sorting.
- Search/filter state retained in the listing URL for reloads and browser back navigation.
- Dynamic landing pages at `webinar.html?slug=ai-data-resilience`, or `webinar.html?id=1`.
- One reusable data model and renderer for all six landing pages.
- Three related campaigns per page, excluding the selected campaign and preferring the same category.
- Invalid/missing webinar state, no-results state, reset controls, image-error fallbacks, focus styles, and keyboard-accessible controls.
- External publisher links are clearly labeled. Speakers, duration, and agendas are omitted because the source records do not contain them.
- Right-hand registration form: first name, last name, email, phone, company, country/region, optional job title, required privacy consent, and optional marketing opt-in.
- Marketing opt-in starts unchecked and is never required for video access. No marketing emails are sent by this prototype.
- Shared browser/server validation, inline errors, privacy dialog, duplicate-submit protection, and clear server errors.
- Real SQLite lead and registration records linked to the selected campaign. Case-insensitive email uniqueness prevents duplicate leads; repeat registrations update the existing lead/webinar entry.
- After submitting, an HTML5 player opens the selected campaign’s local MP4. All six videos are sample footage and labeled as such. Direct video links are also provided.
- HttpOnly session cookie remembers access for up to 30 days. Every video request is checked server-side, including byte ranges for seeking. Registering for one webinar does not unlock another.
- Edit registration/preferences or remove a registration to revoke access. Removing a lead’s final registration also deletes their contact record.

## Files and database

- `registration.js`: form, consent, API integration, and player behavior.
- `registration-rules.js`: shared validation rules.
- `server.js`: file allowlist, registration API, SQLite schema, sessions, and protected video streaming.
- `video-library.js`: server-side video mapping and sample source URLs.
- `videos/sample-1.mp4` through `videos/sample-6.mp4`: six local sample files. They are served only through the protected video endpoint.
- `.data/webinars.sqlite`: automatically created on first start. Contains real contact information; keep it private. `.data/` is ignored by Git and is never served.

The database contains `webinars`, `leads`, `lead_webinars`, `visitor_sessions`, and `session_registrations` tables, with foreign keys, indexes, uniqueness constraints, and transactional registration writes. Schema setup is idempotent and tracked with SQLite `user_version = 1`. Timestamps are UTC ISO strings.

Consent version, acceptance time, preference update time, and registration time are saved. Expired sessions are removed on startup and hourly. Registrations inactive for 90 days are removed during cleanup, along with orphan contact records.

## API

| Method | Route | Behavior |
| --- | --- | --- |
| GET | `/api/webinars/:id/registration` | Return current browser session’s registration or `null` |
| POST | `/api/webinars/:id/registration` | Validate, persist, and grant access to this webinar |
| DELETE | `/api/webinars/:id/registration` | Remove registration and revoke access |
| GET / HEAD | `/api/webinars/:id/video` | Require registration, then stream the correct MP4; supports byte ranges |

POST and DELETE require a matching Origin header. Writes are limited to 30 attempts per minute per connecting IP. JSON payloads are limited to 16 KiB. A hidden honeypot rejects basic automated submissions. SQL values are parameterized. Source/server files, database files, and direct video-file paths are excluded from the static file allowlist.

Configured for localhost development. Before public deployment, configure the intended host, HTTPS and `COOKIE_SECURE=1`, review privacy/retention text, add production operations/backups, and replace sample footage with authorized recordings. Self-registration grants access but does not verify ownership of an email address.

## Next stages

1. Playback sessions → engagement event tracking and watch-duration calculations.
2. Transcripts → AI chatbot → stored conversations.
3. Protected admin dashboard → filters → analytics → exports.

The browser UI stays HTML/CSS/JavaScript. The backend also uses JavaScript.

## Verification

Run the dependency-free API/database checks:

```powershell
node --test tests/registration.test.js
```

Checks cover validation, opt-out, persistence, duplicates, campaign isolation, private files, unauthorized access, all six actual MP4 streams, byte ranges, restart persistence, deletion, and access revocation. Tests use temporary databases and do not add leads to the running app.

Optional form/DOM tests use a development-only dependency:

```powershell
npm install --prefix .qa --no-audit --no-fund --ignore-scripts --package-lock=false jsdom@26.1.0
node --test tests/form.test.js
```

These execute the actual form scripts against the real API without a browser or external resources. They check inline errors, privacy handlers, submission, opt-out, saved-session restore, campaign isolation, editing, video-error UI, and removal. The app itself needs no npm packages.

Live browser preview access was declined during this change. DOM and API tests passed, but visual layout and actual video decoding/playback were not verified in a live browser for this stage. Earlier browser checks covered the campaign listing and related navigation.

Manual check: register on one webinar with marketing unchecked, play and seek the sample, reload to confirm remembered access, then visit another webinar to confirm it stays locked. Edit preferences and remove the registration to verify those actions. Check wide and narrow layouts.
