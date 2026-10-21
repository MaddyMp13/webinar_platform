# Webinar platform — PHP, MySQL, and XAMPP

The current app uses plain HTML, CSS, and JavaScript with a PHP backend and a MySQL/MariaDB database. Node is not needed to run the app.

Open http://localhost/Task/ after starting Apache and MySQL in XAMPP.

- Full SQL: database/webinar_platform.sql
- PHP database connection: api/db.php
- Database settings: config/config.php
- PHP API router: api/index.php
- Setup and table guide: XAMPP-SETUP.md
- Runnable XAMPP copy: C:\xampp\htdocs\Task
- Desktop source copy: C:\Users\mandar.patil\Desktop\Task

The database webinar_platform has already been created on this machine with 17 tables and the six original campaigns. The existing SQLite registration was migrated with its consent choices and timestamps. The source database and earlier code backups remain available.

## Current behavior

Campaigns load from MySQL. Search, filters, dynamic landing pages, and related webinars keep the selected campaign context. Registration validates contact fields and consent on the browser and PHP server, saves the lead and webinar registration transactionally, and unlocks that webinar’s sample video through a protected PHP endpoint. Returning visitors keep access through a scoped HttpOnly cookie. Editing preferences updates the existing registration; removal revokes access and deletes an orphan contact record.

Optional marketing and activity consent start unchecked. No emails are sent by this prototype. Sample footage is clearly labeled. A private dashboard at http://localhost/Task/dashboard.html shows registrations, video events/watch time, and saved transcript-chat conversations. First visit creates your local admin account. See DASHBOARD.md for setup, metric definitions, data coverage, and tests. Transcript replies use stored excerpts; an AI model and transcription service are not configured.

## Database and installation

On a fresh installation, copy this app to C:\xampp\htdocs\Task and import database/webinar_platform.sql through phpMyAdmin. It creates the database and tables without dropping existing data. Set your local database credentials in config/config.local.php if they differ from the default local XAMPP root/blank-password setup. See XAMPP-SETUP.md for exact steps, table relationships, endpoint examples, privacy/access behavior, and test commands.

The root .htaccess file is required: it blocks HTTP access to database/configuration files, SQL imports, raw videos, and private source. Keep it when copying the project.

## Verification

The PHP files pass syntax checks. API/database tests passed against XAMPP Apache + MariaDB, covering database campaign retrieval, consent, CSRF and origin checks, validation, duplicate handling, campaign isolation, six correct MP4 streams, byte ranges, deletion, and revocation. The form/DOM test passed against the same real backend. Test contact records were removed; the migrated registration remains.

Run API checks with: node --test tests/php-workflow.test.js

Optional form tests require the development-only jsdom package described in XAMPP-SETUP.md. The runtime app needs no npm dependencies. Browser playback and visual layout were not reverified in a live browser because preview access was previously declined.

The previous Node/SQLite files and tests are retained as legacy references. Use XAMPP for the active app; the old port 8001 server is stopped.
