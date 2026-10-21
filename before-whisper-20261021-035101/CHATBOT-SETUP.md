# Local webinar chatbot

Open **http://localhost/Task/transcripts.html** after signing in at **http://localhost/Task/dashboard.html**. Upload or paste an authorized UTF-8 TXT, VTT, or SRT transcript for the matching recording. VTT/SRT timestamps become video links. TXT supports reading and Q&A without invented timestamps. Sample videos do not come with the original webinar transcripts; replace the recording or provide its actual matching captions. This integration does not perform speech recognition.

## Start local AI

Install [Ollama for Windows](https://ollama.com/download/windows) if needed, then run in PowerShell from the project folder:

```powershell
powershell -ExecutionPolicy Bypass -File tools/start-ollama.ps1
```

This starts Ollama locally if needed and downloads `qwen2.5:1.5b` (about 986 MB). The model stays on the computer. Subsequent starts reuse it. A stronger installed model can be selected by setting `ollama_model` in `config/config.local.php` (copy the example first). Match the exact name from `ollama list`. The model's quality and generation speed depend on your hardware. Cloud model names and non-loopback API addresses are rejected; the start script also disables Ollama cloud features for a server it starts.

PHP calls the local [Ollama chat API](https://docs.ollama.com/api/chat) and uses [structured outputs](https://docs.ollama.com/capabilities/structured-outputs). No API key or cloud subscription is needed. The site sends only the question, bounded recent chat context, and retrieved transcript passages to the local model. It does not include registration contact fields. Do not expose Ollama's port publicly.

## Database

On an existing XAMPP database run:

```powershell
C:\xampp\php\php.exe tools/migrate-chatbot.php
```

Or import `database/chatbot.sql` into the existing `webinar_platform` database using phpMyAdmin. The full `database/webinar_platform.sql` includes all tables for a new installation. Migrations preserve registrations, administrator accounts, and activity records.

`webinar_transcripts` stores searchable chunks with webinar ID and optional time ranges. `webinar_transcript_documents` stores the import filename, format, hash and administrator. `webinar_insights` caches summaries for each transcript version, model, and section. Existing chat conversations, messages, and source tables save question/answer history, actual model names, token counts, source IDs, timestamps, and quoted evidence.

## Use the chatbot

Register for a webinar and enable optional activity consent. Under its video:

1. **Ask the assistant** asks questions, starts a new conversation, and reloads saved conversations for that registration and webinar. Questions are saved only after a complete, validated answer. A retry uses the same request ID and does not duplicate messages.
2. **Transcript** reads or searches the selected transcript. Search phrases use a request body. Timestamp buttons move the on-page video to that passage. Reading does not require activity consent.
3. **Summary & takeaways** generates a summary, topics, key takeaways and explicitly stated actions. Long transcripts are summarized in sections covering the entire import. Progress is saved after each section; stopping and continuing reuses it. A cached summary can be read without enabling activity collection. Summary views are not counted as chatbot questions in the dashboard.

Questions use ranked lexical retrieval from this webinar only. Follow-up retrieval can reuse the previous question's terms; lexical search is not semantic embedding search. The model is instructed to use transcript evidence only. Every accepted citation must name a retrieved chunk and contain a quote actually found in it. Unsupported answers are refused; invalid citations or provider failures are shown as errors and are not saved. Model wording can still be mistaken: readers should verify cited passages. There are no web search tools, external actions, or cross-webinar retrieval.

Replacing a transcript invalidates cached summaries. Previously saved answers keep their original source labels, time ranges, and quotes, but their links to removed chunks become null; the viewer labels those as a previous version and disables timestamp links. Admin chat details remain available in the existing dashboard.

## Limits and troubleshooting

- Imports: UTF-8 only, 20–120,000 characters; no remote URL fetching or arbitrary uploaded files served by Apache.
- Chat: 3–1,500 characters per question, up to 50 questions per conversation. Generation is rate limited and serialized while a webinar transcript is in use. Retry after a busy response.
- Local inference: bounded retrieved context, 1,200 output tokens by default, 180-second timeout, no automatic paid-service fallback. Ollama being offline keeps history and transcript reading available; questions are retained in the form for retry.
- If a video timestamp is outside its duration, check that the uploaded captions match the current video.
- To intentionally use direct excerpts without AI, set `chat_provider` to `excerpts`; the UI labels that mode explicitly.
- Settings and database credentials stay in the private `config/` folder. Apache serves only the allowlisted public files and the API entry point.

Validation: PHP syntax, caption parsing and citation checks; real Apache/MySQL API integration; DOM interaction tests. Browser rendering and video decoding require a browser check. Tests create uniquely named temporary QA records and remove them without touching existing user accounts or webinar transcripts.
