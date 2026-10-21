'use strict';
// Start XAMPP and import database/webinar_platform.sql before running this test.
// Creates only a uniquely named example.test lead and removes it through the API.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const base = new URL(process.env.WEBINAR_TEST_URL || 'http://localhost/Task/');
const php = process.env.PHP_BIN || 'C:/xampp/php/php.exe';

test('XAMPP PHP + MySQL registration and protected video workflow', async t => {
  const email = `qa-${Date.now()}@example.test`;
  const values = { firstName: 'Test', lastName: 'Viewer', email, phone: '+91 98765 43210', company: 'Example Company', jobTitle: 'Engineer', country: 'IN', privacyConsent: true, marketingOptIn: false };
  let cookie = '';
  let csrf = '';
  const registeredIds = new Set();
  function url(resource, id) {
    const result = new URL('api/index.php', base);
    result.searchParams.set('resource', resource);
    if (id) result.searchParams.set('webinar_id', id);
    return result;
  }
  async function request(resource, id, method = 'GET', data, overrides = {}) {
    const response = await fetch(url(resource, id), {
      method,
      headers: { Cookie: cookie, Origin: base.origin, 'X-CSRF-Token': csrf, ...(data ? { 'Content-Type': 'application/json' } : {}), ...(overrides.headers || {}) },
      ...(data ? { body: JSON.stringify(data) } : {})
    });
    if (response.headers.get('set-cookie')) cookie = response.headers.get('set-cookie').split(';')[0];
    return response;
  }
  async function init(id) {
    const response = await request('registration', id);
    const result = await response.json();
    csrf = result.csrfToken;
    return result;
  }
  const inspect = () => JSON.parse(execFileSync(php, [path.join(__dirname,'php-inspect.php'), email], { encoding: 'utf8' }));
  t.after(async () => {
    for (const id of registeredIds) await request('registration', id, 'DELETE');
  });
  await t.test('public campaigns and related webinars are loaded from MySQL', async () => {
    const response = await request('webinars');
    assert.equal(response.status, 200);
    const { webinars } = await response.json();
    assert.equal(webinars.length, 6);
    assert.equal(webinars[4].slug, 'big-data-microsoft-azure');
    assert.equal(webinars[4].videoIsDemo, true);
    assert.equal(webinars[4].storage_path, undefined);
    const detail = new URL(url('webinar'));
    detail.searchParams.set('slug', 'cybersecurity-ai');
    const result = await (await fetch(detail)).json();
    assert.equal(result.webinar.id, 4);
    assert.equal(result.relatedWebinars[0].id, 6);
    assert.equal(result.relatedWebinars.length, 3);
    assert.ok(result.relatedWebinars.every(item => item.id !== 4));
    assert.equal(inspect().tables.length, 21);
  });
  await t.test('Apache blocks private files and unregistered video access', async () => {
    for (const file of ['database/webinar_platform.sql','config/config.php','api/db.php','videos/sample-1.mp4','.data/webinars.sqlite','server.js']) {
      assert.ok([403,404].includes((await fetch(new URL(file, base))).status), file);
    }
    assert.equal((await request('video', 1)).status, 401);
    const response = await request('registration', 1);
    const header = response.headers.get('set-cookie');
    assert.match(header, /httponly/i);
    assert.match(header, /samesite=lax/i);
    assert.match(header, /path=\/Task\//i);
    const result = await response.json();
    csrf = result.csrfToken;
    assert.equal(result.registration, null);
    assert.match(csrf, /^[a-f0-9]{64}$/);
  });
  await t.test('CSRF, origin checks, and field validation reject invalid submissions', async () => {
    assert.equal((await request('registration',1,'POST',values,{ headers:{ Origin:'https://other.example' } })).status,403);
    assert.equal((await request('registration',1,'POST',values,{ headers:{ 'X-CSRF-Token':'' } })).status,403);
    const invalid = await request('registration',1,'POST',{ ...values, email:'wrong', phone:'123', privacyConsent:false });
    assert.equal(invalid.status,422);
    const errors = (await invalid.json()).errors;
    for (const name of ['email','phone','privacyConsent']) assert.ok(errors[name]);
    assert.equal(inspect().leadCount,0);
  });
  let first;
  await t.test('real database registration saves opt-out and grants only selected video access', async () => {
    const response = await request('registration',1,'POST',values);
    assert.equal(response.status,201);
    first = (await response.json()).registration;
    registeredIds.add(1);
    assert.equal(first.webinarId,1);
    assert.equal(first.marketingOptIn,false);
    assert.equal(first.videoUrl,'/Task/api/index.php?resource=video&webinar_id=1');
    const record = inspect();
    assert.equal(record.leadCount,1);
    assert.equal(record.registrations.length,1);
    assert.equal(record.registrations[0].marketing_opt_in,0);
    assert.equal(record.registrations[0].consent_count,3);
    assert.equal((await request('video',2)).status,401);
    assert.equal((await init(1)).registration.id, first.id);
    const noCookie = await request('registration',1,'GET',undefined,{ headers:{ Cookie:'' } });
    assert.equal((await noCookie.json()).registration,null);
    // The anonymous lookup issued a different cookie; restore our owning session.
  });
  // Keep a dedicated owning cookie before checking further campaigns.
  // Re-registering with the same email links the existing row to the current browser.
  await t.test('repeat registration is idempotent and records consent preference changes', async () => {
    await init(1);
    const response = await request('registration',1,'POST',{ ...values,email:email.toUpperCase(),marketingOptIn:true });
    assert.equal(response.status,200);
    const result = (await response.json()).registration;
    assert.equal(result.id,first.id);
    assert.equal(result.registeredAt,first.registeredAt);
    assert.equal(result.marketingOptIn,true);
    const record = inspect();
    assert.equal(record.registrations.length,1);
    assert.equal(record.registrations[0].consent_count,4);
  });
  await t.test('all six protected videos return the correct MP4 bytes and support seeking', async () => {
    for (let id = 1; id <= 6; id++) {
      if (id !== 1) {
        const response = await request('registration',id,'POST',values);
        assert.equal(response.status,201);
        registeredIds.add(id);
      }
      const response = await request('video',id,'GET',undefined,{ headers:{ Range:'bytes=0-511' } });
      assert.equal(response.status,206);
      assert.equal(response.headers.get('content-type'),'video/mp4');
      assert.equal(response.headers.get('cache-control'),'private, no-store');
      const bytes = Buffer.from(await response.arrayBuffer());
      const expected = fs.readFileSync(path.join(__dirname,'..','videos',`sample-${id}.mp4`));
      assert.deepEqual(bytes,expected.subarray(0,512));
      assert.equal(bytes.toString('ascii',4,8),'ftyp');
      const head = await request('video',id,'HEAD');
      assert.equal(head.status,200);
      assert.equal(Number(head.headers.get('content-length')),expected.length);
    }
    assert.equal(inspect().registrations.length,6);
    assert.equal((await request('video',1,'GET',undefined,{ headers:{ Range:'bytes=999999999999-' } })).status,416);
    const suffix = await request('video',1,'GET',undefined,{ headers:{ Range:'bytes=-16' } });
    assert.equal(suffix.status,206);
    assert.equal((await suffix.arrayBuffer()).byteLength,16);
  });
  await t.test('deletion removes registrations/contact data and revokes video access', async () => {
    for (const id of [...registeredIds]) {
      assert.equal((await request('registration',id,'DELETE')).status,200);
      registeredIds.delete(id);
      assert.equal((await request('video',id)).status,401);
    }
    assert.equal(inspect().leadCount,0);
    assert.equal(inspect().registrations.length,0);
  });
});
