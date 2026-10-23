'use strict';

// Plain browser JavaScript; registration and video access are enforced by PHP/MySQL.
const WebinarRegistration = (() => {
  const { countryCodes } = RegistrationRules;
  const countryNames = new Intl.DisplayNames(['en'], { type: 'region' });
  const countries = countryCodes.map(code => ({ code, name: countryNames.of(code) }))
    .sort((a, b) => a.name.localeCompare(b.name));
  const escape = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const sampleSources = Object.freeze({
    'ai-data-resilience': 'https://www.youtube.com/watch?v=qnxAK_mdPLg',
    'cloud-security-ai': 'https://www.youtube.com/watch?v=QfFROJguRG4',
    'cloud-data-analytics': 'https://www.youtube.com/watch?v=orpgLYM-FYM',
    'cybersecurity-ai': 'https://www.youtube.com/watch?v=6wPcR6MrsIk',
    'big-data-microsoft-azure': 'https://www.youtube.com/watch?v=2ZuZV-dlyto',
    'ai-threat-detection': 'https://www.youtube.com/watch?v=tyEDiMVa58o',
  });

  async function mount(webinar) {
    const panel = document.querySelector('#registration-panel');
    const media = document.querySelector('#session-media');
    let activeRegistration = null;
    async function request(method = 'GET', data) {
      return WebinarAPI.request('registration', { webinar_id: webinar.id }, method, data);
    }

    function field(name, label, options = {}) {
      return `<div class="form-field"><label for="${name}">${label}${options.optional ? ' <span class="optional">(optional)</span>' : ' <span aria-hidden="true">*</span>'}</label><input id="${name}" name="${name}" type="${options.type || 'text'}" autocomplete="${options.autocomplete || 'off'}" ${options.optional ? '' : 'required'} maxlength="${options.max || 100}" ${options.placeholder ? `placeholder="${options.placeholder}"` : ''} aria-describedby="${name}-error"${options.type === 'tel' ? ' inputmode="tel"' : ''}><span id="${name}-error" class="field-error"></span></div>`;
    }

    function renderLockedMedia() {
      media.innerHTML = `<div class="locked-player"><div class="locked-art">${webinar.image ? `<img src="${escape(webinar.image)}" alt="" loading="lazy">` : ''}</div><div class="locked-message"><span class="play-symbol" aria-hidden="true">▶</span><span class="eyebrow">Your next perspective</span><h2>One quick step to watch.</h2><p>Register to open the video for this session.</p><button type="button" class="button" id="focus-registration">Register to watch <span aria-hidden="true">→</span></button></div></div><p class="media-note">Sample video available after registration.</p>`;
      media.querySelector('img')?.addEventListener('error', event => { event.target.hidden = true; }, { once: true });
      document.querySelector('#focus-registration').addEventListener('click', () => {
        if (!document.querySelector('#registration-form')) showForm(activeRegistration);
        document.querySelector('#firstName').focus();
        panel.scrollIntoView({ block: 'start', behavior: 'auto' });
      });
    }

    function renderPlayer(openAssistant = false) {
      WebinarPlayback.stop();
      const sampleSource = webinar.videoIsDemo ? sampleSources[webinar.slug] : null;
      media.innerHTML = `<section class="video-section" aria-labelledby="video-title"><div class="video-heading"><h2 id="video-title">Watch this session</h2>${webinar.videoIsDemo ? '<span class="badge demo-badge">Sample video</span>' : ''}</div><video id="webinar-video" controls playsinline preload="metadata" aria-label="${escape(webinar.title)} — ${escape(webinar.videoLabel)}"><source src="${escape(activeRegistration.videoUrl)}" type="video/mp4">Your browser does not support HTML5 video.</video><p id="video-error" class="form-feedback" role="alert" hidden>We couldn’t load the video. Check your connection and <button type="button" class="text-button" id="retry-video">try again</button>, or use the direct video link below.</p><div class="video-caption"><p>${webinar.videoIsDemo ? `<strong>Demo content:</strong> ${escape(webinar.videoLabel)}. This is a placeholder, not the original webinar recording.${sampleSource ? ` <a href=\"${escape(sampleSource)}\" target=\"_blank\" rel=\"noopener noreferrer\">Source on YouTube ↗</a>` : ''}` : escape(webinar.videoLabel)}</p><a href="${escape(activeRegistration.videoUrl)}" target="_blank" rel="noopener noreferrer">Open direct video ↗<span class="visually-hidden"> (opens in a new tab)</span></a></div></section>`;
      const video = document.querySelector('#webinar-video');
      const trackingNote=document.createElement('p');
      trackingNote.id='tracking-note'; trackingNote.className='media-note';
      trackingNote.textContent=activeRegistration.activityConsent ? 'Video activity is being saved with your consent.' : 'Video tracking is off. You can enable activity consent under Edit registration.';
      media.append(trackingNote);
      WebinarPlayback.attach(video,webinar,activeRegistration);
      const assistantHost=document.querySelector('#assistant-root');
      void WebinarChat.mount(assistantHost,webinar,activeRegistration,{openOnMount:openAssistant});
      const error = document.querySelector('#video-error');
      const showError = () => { error.hidden = false; };
      video.addEventListener('error', showError);
      video.querySelector('source').addEventListener('error', showError);
      video.addEventListener('loadedmetadata', () => { error.hidden = true; });
      document.querySelector('#retry-video').addEventListener('click', () => { error.hidden = true; video.load(); });
    }

    function privacyDialog() {
      return `<dialog class="privacy-dialog" id="privacy-dialog" aria-labelledby="privacy-title"><h2 id="privacy-title">Your details, your choice</h2><p>Your name, email, phone, company, country, and consent choices are saved in this app’s database and linked to the selected webinar. A browser session cookie remembers access for up to 30 days.</p><p>With optional activity consent, we also save video playback events, observed watch time, and webinar-assistant questions and responses. These records can be viewed by the site administrator. Questions, recent chat context, and relevant transcript passages are processed by the configured Ollama server. Contact fields are not sent to the model. You can switch off future activity collection by editing your registration.</p><p>Marketing consent is separate and optional. No marketing emails are sent by this prototype. Your details are not sent to sample video publishers.</p><p>Registrations inactive for 90 days are removed during cleanup. “Remove my registration” deletes this webinar registration and its video/chat activity. Your contact record is deleted when no registrations remain.</p><button class="button" type="button" id="close-privacy">Close notice</button></dialog>`;
    }

    function showForm(previous = null) {
      panel.innerHTML = `<p class="eyebrow">Explore this session</p><h2 id="access-title">Register to watch.</h2><p class="form-intro">Get instant access to the video for <strong>${escape(webinar.title)}</strong>.</p><p class="required-note">All fields required unless marked optional.</p><form id="registration-form" method="post" novalidate>
        <div class="form-row">${field('firstName', 'First name', { autocomplete: 'given-name', max: 80 })}${field('lastName', 'Last name', { autocomplete: 'family-name', max: 80 })}</div>
        ${field('email', 'Email address', { type: 'email', autocomplete: 'email', max: 254, placeholder: 'you@company.com' })}
        ${field('phone', 'Phone number', { type: 'tel', autocomplete: 'tel', max: 30, placeholder: '+91 98765 43210' })}
        ${field('company', 'Company name', { autocomplete: 'organization', max: 120 })}
        ${field('jobTitle', 'Job title', { autocomplete: 'organization-title', max: 100, optional: true })}
        <div class="form-field"><label for="country">Country / region <span aria-hidden="true">*</span></label><select id="country" name="country" autocomplete="country" required aria-describedby="country-error"><option value="">Select your country / region</option>${countries.map(country => `<option value="${country.code}">${escape(country.name)}</option>`).join('')}</select><span id="country-error" class="field-error"></span></div>
        <div class="honeypot" aria-hidden="true"><label for="companyWebsite">Leave this field blank</label><input id="companyWebsite" name="companyWebsite" type="text" tabindex="-1" autocomplete="off"></div>
        <div class="consent-block"><label class="check-label"><input id="privacyConsent" name="privacyConsent" type="checkbox" required aria-describedby="privacyConsent-error"><span>I agree to the privacy notice and the use of my details to register for this webinar. <span aria-hidden="true">*</span></span></label><span id="privacyConsent-error" class="field-error"></span><button type="button" class="text-button privacy-link" id="open-privacy">Read privacy notice</button>
        <label class="check-label marketing-choice"><input id="marketingOptIn" name="marketingOptIn" type="checkbox"><span>Yes, I’d like emails about future webinars and resources. <span class="optional">(optional)</span></span></label></div>
        <label class="check-label activity-choice"><input id="activityConsent" name="activityConsent" type="checkbox"><span>Save my video engagement and transcript-chat history for this webinar. <span class="optional">(optional)</span></span></label><p id="registration-feedback" class="form-feedback" role="alert" hidden></p><button class="button submit-registration" type="submit">Register &amp; watch <span aria-hidden="true">→</span></button><p class="demo-note">Marketing and activity consent are optional.<br>Change your choices under Edit registration.</p></form>${privacyDialog()}`;
      const form = document.querySelector('#registration-form');
      const feedback = document.querySelector('#registration-feedback');
      const dialog = document.querySelector('#privacy-dialog');
      let submitting = false;
      document.querySelector('#open-privacy').addEventListener('click', () => dialog.showModal());
      document.querySelector('#close-privacy').addEventListener('click', () => dialog.close());
      if (previous) {
        for (const name of ['firstName', 'lastName', 'email', 'phone', 'company', 'jobTitle', 'country']) {
          form.elements[name].value = previous[name] || '';
        }
        form.elements.privacyConsent.checked = previous.privacyConsent === true;
        form.elements.marketingOptIn.checked = previous.marketingOptIn === true;
        form.elements.activityConsent.checked = previous.activityConsent === true;
      }
      function validate(input) {
        const data = Object.fromEntries(new FormData(form));
        data.privacyConsent = form.elements.privacyConsent.checked;
        const message = RegistrationRules.validate(data)[input.name] || '';
        const error = document.getElementById(`${input.name}-error`);
        if (error) {
          error.textContent = message;
          input.setAttribute('aria-invalid', String(Boolean(message)));
        }
        return !message;
      }
      const inputs = [...form.querySelectorAll('input:not(#companyWebsite):not(#marketingOptIn):not(#activityConsent), select')];
      inputs.forEach(input => {
        input.addEventListener('blur', () => { if (input.value || input.getAttribute('aria-invalid') === 'true') validate(input); });
        input.addEventListener(input.type === 'checkbox' || input.tagName === 'SELECT' ? 'change' : 'input', () => {
          if (input.getAttribute('aria-invalid') === 'true') validate(input);
        });
      });
      form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submitting) return;
        feedback.hidden = true;
        const invalid = inputs.filter(input => !validate(input));
        if (invalid.length) {
          feedback.textContent = 'Please review the highlighted fields.';
          feedback.hidden = false;
          invalid[0].focus();
          return;
        }
        if (form.elements.companyWebsite.value) {
          feedback.textContent = 'We couldn’t submit this registration. Please try again.';
          feedback.hidden = false;
          return;
        }
        const submit = form.querySelector('[type="submit"]');
        submitting = true;
        submit.disabled = true;
        submit.textContent = 'Opening your video…';
        const data = Object.fromEntries(new FormData(form));
        data.privacyConsent = form.elements.privacyConsent.checked;
        data.marketingOptIn = form.elements.marketingOptIn.checked;
        data.activityConsent = form.elements.activityConsent.checked;
        try {
          activeRegistration = (await request('POST', data)).registration;
          renderPlayer(true);
          showSuccess();
          document.querySelector('#access-title').focus({ preventScroll: true });
          media.scrollIntoView({ behavior: 'auto', block: 'start' });
        } catch (error) {
          feedback.textContent = error.message || 'We couldn’t save your registration. Please try again.';
          feedback.hidden = false;
          if (error.fields) {
            for (const [name, message] of Object.entries(error.fields)) {
              const errorElement = document.getElementById(`${name}-error`);
              if (errorElement) { errorElement.textContent = message; form.elements[name].setAttribute('aria-invalid', 'true'); }
            }
            form.querySelector('[aria-invalid="true"]')?.focus();
          }
          submit.disabled = false;
          submit.innerHTML = 'Register &amp; watch <span aria-hidden="true">→</span>';
        } finally {
          submitting = false;
        }
      });
    }

    function showSuccess() {
      panel.innerHTML = `<div class="registration-success"><span class="success-icon" aria-hidden="true">✓</span><p class="eyebrow">You’re registered</p><h2 id="access-title" tabindex="-1">Your session is ready.</h2><p>Your registration for <strong>${escape(webinar.title)}</strong> is complete.</p><p class="registered-email">${escape(activeRegistration.email)}</p><button class="button" type="button" id="watch-now">Watch video <span aria-hidden="true">▶</span></button><button class="button secondary assistant-success-button" type="button" id="open-assistant">Open AI assistant <span aria-hidden="true">✦</span></button><dl class="registration-summary"><div><dt>Campaign</dt><dd>${escape(webinar.campaignId)}</dd></div><div><dt>Marketing emails</dt><dd>${activeRegistration.marketingOptIn ? 'Opted in' : 'Not opted in'}</dd></div></dl><p class="demo-note">Registration saved. No marketing emails are sent by this prototype.</p><button class="text-button" type="button" id="edit-registration">Edit registration</button><button class="text-button clear-registration" type="button" id="clear-registration">Remove my registration</button><p class="field-error" id="clear-error" role="alert" hidden></p></div>`;
      document.querySelector('#watch-now').addEventListener('click', () => {
        const video = document.querySelector('#webinar-video');
        media.scrollIntoView({ behavior: 'auto', block: 'start' });
        video.focus();
        video.play().catch(() => {
          // Native controls remain available if the browser disallows playback.
        });
      });
      document.querySelector('#open-assistant').addEventListener('click', () => document.querySelector('#assistant-launcher')?.click());
      document.querySelector('#edit-registration').addEventListener('click', () => { showForm(activeRegistration); document.querySelector('#firstName').focus(); });
      document.querySelector('#clear-registration').addEventListener('click', async () => {
        const remove = document.querySelector('#clear-registration');
        remove.disabled = true;
        try { await request('DELETE'); }
        catch (requestError) {
          const error = document.querySelector('#clear-error');
          error.textContent = requestError.message || 'Couldn’t remove your registration. Please try again.';
          error.hidden = false;
          remove.disabled = false;
          return;
        }
        document.querySelector('#webinar-video')?.pause();
        WebinarPlayback.stop();
        WebinarChat.unmount(document.querySelector('#assistant-root'));
        document.body.classList.remove('assistant-open');
        activeRegistration = null;
        renderLockedMedia();
        showForm();
        document.querySelector('#firstName').focus();
      });
    }

    renderLockedMedia();
    showForm();
    const submit = panel.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
      activeRegistration = (await request()).registration;
      if (activeRegistration) { renderPlayer(); showSuccess(); }
    } catch (error) {
      const feedback = document.querySelector('#registration-feedback');
      feedback.textContent = error.message || 'Registration is unavailable. Please check your connection and try again.';
      feedback.hidden = false;
    } finally {
      submit.disabled = false;
    }
  }

  return { mount };
})();
