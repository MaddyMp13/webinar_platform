'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { createApp } = require('../server.js');
const rules = require('../registration-rules.js');

const valid = {
  firstName: ' Test ', lastName: 'Viewer', email: 'VIEWER@example.test',
  phone: '+91 98765 43210', company: 'Example Company', jobTitle: 'Engineer',
  country: 'IN', privacyConsent: true, marketingOptIn: false
};

test('shared validation accepts international names and rejects invalid contact/consent fields', () => {
  assert.deepEqual(rules.validate({ ...valid, firstName: '李', lastName: 'O’Neill' }), {});
  assert.equal(rules.normalize(valid).email, 'viewer@example.test');
  const errors = rules.validate({ ...valid, firstName: '  ', email: 'wrong', phone: 'abcd123', country: 'XX', privacyConsent: 'true' });
  for (const field of ['firstName', 'email', 'phone', 'country', 'privacyConsent']) assert.ok(errors[field]);
  assert.ok(rules.validate({ ...valid, company: 'x'.repeat(121) }).company);
  assert.ok(!rules.validate(valid).marketingOptIn, 'Marketing consent must not be required');
});

test('registration persists in SQLite and gates the correct video', async t => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'webinar-registration-test-'));
  const dbPath = path.join(directory, 'test.sqlite');
  let app = createApp({ dbPath });
  await new Promise(resolve => app.server.listen(0, '127.0.0.1', resolve));
  let origin = `http://127.0.0.1:${app.server.address().port}`;
  t.after(async () => {
    if (app.server.listening) await new Promise(resolve => app.server.close(resolve));
    fs.rmSync(directory, { recursive: true, force: true });
  });
  const route = id => `/api/webinars/${id}/registration`;
  const post = (id, data, cookie = '', requestOrigin = origin) => fetch(origin + route(id), {
    method: 'POST', headers: { Origin: requestOrigin, Cookie: cookie, 'Content-Type': 'application/json' }, body: JSON.stringify(data)
  });
  const count = table => app.db.prepare(`SELECT COUNT(*) AS n FROM ${table}`).get().n;

  await t.test('rejects private files, unauthenticated playback, and cross-origin writes', async () => {
    for (const file of ['/server.js', '/.data/webinars.sqlite', '/videos/sample-1.mp4', '/video-library.js']) {
      assert.equal((await fetch(origin + file)).status, 404);
    }
    assert.equal((await fetch(origin + '/api/webinars/1/video')).status, 401);
    assert.equal((await post(1, valid, '', 'https://other.example')).status, 403);
    assert.equal((await post(999, valid)).status, 404);
    assert.equal(count('leads'), 0);
  });

  await t.test('invalid submissions create no database records', async () => {
    const response = await post(1, { ...valid, email: 'invalid', phone: '123', privacyConsent: false });
    assert.equal(response.status, 422);
    const result = await response.json();
    for (const field of ['email', 'phone', 'privacyConsent']) assert.ok(result.errors[field]);
    assert.equal(count('leads'), 0);
    assert.equal(count('lead_webinars'), 0);
    assert.equal((await post(1, { ...valid, companyWebsite: 'spam' })).status, 400);
  });

  let cookie;
  let firstRegistration;
  await t.test('creates a real record, preserves opt-out, and remembers access with an HttpOnly cookie', async () => {
    const response = await post(1, valid);
    assert.equal(response.status, 201);
    const header = response.headers.get('set-cookie');
    assert.match(header, /HttpOnly/);
    assert.match(header, /SameSite=Lax/);
    cookie = header.split(';')[0];
    firstRegistration = (await response.json()).registration;
    assert.equal(firstRegistration.webinarId, 1);
    assert.equal(firstRegistration.email, 'viewer@example.test');
    assert.equal(firstRegistration.marketingOptIn, false);
    assert.equal(firstRegistration.videoUrl, '/api/webinars/1/video');
    assert.equal(count('leads'), 1);
    assert.equal(count('lead_webinars'), 1);
    const record = app.db.prepare('SELECT * FROM lead_webinars').get();
    assert.equal(record.marketing_opt_in, 0);
    assert.equal(record.privacy_consent, 1);
    assert.ok(record.privacy_accepted_at);
    assert.equal((await (await fetch(origin + route(1), { headers: { Cookie: cookie } })).json()).registration.id, firstRegistration.id);
    assert.equal((await (await fetch(origin + route(1))).json()).registration, null);
    assert.equal((await fetch(origin + '/api/webinars/2/video', { headers: { Cookie: cookie } })).status, 401);
  });

  await t.test('duplicate email updates preferences without duplicating leads or registrations', async () => {
    const response = await post(1, { ...valid, email: 'viewer@EXAMPLE.TEST', marketingOptIn: true }, cookie);
    assert.equal(response.status, 200);
    const registration = (await response.json()).registration;
    assert.equal(registration.id, firstRegistration.id);
    assert.equal(registration.registeredAt, firstRegistration.registeredAt);
    assert.equal(registration.marketingOptIn, true);
    assert.equal(count('leads'), 1);
    assert.equal(count('lead_webinars'), 1);
  });

  await t.test('every campaign streams its own configured MP4 and supports seeking', async () => {
    const contentHashes = new Set();
    const { createHash } = require('node:crypto');
    for (let id = 1; id <= 6; id++) {
      if (id !== 1) assert.equal((await post(id, valid, cookie)).status, 201);
      const video = await fetch(origin + `/api/webinars/${id}/video`, { headers: { Cookie: cookie, Range: 'bytes=0-511' } });
      assert.equal(video.status, 206);
      assert.equal(video.headers.get('content-type'), 'video/mp4');
      assert.match(video.headers.get('content-range'), /^bytes 0-511\/\d+$/);
      assert.equal(video.headers.get('cache-control'), 'private, no-store');
      const bytes = Buffer.from(await video.arrayBuffer());
      assert.equal(bytes.length, 512);
      assert.equal(bytes.toString('ascii', 4, 8), 'ftyp');
      const expected = fs.readFileSync(path.join(__dirname, '..', 'videos', `sample-${id}.mp4`));
      assert.deepEqual(bytes, expected.subarray(0, 512));
      contentHashes.add(createHash('sha256').update(expected).digest('hex'));
      const head = await fetch(origin + `/api/webinars/${id}/video`, { method: 'HEAD', headers: { Cookie: cookie } });
      assert.equal(head.status, 200);
      assert.equal(Number(head.headers.get('content-length')), expected.length);
    }
    assert.equal(contentHashes.size, 6);
    assert.equal(count('leads'), 1);
    assert.equal(count('lead_webinars'), 6);
    const invalidRange = await fetch(origin + '/api/webinars/1/video', { headers: { Cookie: cookie, Range: 'bytes=999999999999-' } });
    assert.equal(invalidRange.status, 416);
    const suffix = await fetch(origin + '/api/webinars/1/video', { headers: { Cookie: cookie, Range: 'bytes=-16' } });
    assert.equal(suffix.status, 206);
    assert.equal((await suffix.arrayBuffer()).byteLength, 16);
  });

  await t.test('registration and session survive a server restart', async () => {
    await new Promise(resolve => app.server.close(resolve));
    app = createApp({ dbPath });
    await new Promise(resolve => app.server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${app.server.address().port}`;
    const result = await (await fetch(origin + route(1), { headers: { Cookie: cookie } })).json();
    assert.equal(result.registration.id, firstRegistration.id);
    assert.equal(count('lead_webinars'), 6);
  });

  await t.test('removal deletes registrations, revokes access, and removes orphan contact data', async () => {
    for (let id = 1; id <= 6; id++) {
      const response = await fetch(origin + route(id), { method: 'DELETE', headers: { Origin: origin, Cookie: cookie } });
      assert.equal(response.status, 200);
      assert.equal((await fetch(origin + `/api/webinars/${id}/video`, { headers: { Cookie: cookie } })).status, 401);
    }
    assert.equal(count('lead_webinars'), 0);
    assert.equal(count('leads'), 0);
  });
});
