'use strict';

// Keep the original campaign records as the single source for every page.
const slugs = ['ai-data-resilience', 'cloud-security-ai', 'cloud-data-analytics', 'cybersecurity-ai', 'big-data-microsoft-azure', 'ai-threat-detection'];
const webinars = campaigns.map((campaign, index) => ({ ...campaign, slug: slugs[index] }));
const main = document.querySelector('#main');
const escapeHTML = value => String(value).replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
const webinarURL = webinar => `webinar.html?slug=${encodeURIComponent(webinar.slug)}`;

function artwork(webinar, extraClass = '') {
  return `<div class="art ${extraClass}"><img src="${escapeHTML(webinar.image)}" alt="${escapeHTML(webinar.title)} webinar artwork" loading="lazy"><span class="fallback" aria-hidden="true">${escapeHTML(webinar.category)}</span></div>`;
}

function handleImages() {
  main.querySelectorAll('.art img').forEach(image => {
    const fallback = () => image.parentElement.classList.add('failed');
    image.addEventListener('error', fallback, { once: true });
    if (image.complete && image.naturalWidth === 0) fallback();
  });
}

function card(webinar) {
  return `<a class="card" href="${webinarURL(webinar)}" aria-label="View details: ${escapeHTML(webinar.title)}">
    ${artwork(webinar)}<div class="card-body"><div class="meta"><span class="badge">${escapeHTML(webinar.category)}</span><span>•</span><span>${escapeHTML(webinar.date)}</span></div>
    <h2>${escapeHTML(webinar.title)}</h2><p>${escapeHTML(webinar.description)}</p><span class="card-action">View details <span aria-hidden="true">↗</span></span></div></a>`;
}

function relatedWebinars(selected, limit = 3) {
  return webinars.filter(webinar => webinar.id !== selected.id)
    .sort((a, b) => Number(b.category === selected.category) - Number(a.category === selected.category) || a.id - b.id)
    .slice(0, limit);
}

function renderLibrary() {
  document.title = 'Webinar Library | AI, Cloud & Data';
  const params = new URLSearchParams(location.search);
  const categories = ['All topics', ...new Set(webinars.map(webinar => webinar.category))];
  let category = categories.includes(params.get('category')) ? params.get('category') : 'All topics';
  main.innerHTML = `<section class="hero"><p class="eyebrow">Learn. Connect. Move forward.</p><h1>Big ideas. <span>Real-world impact.</span></h1><p>Explore conversations at the intersection of technology and possibility.<br>Find your next perspective on AI, cloud, data, and security.</p></section>
    <section class="library" aria-label="Browse webinars"><div class="toolbar"><label class="search"><span aria-hidden="true">⌕</span><input id="search" type="search" placeholder="Search webinars…" aria-label="Search webinars"></label><label class="sort">Sort by <select id="sort"><option value="default">Featured</option><option value="az">Title: A–Z</option><option value="za">Title: Z–A</option></select></label></div>
    <div class="filters" role="group" aria-label="Filter by topic">${categories.map(name => `<button class="chip" type="button" data-category="${escapeHTML(name)}" aria-pressed="${name === category}">${escapeHTML(name)}</button>`).join('')}</div><p class="results" id="results" role="status" aria-live="polite"></p><div class="grid" id="cards"></div></section>`;
  const search = document.querySelector('#search');
  const sort = document.querySelector('#sort');
  search.value = params.get('q') || '';
  sort.value = ['az', 'za'].includes(params.get('sort')) ? params.get('sort') : 'default';
  function update() {
    const query = search.value.trim().toLocaleLowerCase();
    const matches = webinars.filter(webinar => (category === 'All topics' || webinar.category === category) && `${webinar.title} ${webinar.description} ${webinar.category}`.toLocaleLowerCase().includes(query));
    if (sort.value !== 'default') matches.sort((a, b) => a.title.localeCompare(b.title) * (sort.value === 'az' ? 1 : -1));
    document.querySelector('#results').textContent = `Showing ${matches.length} of ${webinars.length} webinars${category === 'All topics' ? '' : ` in ${category}`}`;
    document.querySelector('#cards').innerHTML = matches.length ? matches.map(card).join('') : '<div class="empty"><h2>No webinars found</h2><p>Try a different search or explore all topics.</p><button type="button" class="button secondary" id="reset">Clear filters</button></div>';
    const reset = document.querySelector('#reset');
    if (reset) reset.addEventListener('click', () => { search.value = ''; category = 'All topics'; sort.value = 'default'; update(); search.focus(); });
    main.querySelectorAll('[data-category]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.category === category)));
    const url = new URL(location.href);
    url.search = '';
    if (search.value) url.searchParams.set('q', search.value);
    if (category !== 'All topics') url.searchParams.set('category', category);
    if (sort.value !== 'default') url.searchParams.set('sort', sort.value);
    history.replaceState(null, '', url);
    handleImages();
  }
  main.querySelectorAll('[data-category]').forEach(button => button.addEventListener('click', () => { category = button.dataset.category; update(); }));
  search.addEventListener('input', update);
  sort.addEventListener('change', update);
  update();
}

function renderDetail() {
  const params = new URLSearchParams(location.search);
  const selected = params.has('slug') ? webinars.find(webinar => webinar.slug === params.get('slug')) : webinars.find(webinar => String(webinar.id) === params.get('id'));
  if (!selected) {
    document.title = 'Webinar not found | Webinar Library';
    main.innerHTML = '<section class="detail"><div class="empty"><p class="eyebrow">Webinar unavailable</p><h1>We couldn’t find that webinar.</h1><p>The link may be incomplete or the campaign may no longer exist.</p><a class="button" href="index.html">Browse all webinars</a></div></section>';
    return;
  }
  document.title = `${selected.title} | Webinar Library`;
  main.innerHTML = `<article class="detail"><nav class="breadcrumbs" aria-label="Breadcrumb"><a href="index.html">Webinar library</a><span aria-hidden="true">/</span><span aria-current="page">${escapeHTML(selected.title)}</span></nav>
    <div class="detail-layout"><section><div class="meta"><span class="badge">${escapeHTML(selected.category)}</span><span>•</span><span>${escapeHTML(selected.date)}</span></div><h1>${escapeHTML(selected.title)}</h1><p class="lead">${escapeHTML(selected.description)}</p><div id="session-media">${artwork(selected, 'banner')}</div><h2>About this webinar</h2><p class="body-copy">${escapeHTML(selected.description)}</p><p class="body-copy">Visit the original event page for speaker information and the full agenda. The on-page player uses a sample video while the webinar recording is being configured.</p><a class="publisher-link" href="${escapeHTML(selected.webinarLink)}" target="_blank" rel="noopener noreferrer">Original event page ↗<span class="visually-hidden"> (opens in a new tab)</span></a></section>
    <aside class="aside registration-aside" id="registration-panel" aria-labelledby="access-title"></aside></div>
    <section class="related" aria-labelledby="related-title"><div class="section-title"><h2 id="related-title">Related webinars</h2><a href="index.html">All webinars →</a></div><p class="body-copy">Keep exploring ideas from the webinar library.</p><div class="grid">${relatedWebinars(selected).map(card).join('')}</div></section></article>`;
  handleImages();
  WebinarRegistration.mount(selected);
}

if (location.pathname.endsWith('/webinar.html')) renderDetail();
else renderLibrary();
