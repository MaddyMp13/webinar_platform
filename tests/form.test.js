'use strict';
// Optional DOM integration check: npm install --prefix .qa --ignore-scripts jsdom@26.1.0
// No real browser or external resources are loaded by these tests.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { JSDOM } = require('../.qa/node_modules/jsdom');
const { execFileSync } = require('node:child_process');

test('form submission, validation, restore, editing, privacy, and deletion use the real API', async t => {
  const base = new URL(process.env.WEBINAR_TEST_URL || 'http://localhost/Task/');
  const origin = base.origin;
  const email = `qa-form-${Date.now()}@example.test`;
  const inspect = () => JSON.parse(execFileSync(process.env.PHP_BIN || 'C:/xampp/php/php.exe', [path.join(__dirname,'php-inspect.php'),email], { encoding:'utf8' }));
  let cookie = '';
  const windows = [];
  const root = path.join(__dirname, '..');
  t.after(async () => {
    for (const dom of windows) dom.window.close();
    const getUrl = new URL('api/index.php?resource=registration&webinar_id=5',base);
    const response = await fetch(getUrl,{ headers:{ Cookie:cookie } });
    const data = await response.json();
    if (data.registration) await fetch(getUrl,{ method:'DELETE',headers:{ Cookie:cookie,Origin:origin,'X-CSRF-Token':data.csrfToken } });
  });
  async function waitFor(condition) {
    for (let attempt = 0; attempt < 200; attempt++) {
      if (condition()) return;
      await new Promise(resolve => setTimeout(resolve, 10));
    }
    throw new Error('DOM condition did not become true');
  }
  function load(slug, offline = false) {
    const dom = new JSDOM(fs.readFileSync(path.join(root, 'webinar.html'), 'utf8'), {
      url: new URL(`webinar.html?slug=${slug}`,base).href, runScripts: 'outside-only'
    });
    windows.push(dom);
    const win = dom.window;
    win.HTMLElement.prototype.scrollIntoView = () => {};
    win.HTMLMediaElement.prototype.play = () => Promise.resolve();
    win.HTMLMediaElement.prototype.pause = () => {};
    win.HTMLMediaElement.prototype.load = () => {};
    win.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    win.HTMLDialogElement.prototype.close = function () { this.open = false; };
    win.fetch = async (url, options = {}) => {
      if (offline) throw new Error('Connection unavailable. Please try again.');
      const response = await fetch(new URL(url,base), {
        ...options, headers: { ...(options.headers || {}), Origin: origin, Cookie: cookie }
      });
      if (response.headers.get('set-cookie')) cookie = response.headers.get('set-cookie').split(';')[0];
      return response;
    };
    for (const file of ['backend.js', 'registration-rules.js', 'playback.js', 'chat.js', 'registration.js', 'script.js']) {
      vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8'), dom.getInternalVMContext(), { filename: file });
    }
    return dom;
  }
  const dom = load('big-data-microsoft-azure');
  const win = dom.window;
  const doc = win.document;
  await waitFor(() => doc.querySelector('[type="submit"]')?.disabled === false);
  assert.equal(doc.querySelector('#marketingOptIn').checked, false);
  assert.equal(doc.querySelector('video'), null);
  assert.equal(doc.querySelectorAll('#country option').length, 250);
  doc.querySelector('#open-privacy').click();
  assert.equal(doc.querySelector('#privacy-dialog').open, true);
  doc.querySelector('#close-privacy').click();
  assert.equal(doc.querySelector('#privacy-dialog').open, false);
  let form = doc.querySelector('#registration-form');
  const submit = () => form.dispatchEvent(new win.Event('submit', { bubbles: true, cancelable: true }));
  submit();
  assert.equal(doc.querySelectorAll('[aria-invalid="true"]').length, 7);
  assert.equal(doc.activeElement.id, 'firstName');
  assert.equal(inspect().leadCount, 0);
  const values = { firstName: 'Test', lastName: 'Viewer', email: email.toUpperCase(), phone: '+91 98765 43210', company: 'Example Company', country: 'IN', jobTitle: 'Engineer' };
  for (const [name, value] of Object.entries(values)) {
    form.elements[name].value = value;
    form.elements[name].dispatchEvent(new win.Event('input', { bubbles: true }));
  }
  form.elements.privacyConsent.checked = true;
  submit();
  await waitFor(() => doc.querySelector('video'));
  assert.equal(doc.querySelector('video source').getAttribute('src'), '/Task/api/index.php?resource=video&webinar_id=5');
  assert.match(doc.querySelector('#access-title').textContent, /ready/);
  assert.equal(inspect().registrations[0].marketing_opt_in, 0);
  assert.equal(doc.querySelector('.registered-email').textContent,email);
  const initialId = inspect().registrations[0].id;

  const other = load('ai-threat-detection');
  await waitFor(() => other.window.document.querySelector('[type="submit"]')?.disabled === false);
  assert.equal(other.window.document.querySelector('video'), null, 'Another webinar must remain locked');
  const restored = load('big-data-microsoft-azure');
  await waitFor(() => restored.window.document.querySelector('video'));
  assert.equal(restored.window.document.querySelector('video source').getAttribute('src'), '/Task/api/index.php?resource=video&webinar_id=5');

  doc.querySelector('video source').dispatchEvent(new win.Event('error'));
  assert.equal(doc.querySelector('#video-error').hidden, false);
  doc.querySelector('#retry-video').click();
  assert.equal(doc.querySelector('#video-error').hidden, true);
  doc.querySelector('#edit-registration').click();
  form = doc.querySelector('#registration-form');
  assert.equal(form.elements.email.value, email);
  form.elements.marketingOptIn.checked = true;
  submit();
  await waitFor(() => doc.querySelector('#watch-now'));
  assert.equal(inspect().registrations.length, 1);
  assert.equal(inspect().registrations[0].id, initialId);
  assert.equal(inspect().registrations[0].marketing_opt_in, 1);
  doc.querySelector('#clear-registration').click();
  await waitFor(() => doc.querySelector('#registration-form'));
  assert.equal(doc.querySelector('video'), null);
  assert.equal(inspect().leadCount, 0);
  const disconnected = load('big-data-microsoft-azure', true);
  await waitFor(() => disconnected.window.document.querySelector('#retry-library'));
  assert.equal(disconnected.window.document.querySelector('video'), null);
});
