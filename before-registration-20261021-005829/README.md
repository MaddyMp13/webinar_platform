# Webinar library — stage 1

Built using HTML, CSS, and vanilla JavaScript. No framework, dependencies, or build step.

## Run

Open `index.html` in a browser, or run a static server from this folder:

```powershell
python -m http.server 8000 --bind 127.0.0.1
```

Then visit http://127.0.0.1:8000. Python serves files only; it is not an application backend. `first.html` is a compatible listing entry point matching the original filename.

## Original project inspection

Source inspected: `C:\Users\mandar.patil\Desktop\Task`.

- `first.html`: listing container, Google logo, inline card styles.
- `script.js`: six campaign records and card rendering; cards linked directly to external publishers.
- `Google-Logo-Tech-Company.png`: existing branding asset.
- No framework, package dependencies, backend, database, authentication, or application routes were present.

This workspace contains an extended copy. The original Desktop files were preserved. All six records, descriptions, source image URLs, publisher URLs, campaign IDs, and dates are retained in `campaigns.js`.

## Implemented

- Responsive campaign cards with title, category, description, original artwork, and details links.
- Search across title, description, and category; topic filtering and title sorting.
- Search/filter state retained in the listing URL for reloads and browser back navigation.
- Dynamic landing pages at `webinar.html?slug=ai-data-resilience`, or `webinar.html?id=1`.
- One reusable data model and renderer for all six landing pages.
- Three related campaigns per page, excluding the selected campaign and preferring the same category.
- Invalid/missing webinar state, no-results state, reset controls, image-error fallbacks, focus styles, and keyboard-accessible controls.
- External publisher links are clearly labeled. Speakers, duration, and agendas are omitted because the source records do not contain them.

## Next stages

1. Registration → database → secure asset access: requires a real server API, database, validation, and authorization. Frontend-only JavaScript cannot protect an asset or database credentials. No registration records or access grants are simulated here.
2. Player → playback sessions → event tracking: requires authorized playable assets and server persistence.
3. Transcripts → AI chatbot → stored conversations: requires authorized transcripts, a server-side AI integration, and database storage.
4. Protected admin dashboard → filters → analytics → exports: requires authentication and actual database queries.

The browser UI can remain HTML/CSS/JavaScript for every stage. Server-side JavaScript is an option when implementing the database and protected flows.

## Verification

- JavaScript syntax checked with `node --check script.js` and `node --check campaigns.js`.
- Browser checks cover search empty state, reset, category filtering, campaign details, and same-category related navigation.
- Artwork remains hosted by the original third-party publishers; unavailable images show a category fallback. Recording availability and original event links are not independently verified.

Manual regression: open all six cards; confirm URL, title, category, campaign ID, description, artwork, and publisher link match `campaigns.js`. On each detail page confirm three related campaigns and no self-link. Follow a related card, reload a deep link, test an unknown slug, and check narrow and wide browser widths.
