"use strict";

// Node.js 22.13+; no npm dependencies. The SQLite database is never served.
const http = require("node:http");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const { randomBytes, randomUUID, createHash } = require("node:crypto");
const { DatabaseSync } = require("node:sqlite");
const {
  normalize,
  validate,
  privacyVersion,
} = require("./registration-rules.js");
const videoLibrary = require("./video-library.js");

function createApp(options = {}) {
  const root = __dirname;
  const dbFile = options.dbPath || path.join(root, ".data", "webinars.sqlite");
  if (dbFile !== ":memory:")
    fs.mkdirSync(path.dirname(dbFile), { recursive: true });
  const db = new DatabaseSync(dbFile);
  const campaignData = vm.runInNewContext(
    fs.readFileSync(path.join(root, "campaigns.js"), "utf8") + "; campaigns;",
  );
  const videoRoot = options.videoRoot || path.join(root, "videos");
  const cookieAge = 30 * 24 * 60 * 60;
  const secureCookie = process.env.COOKIE_SECURE === "1" ? "; Secure" : "";
  const allowedHosts = new Set(
    options.allowedHosts || ["127.0.0.1", "localhost"],
  );
  const rateLimits = new Map();
  db.exec(`
    PRAGMA foreign_keys = ON;
    PRAGMA journal_mode = WAL;
    CREATE TABLE IF NOT EXISTS webinars (id INTEGER PRIMARY KEY, campaign_id TEXT UNIQUE NOT NULL, title TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS leads (
      id TEXT PRIMARY KEY, first_name TEXT NOT NULL, last_name TEXT NOT NULL,
      email TEXT NOT NULL UNIQUE COLLATE NOCASE, phone TEXT NOT NULL,
      company TEXT NOT NULL, job_title TEXT NOT NULL DEFAULT '', country TEXT NOT NULL,
      created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS lead_webinars (
      id TEXT PRIMARY KEY, lead_id TEXT NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
      webinar_id INTEGER NOT NULL REFERENCES webinars(id),
      privacy_consent INTEGER NOT NULL CHECK (privacy_consent = 1),
      marketing_opt_in INTEGER NOT NULL CHECK (marketing_opt_in IN (0,1)),
      privacy_version TEXT NOT NULL, privacy_accepted_at TEXT NOT NULL,
      consent_updated_at TEXT NOT NULL, registered_at TEXT NOT NULL, updated_at TEXT NOT NULL,
      UNIQUE (lead_id, webinar_id)
    );
    CREATE TABLE IF NOT EXISTS visitor_sessions (token_hash TEXT PRIMARY KEY, expires_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS session_registrations (
      token_hash TEXT NOT NULL REFERENCES visitor_sessions(token_hash) ON DELETE CASCADE,
      registration_id TEXT NOT NULL REFERENCES lead_webinars(id) ON DELETE CASCADE,
      UNIQUE (token_hash, registration_id)
    );
    CREATE INDEX IF NOT EXISTS registrations_by_webinar ON lead_webinars(webinar_id);
    CREATE INDEX IF NOT EXISTS registrations_by_date ON lead_webinars(updated_at);
    CREATE INDEX IF NOT EXISTS session_registrations_by_registration ON session_registrations(registration_id);
    PRAGMA user_version = 1;
  `);
  const seed = db.prepare(
    "INSERT INTO webinars(id,campaign_id,title) VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET campaign_id=excluded.campaign_id,title=excluded.title",
  );
  for (const webinar of campaignData)
    seed.run(webinar.id, webinar.campaignId, webinar.title);

  function cleanOldData() {
    db.prepare("DELETE FROM visitor_sessions WHERE expires_at < ?").run(
      Date.now(),
    );
    db.prepare("DELETE FROM lead_webinars WHERE updated_at < ?").run(
      new Date(Date.now() - 90 * 86400000).toISOString(),
    );
    db.exec(
      "DELETE FROM leads WHERE NOT EXISTS (SELECT 1 FROM lead_webinars WHERE lead_id=leads.id)",
    );
  }
  cleanOldData();
  const cleanup = setInterval(cleanOldData, 60 * 60 * 1000);
  cleanup.unref();

  function send(res, status, body) {
    res.writeHead(status, {
      "Content-Type": "application/json; charset=utf-8",
      "Cache-Control": "no-store",
    });
    res.end(JSON.stringify(body));
  }
  function fail(status, message, errors) {
    return Object.assign(new Error(message), { status, errors });
  }
  function sessionHash(req) {
    const token = req.headers.cookie
      ?.split(";")
      .map((part) => part.trim())
      .find((part) => part.startsWith("webinar_session="))
      ?.slice("webinar_session=".length);
    return token && /^[a-f0-9]{64}$/.test(token)
      ? createHash("sha256").update(token).digest("hex")
      : "";
  }
  function session(req) {
    const hash = sessionHash(req);
    return hash &&
      db
        .prepare(
          "SELECT token_hash FROM visitor_sessions WHERE token_hash=? AND expires_at>?",
        )
        .get(hash, Date.now())
      ? hash
      : null;
  }
  function findRegistration(hash, webinarId) {
    if (!hash) return null;
    const row = db
      .prepare(
        `SELECT r.*, l.first_name, l.last_name, l.email, l.phone, l.company, l.job_title, l.country
      FROM session_registrations s JOIN lead_webinars r ON r.id=s.registration_id JOIN leads l ON l.id=r.lead_id
      WHERE s.token_hash=? AND r.webinar_id=?`,
      )
      .get(hash, webinarId);
    if (!row) return null;
    return {
      id: row.id,
      webinarId: row.webinar_id,
      firstName: row.first_name,
      lastName: row.last_name,
      email: row.email,
      phone: row.phone,
      company: row.company,
      jobTitle: row.job_title,
      country: row.country,
      privacyConsent: true,
      marketingOptIn: Boolean(row.marketing_opt_in),
      registeredAt: row.registered_at,
      videoUrl: `/api/webinars/${webinarId}/video`,
    };
  }
  async function readBody(req) {
    if (!req.headers["content-type"]?.startsWith("application/json"))
      throw fail(415, "Send registration data as JSON.");
    let size = 0;
    const chunks = [];
    for await (const chunk of req) {
      size += chunk.length;
      if (size > 16384) throw fail(413, "Registration data is too large.");
      chunks.push(chunk);
    }
    try {
      const body = JSON.parse(Buffer.concat(chunks).toString());
      if (!body || typeof body !== "object" || Array.isArray(body))
        throw new Error();
      return body;
    } catch {
      throw fail(400, "Invalid registration data.");
    }
  }
  function limit(req) {
    const address = req.socket.remoteAddress;
    const now = Date.now();
    if (rateLimits.size > 1000)
      for (const [key, value] of rateLimits)
        if (value.until < now) rateLimits.delete(key);
    let entry = rateLimits.get(address);
    if (!entry || entry.until < now) {
      entry = { count: 0, until: now + 60000 };
      rateLimits.set(address, entry);
    }
    if (++entry.count > 30)
      throw fail(429, "Too many attempts. Please wait a minute and try again.");
  }
  function allowMutation(req) {
    // No cross-origin writes, including requests from another local port.
    const expected = `${secureCookie ? "https" : "http"}://${req.headers.host}`;
    if (
      req.headers.origin !== expected ||
      req.headers["sec-fetch-site"] === "cross-site"
    )
      throw fail(403, "Open the form from this website to continue.");
  }
  function register(req, res, webinarId, raw) {
    const errors = validate(raw);
    if (Object.keys(errors).length)
      throw fail(422, "Please review the highlighted fields.", errors);
    if (raw.companyWebsite)
      throw fail(
        400,
        "We couldn’t submit this registration. Please try again.",
      );
    const data = normalize(raw);
    const now = new Date().toISOString();
    let hash = session(req);
    let cookie;
    if (!hash) {
      const token = randomBytes(32).toString("hex");
      hash = createHash("sha256").update(token).digest("hex");
      cookie = `webinar_session=${token}; HttpOnly; SameSite=Lax; Path=/; Max-Age=${cookieAge}${secureCookie}`;
    }
    db.exec("BEGIN IMMEDIATE");
    try {
      const existingLead = db
        .prepare("SELECT id FROM leads WHERE email=?")
        .get(data.email);
      const leadId = existingLead?.id || randomUUID();
      db.prepare(
        `INSERT INTO leads(id,first_name,last_name,email,phone,company,job_title,country,created_at,updated_at)
        VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(email) DO UPDATE SET first_name=excluded.first_name,last_name=excluded.last_name,
        phone=excluded.phone,company=excluded.company,job_title=excluded.job_title,country=excluded.country,updated_at=excluded.updated_at`,
      ).run(
        leadId,
        data.firstName,
        data.lastName,
        data.email,
        data.phone,
        data.company,
        data.jobTitle,
        data.country,
        now,
        now,
      );
      const existing = db
        .prepare(
          "SELECT id FROM lead_webinars WHERE lead_id=? AND webinar_id=?",
        )
        .get(leadId, webinarId);
      const registrationId = existing?.id || randomUUID();
      db.prepare(
        `INSERT INTO lead_webinars(id,lead_id,webinar_id,privacy_consent,marketing_opt_in,privacy_version,
        privacy_accepted_at,consent_updated_at,registered_at,updated_at) VALUES(?,?,?,1,?,?,?,?,?,?)
        ON CONFLICT(lead_id,webinar_id) DO UPDATE SET marketing_opt_in=excluded.marketing_opt_in,
        privacy_version=excluded.privacy_version,privacy_accepted_at=excluded.privacy_accepted_at,
        consent_updated_at=excluded.consent_updated_at,updated_at=excluded.updated_at`,
      ).run(
        registrationId,
        leadId,
        webinarId,
        Number(data.marketingOptIn),
        privacyVersion,
        now,
        now,
        now,
        now,
      );
      db.prepare(
        "INSERT INTO visitor_sessions VALUES(?,?) ON CONFLICT(token_hash) DO UPDATE SET expires_at=excluded.expires_at",
      ).run(hash, Date.now() + cookieAge * 1000);
      // One active registration per webinar in this browser session.
      db.prepare(
        `DELETE FROM session_registrations WHERE token_hash=? AND registration_id IN
        (SELECT id FROM lead_webinars WHERE webinar_id=?)`,
      ).run(hash, webinarId);
      db.prepare("INSERT INTO session_registrations VALUES(?,?)").run(
        hash,
        registrationId,
      );
      db.exec("COMMIT");
      if (cookie) res.setHeader("Set-Cookie", cookie);
      return send(res, existing ? 200 : 201, {
        registration: findRegistration(hash, webinarId),
      });
    } catch (error) {
      db.exec("ROLLBACK");
      throw error;
    }
  }
  function serveVideo(req, res, webinarId) {
    if (!findRegistration(session(req), webinarId))
      throw fail(401, "Register for this webinar before watching.");
    const video = videoLibrary.find((item) => item.id === webinarId);
    const filename = video && path.join(videoRoot, video.file);
    if (!filename || !fs.existsSync(filename))
      throw fail(503, "The video has not been configured yet.");
    const size = fs.statSync(filename).size;
    let start = 0;
    let end = size - 1;
    const range = req.headers.range;
    if (range) {
      const match = /^bytes=(\d*)-(\d*)$/.exec(range);
      if (!match || (!match[1] && !match[2])) {
        res.writeHead(416, { "Content-Range": `bytes */${size}` });
        res.end();
        return;
      }
      if (!match[1]) start = Math.max(0, size - Number(match[2]));
      else start = Number(match[1]);
      if (match[1] && match[2]) end = Math.min(Number(match[2]), size - 1);
      if (
        !Number.isSafeInteger(start) ||
        !Number.isSafeInteger(end) ||
        start >= size ||
        start > end
      ) {
        res.writeHead(416, { "Content-Range": `bytes */${size}` });
        res.end();
        return;
      }
    }
    const headers = {
      "Content-Type": "video/mp4",
      "Accept-Ranges": "bytes",
      "Content-Length": end - start + 1,
      "Cache-Control": "private, no-store",
    };
    if (range) headers["Content-Range"] = `bytes ${start}-${end}/${size}`;
    res.writeHead(range ? 206 : 200, headers);
    if (req.method === "HEAD") return res.end();
    const stream = fs.createReadStream(filename, { start, end });
    stream.on("error", () => res.destroy());
    res.on("close", () => stream.destroy());
    stream.pipe(res);
  }
  const publicFiles = new Set([
    "index.html",
    "first.html",
    "webinar.html",
    "styles.css",
    "script.js",
    "campaigns.js",
    "registration.js",
    "registration-rules.js",
    "Google-Logo-Tech-Company.png",
  ]);
  const types = {
    ".html": "text/html; charset=utf-8",
    ".css": "text/css; charset=utf-8",
    ".js": "text/javascript; charset=utf-8",
    ".png": "image/png",
  };
  const server = http.createServer(async (req, res) => {
    res.setHeader("X-Content-Type-Options", "nosniff");
    res.setHeader("Referrer-Policy", "same-origin");
    res.setHeader("X-Frame-Options", "DENY");
    res.setHeader(
      "Content-Security-Policy",
      "default-src 'self'; img-src 'self' https:; media-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
    );
    try {
      const host = new URL(`http://${req.headers.host}`).hostname;
      if (!allowedHosts.has(host))
        throw fail(403, "This host is not configured.");
      const url = new URL(req.url, `http://${req.headers.host}`);
      const match = /^\/api\/webinars\/(\d+)\/(registration|video)$/.exec(
        url.pathname,
      );
      if (match) {
        const webinarId = Number(match[1]);
        if (!campaignData.some((item) => item.id === webinarId))
          throw fail(404, "Webinar not found.");
        if (match[2] === "video" && ["GET", "HEAD"].includes(req.method))
          return serveVideo(req, res, webinarId);
        if (match[2] === "registration") {
          if (req.method === "GET")
            return send(res, 200, {
              registration: findRegistration(session(req), webinarId),
            });
          if (["POST", "DELETE"].includes(req.method)) {
            allowMutation(req);
            limit(req);
            if (req.method === "POST")
              return register(req, res, webinarId, await readBody(req));
            const registration = findRegistration(session(req), webinarId);
            if (!registration)
              throw fail(401, "No registration is available in this browser.");
            db.exec("BEGIN IMMEDIATE");
            try {
              db.prepare("DELETE FROM lead_webinars WHERE id=?").run(
                registration.id,
              );
              db.exec(
                "DELETE FROM leads WHERE NOT EXISTS (SELECT 1 FROM lead_webinars WHERE lead_id=leads.id)",
              );
              db.exec("COMMIT");
            } catch (error) {
              db.exec("ROLLBACK");
              throw error;
            }
            return send(res, 200, { deleted: true });
          }
        }
        throw fail(405, "Method not allowed.");
      }
      if (url.pathname.startsWith("/api/"))
        throw fail(404, "API route not found.");
      if (!["GET", "HEAD"].includes(req.method))
        throw fail(405, "Method not allowed.");
      const file = url.pathname === "/" ? "index.html" : url.pathname.slice(1);
      if (!publicFiles.has(file)) throw fail(404, "Page not found.");
      const content = fs.readFileSync(path.join(root, file));
      res.writeHead(200, {
        "Content-Type": types[path.extname(file)],
        "Content-Length": content.length,
        "Cache-Control": "no-cache",
      });
      res.end(req.method === "HEAD" ? undefined : content);
    } catch (error) {
      if (res.headersSent) {
        res.destroy();
        return;
      }
      if (!error.status)
        console.error("Request failed:", error.code || error.name);
      if (error.status === 429) res.setHeader("Retry-After", "60");
      send(res, error.status || 500, {
        message: error.status
          ? error.message
          : "Something went wrong. Please try again.",
        ...(error.errors ? { errors: error.errors } : {}),
      });
    }
  });
  server.on("close", () => {
    clearInterval(cleanup);
    db.close();
  });
  return { server, db };
}

if (require.main === module) {
  const port = Number(process.env.PORT || 8001);
  const app = createApp();
  app.server.on("error", (error) => {
    console.error(`Could not start server: ${error.message}`);
    process.exit(1);
  });
  app.server.listen(port, "127.0.0.1", () =>
    console.log(
      `Webinar library: http://127.0.0.1:${port}\nRegistrations: ${path.join(__dirname, ".data", "webinars.sqlite")}`,
    ),
  );
  for (const signal of ["SIGINT", "SIGTERM"])
    process.on(signal, () => app.server.close(() => process.exit(0)));
}
module.exports = { createApp };
