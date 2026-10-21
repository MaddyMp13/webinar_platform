"use strict";

(() => {
  const app = document.querySelector("#dashboard-app");
  const escape = (value) =>
    String(value ?? "").replace(
      /[&<>"']/g,
      (char) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        })[char],
    );
  const labels = {
    users: "Registrations",
    video: "Video activity",
    chat: "Chatbot activity",
  };
  let view = ["users", "video", "chat"].includes(location.hash.slice(1))
    ? location.hash.slice(1)
    : "users";
  let page = 1,
    version = 0,
    authenticated = false,
    refreshTimer,
    latest;
  const filters = { webinarId: 0, search: "", from: "", to: "", status: "" };
  const clock = (value) => {
    if (!value) return "—";
    const date = new Date(
      /[zZ]|\+\d\d:\d\d$/.test(value) ? value : value.replace(" ", "T") + "Z",
    );
    return Number.isNaN(date.getTime())
      ? "—"
      : new Intl.DateTimeFormat("en-IN", {
          timeZone: "Asia/Kolkata",
          dateStyle: "medium",
          timeStyle: "short",
        }).format(date);
  };
  const duration = (value) => {
    const seconds = Math.round(Number(value) || 0);
    return seconds >= 3600
      ? `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`
      : `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
  };
  const number = (value) =>
    new Intl.NumberFormat("en-IN").format(Number(value) || 0);
  const tag = (text, color = "") =>
    `<span class="tag ${color}">${escape(text)}</span>`;

  async function open() {
    try {
      const state = await WebinarAPI.request("admin_status");
      authenticated = state.authenticated;
      if (authenticated) {
        shell(state.user);
        await refresh();
      } else authForm(state.setupRequired);
    } catch (error) {
      app.innerHTML = `<main id="dashboard-main" class="auth-layout"><div class="auth-card"><h1>Dashboard unavailable</h1><p>${escape(error.message)}</p><button type="button" class="primary" id="retry-auth">Try again</button></div></main>`;
      document.querySelector("#retry-auth").addEventListener("click", open);
    }
  }
  function authForm(setup) {
    clearInterval(refreshTimer);
    app.innerHTML = `<main id="dashboard-main" class="auth-layout"><section class="auth-card"><img src="Google-Logo-Tech-Company.png" alt="Google"><h1>${setup ? "Create your dashboard account" : "Sign in to your dashboard"}</h1><p>${setup ? "First-time setup is available locally on this computer. Choose your own admin email and password." : "View registrations, video engagement, and saved transcript-chat conversations."}</p><form id="admin-form">${setup ? '<label for="admin-name">Your name</label><input id="admin-name" name="name" autocomplete="name" maxlength="120" required>' : ""}<label for="admin-email">Admin email</label><input id="admin-email" name="email" type="email" autocomplete="username" maxlength="254" required><label for="admin-password">Password ${setup ? "(12–72 characters)" : ""}</label><input id="admin-password" name="password" type="password" autocomplete="${setup ? "new-password" : "current-password"}" ${setup ? 'minlength="12"' : ""} maxlength="72" required><p class="error" id="auth-error" role="alert" hidden></p><button class="primary" type="submit">${setup ? "Create account & open dashboard" : "Sign in"} →</button></form><a class="back-link" href="index.html">← Back to webinar library</a></section></main>`;
    document
      .querySelector("#admin-form")
      .addEventListener("submit", async (event) => {
        event.preventDefault();
        const form = event.currentTarget,
          button = form.querySelector("button"),
          error = document.querySelector("#auth-error");
        button.disabled = true;
        error.hidden = true;
        try {
          const result = await WebinarAPI.request(
            setup ? "admin_setup" : "admin_login",
            {},
            "POST",
            Object.fromEntries(new FormData(form)),
          );
          authenticated = true;
          shell(result.user);
          await refresh();
        } catch (failure) {
          error.textContent = failure.message;
          error.hidden = false;
          button.disabled = false;
        }
      });
  }
  function shell(user) {
    clearInterval(refreshTimer);
    app.innerHTML = `<div class="layout"><aside class="sidebar"><a class="side-brand" href="index.html"><img src="Google-Logo-Tech-Company.png" alt="Google"><span>Webinar dashboard</span></a><div class="nav-label">Workspace</div><nav class="side-nav" aria-label="Dashboard sections">${Object.entries(
      labels,
    )
      .map(
        ([id, label], index) =>
          `<button type="button" data-view="${id}" aria-current="${view === id ? "page" : "false"}"><span class="nav-icon" aria-hidden="true">${["▦", "▷", "▤"][index]}</span>${label}</button>`,
      )
      .join(
        "",
      )}</nav><div class="sidebar-bottom"><a href="index.html">← Webinar library</a><p>Private admin workspace<br>Display times: India Standard Time</p></div></aside><main class="workspace" id="dashboard-main"><header class="topbar"><div class="breadcrumb"><a href="index.html">Webinar library</a> / Dashboard</div><div class="account"><span>${escape(user.name)}</span><button class="text-button" id="logout" type="button">Sign out</button></div></header><div class="page-heading"><div><h1 id="view-title">${labels[view]}</h1><p id="view-subtitle"></p></div><div class="heading-actions"><button class="outline" type="button" id="refresh">↻ Refresh</button><button class="primary" type="button" id="export">↓ Export CSV</button></div></div><form class="filters" id="filters"><div class="filter-field"><label for="filter-webinar">Webinar</label><select id="filter-webinar"><option value="0">All webinars</option></select></div><div class="filter-field"><label for="filter-search">Find a user</label><input id="filter-search" type="search" maxlength="120" placeholder="Name, email, or company"></div><div class="filter-field"><label for="filter-from">From date</label><input id="filter-from" type="date"></div><div class="filter-field"><label for="filter-to">To date</label><input id="filter-to" type="date"></div><button class="outline filter-reset" id="reset" type="button">Clear filters</button><div class="filter-field status-filter" id="status-filter" hidden><label for="filter-status">Video status</label><select id="filter-status"><option value="">All statuses</option><option value="playing">Playing</option><option value="paused">Paused</option><option value="completed">Reached end</option><option value="ended">Ended</option><option value="abandoned">Inactive</option><option value="created">Created</option></select></div></form><p class="error" id="dashboard-error" role="alert" hidden></p><div id="dashboard-content" aria-live="polite"><p class="loading">Loading database records…</p></div><footer class="footer-status"><span id="fetched-at"></span><label class="auto-refresh"><input id="auto-refresh" type="checkbox" checked>Refresh every 30 seconds while this page is open</label></footer><details class="methods"><summary>About these metrics and data coverage</summary><p>Users are distinct contacts; registrations count a contact once per webinar. Video watch time adds observed playing intervals, excluding paused, buffering, and seek jumps; repeat viewing adds more watch time. Progress is the furthest observed position divided by duration, including seeking, and does not measure unique content watched. “Reached end” means the player emitted an ended event.</p><p>Date filters apply to registration time, viewing-session start, or conversation start for their respective metrics. Times are shown in Asia/Kolkata. Activity collection covers the on-page player and transcript assistant after explicit activity consent. Earlier video activity and direct-video-link playback cannot be reconstructed. Inactive sessions were last playing but have sent no updates for 45 seconds. Browser closure may leave only the last reliably observed position.</p><p>Records come from the MySQL leads, registrations, playback sessions/events, and chat conversations/messages tables. Chat replies currently return matching transcript excerpts; an AI model is not configured. CSV exports apply the same filters and include all matching records, up to 50,000 rows.</p></details></main></div><dialog class="detail-dialog" id="detail-dialog" aria-labelledby="detail-title"><div class="panel-heading"><h2 id="detail-title">Activity details</h2><button class="outline" type="button" id="close-detail">Close</button></div><div id="detail-body"></div></dialog>`;
    document.querySelector("#logout").addEventListener("click", async () => {
      try {
        await WebinarAPI.request("admin_logout", {}, "POST", {});
        authenticated = false;
        await open();
      } catch (error) {
        showError(error.message);
      }
    });
    document.querySelectorAll("[data-view]").forEach((button) =>
      button.addEventListener("click", () => {
        view = button.dataset.view;
        location.hash = view;
        page = 1;
        filters.status = "";
        document.querySelector("#filter-status").value = "";
        void refresh();
      }),
    );
    document
      .querySelector("#refresh")
      .addEventListener("click", () => void refresh());
    document
      .querySelector("#close-detail")
      .addEventListener("click", () =>
        document.querySelector("#detail-dialog").close(),
      );
    document
      .querySelector("#filters")
      .addEventListener("submit", (event) => event.preventDefault());
    let debounce;
    for (const [id, key] of [
      ["filter-webinar", "webinarId"],
      ["filter-search", "search"],
      ["filter-from", "from"],
      ["filter-to", "to"],
      ["filter-status", "status"],
    ]) {
      const input = document.getElementById(id);
      input.value = filters[key];
      input.addEventListener(
        id === "filter-search" ? "input" : "change",
        () => {
          filters[key] =
            key === "webinarId" ? Number(input.value) : input.value;
          page = 1;
          clearTimeout(debounce);
          debounce = setTimeout(
            () => void refresh(),
            id === "filter-search" ? 300 : 0,
          );
        },
      );
    }
    document.querySelector("#reset").addEventListener("click", () => {
      Object.assign(filters, {
        webinarId: 0,
        search: "",
        from: "",
        to: "",
        status: "",
      });
      for (const id of [
        "filter-webinar",
        "filter-search",
        "filter-from",
        "filter-to",
        "filter-status",
      ])
        document.getElementById(id).value = id === "filter-webinar" ? "0" : "";
      page = 1;
      void refresh();
    });
    document
      .querySelector("#export")
      .addEventListener("click", async (event) => {
        event.currentTarget.disabled = true;
        try {
          const blob = await WebinarAPI.exportData({ view, filters });
          const link = document.createElement("a");
          const url = URL.createObjectURL(blob);
          link.href = url;
          link.download = `webinar-${view}.csv`;
          link.click();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (error) {
          showError(error.message);
        } finally {
          const button = document.querySelector("#export");
          if (button) button.disabled = false;
        }
      });
    refreshTimer = setInterval(() => {
      if (
        authenticated &&
        !document.hidden &&
        document.querySelector("#auto-refresh")?.checked &&
        !document.querySelector("#detail-dialog")?.open
      )
        void refresh();
    }, 30000);
  }
  function showError(message) {
    const box = document.querySelector("#dashboard-error");
    if (box) {
      box.textContent = message;
      box.hidden = false;
    }
  }
  async function refresh() {
    const requestVersion = ++version;
    const button = document.querySelector("#refresh");
    if (button) button.disabled = true;
    try {
      const result = await WebinarAPI.request("dashboard", {}, "POST", {
        view,
        filters,
        page,
      });
      if (requestVersion !== version) return;
      latest = result;
      page = result.page;
      document.querySelector("#dashboard-error").hidden = true;
      const select = document.querySelector("#filter-webinar");
      select.innerHTML =
        '<option value="0">All webinars</option>' +
        result.webinars
          .map(
            (webinar) =>
              `<option value="${webinar.id}">${escape(webinar.title)}</option>`,
          )
          .join("");
      select.value = filters.webinarId;
      document.querySelector("#view-title").textContent = labels[view];
      document.querySelector("#view-subtitle").textContent = {
        users:
          "Contact details, webinar registrations, and saved consent choices.",
        video:
          "Observed watch time, playback positions, and individual player events.",
        chat: "Saved questions, transcript responses, and complete conversation history.",
      }[view];
      document.querySelector("#status-filter").hidden = view !== "video";
      document
        .querySelectorAll("[data-view]")
        .forEach((item) =>
          item.setAttribute(
            "aria-current",
            item.dataset.view === view ? "page" : "false",
          ),
        );
      render(result);
      document.querySelector("#fetched-at").textContent =
        "Last fetched " + clock(result.meta.fetchedAt) + " · MySQL";
    } catch (error) {
      if (error.status === 401) {
        authenticated = false;
        await open();
      } else showError(error.message);
    } finally {
      const current = document.querySelector("#refresh");
      if (current && requestVersion === version) current.disabled = false;
    }
  }
  function render(result) {
    const stats = result.summary;
    const cards = [
      [
        "Registered users",
        number(stats.users),
        "Distinct contacts in this period",
        "♙",
      ],
      [
        "Registrations",
        number(stats.registrations),
        "One contact × one webinar",
        "▦",
      ],
      [
        "Observed watch time",
        duration(stats.watchedSeconds),
        `${number(stats.sessions)} viewing sessions`,
        "▷",
      ],
      [
        "Chatbot conversations",
        number(stats.conversations),
        `${number(stats.questions)} saved questions`,
        "▤",
      ],
    ];
    const metricLabel = {
      users: "Registrations",
      video: "Time watched",
      chat: "Questions",
    }[view];
    const max = Math.max(
      1,
      ...result.breakdown.map((item) => Number(item.value)),
    );
    const bars = result.breakdown.length
      ? result.breakdown
          .map(
            (item) =>
              `<div class="bar-row"><span class="bar-label" title="${escape(item.title)}">${escape(item.title)}</span><div class="bar-track"><div class="bar-fill" data-width="${(Number(item.value) / max) * 100}"></div></div><span class="bar-value">${view === "video" ? duration(item.value) : number(item.value)}</span></div>`,
          )
          .join("")
      : '<div class="empty">No activity matches this selection.</div>';
    const titles = {
      users: [
        "User",
        "Contact",
        "Company / role",
        "Webinar",
        "Consent",
        "Activity",
        "Registered",
      ],
      video: [
        "Viewer",
        "Webinar",
        "Watched",
        "Last position",
        "Progress",
        "Status",
        "Started",
        "",
      ],
      chat: ["User", "Webinar", "Questions", "Responses", "Last message", ""],
    };
    const table = result.rows.length
      ? `<div class="table-wrap"><table><thead><tr>${titles[view].map((title) => `<th scope="col">${title}</th>`).join("")}</tr></thead><tbody>${result.rows.map((row) => rowMarkup(row)).join("")}</tbody></table></div>`
      : `<div class="empty"><strong>${{ users: "No registrations match these filters.", video: "No recorded video sessions yet.", chat: "No saved chatbot conversations yet." }[view]}</strong>${{ users: "Try another webinar, date range, or search.", video: "Activity starts when a registered viewer who opted in plays the on-page video.", chat: result.meta.transcriptWebinars ? "Registered viewers with activity consent can ask questions in the transcript assistant." : "Add an authorized webinar transcript to enable the transcript assistant. No conversations have been invented." }[view]}</div>`;
    document.querySelector("#dashboard-content").innerHTML =
      `<section class="metrics" aria-label="Summary">${cards.map(([label, value, context, icon]) => `<div class="metric"><div class="metric-label">${label}<span class="metric-icon" aria-hidden="true">${icon}</span></div><div class="metric-value">${value}</div><div class="metric-context">${context}</div></div>`).join("")}</section><div class="analysis-row"><section class="panel"><div class="panel-heading"><h2>${metricLabel} by webinar</h2><span class="unit-label">${view === "video" ? "Observed playing time" : "Selected filters"}</span></div><div class="bars">${bars}</div></section><section class="panel"><div class="panel-heading"><h2>Collection status</h2><span class="unit-label"><span class="live-dot"></span>Database connected</span></div><div class="coverage"><div class="coverage-row"><span>Activity consent</span><strong>${number(stats.activityConsented)} of ${number(stats.registrations)} registrations</strong></div><div class="coverage-row"><span>Transcripts in library</span><strong>${result.meta.transcriptWebinars} of ${result.webinars.length} webinars</strong></div><div class="coverage-row"><span>Playback progress / reached end</span><strong>${stats.averageProgress === null ? "—" : stats.averageProgress.toFixed(1) + "%"} / ${stats.reachedEnd}</strong></div><p class="coverage-note">Tracking began ${clock(result.meta.trackingStartedAt)}. Sample video activity is included. Only viewers who consent to activity collection are tracked.</p></div></section></div><section class="panel"><div class="records-heading"><h2>${labels[view]}</h2><span>${number(result.total)} ${view === "users" ? "registration records" : view === "video" ? "viewing sessions" : "conversations"}</span></div>${table}<div class="pagination"><span>${result.total ? `${(result.page - 1) * result.pageSize + 1}–${Math.min(result.page * result.pageSize, result.total)} of ${number(result.total)}` : "0 records"}</span><div class="pagination-actions"><button class="outline" type="button" id="previous-page" ${result.page <= 1 ? "disabled" : ""}>← Previous</button><button class="outline" type="button" id="next-page" ${result.page * result.pageSize >= result.total ? "disabled" : ""}>Next →</button></div></div></section>`;
    document.querySelectorAll("[data-width]").forEach((bar) => {
      bar.style.width =
        Math.min(100, Math.max(0, Number(bar.dataset.width))) + "%";
    });
    document.querySelector("#previous-page").addEventListener("click", () => {
      page--;
      void refresh();
    });
    document.querySelector("#next-page").addEventListener("click", () => {
      page++;
      void refresh();
    });
    document
      .querySelectorAll("[data-detail]")
      .forEach((button) =>
        button.addEventListener(
          "click",
          () => void details(button.dataset.detail),
        ),
      );
  }
  function rowMarkup(row) {
    const person = `<strong>${escape(row.name)}</strong><small>${escape(row.email)}</small>`;
    if (view === "users")
      return `<tr><td>${person}</td><td>${escape(row.phone)}<small>${escape(row.country)}</small></td><td>${escape(row.company)}<small>${escape(row.job_title) || "—"}</small></td><td class="webinar-cell" title="${escape(row.webinar)}">${escape(row.webinar)}</td><td>${tag(Number(row.marketing_opt_in) ? "Marketing: yes" : "Marketing: no", Number(row.marketing_opt_in) ? "green" : "gray")}<small>${Number(row.activity_consent) ? "Activity: opted in" : "Activity: off"}</small></td><td>${row.video_sessions} video sessions<small>${row.conversations} conversations</small></td><td>${clock(row.registered_at)}</td></tr>`;
    if (view === "video")
      return `<tr><td>${person}</td><td class="webinar-cell">${escape(row.webinar)}</td><td>${duration(row.watched_seconds)}</td><td>${duration(row.last_position_seconds)}</td><td><div class="progress"><div class="bar-track"><div class="bar-fill" data-width="${Number(row.completion_percent)}"></div></div>${Math.round(Number(row.completion_percent))}%</div></td><td>${tag(row.status === "completed" ? "Reached end" : row.status === "abandoned" ? "Inactive" : row.status, row.status === "completed" ? "green" : row.status === "abandoned" ? "amber" : "")}</td><td>${clock(row.started_at)}</td><td><button class="row-action" data-detail="${escape(row.id)}" type="button">View ${row.event_count} events →</button></td></tr>`;
    return `<tr><td>${person}</td><td class="webinar-cell">${escape(row.webinar)}</td><td>${row.question_count}</td><td>${row.response_count}</td><td>${clock(row.updated_at)}</td><td><button class="row-action" data-detail="${escape(row.id)}" type="button">Open conversation →</button></td></tr>`;
  }
  async function details(id, eventPage = 1) {
    const dialog = document.querySelector("#detail-dialog"),
      body = document.querySelector("#detail-body");
    if (!dialog.open) dialog.showModal();
    body.innerHTML = '<p class="loading">Loading activity…</p>';
    document.querySelector("#detail-title").textContent =
      view === "chat" ? "Conversation history" : "Video event timeline";
    try {
      const result = await WebinarAPI.request("dashboard_detail", {}, "POST", {
        view,
        id,
        page: eventPage,
      });
      const record = result.record;
      let content = `<p class="detail-meta"><strong>${escape(record.name || "Anonymous")}</strong> · ${escape(record.email || "")}<br>${escape(record.webinar)}</p>`;
      if (view === "chat")
        content += `<div class="detail-messages">${result.messages.map((message) => `<article class="message ${message.role === "user" ? "user" : ""}"><strong>${message.role === "user" ? "User" : "Transcript assistant"}</strong><small> · ${clock(message.created_at)}</small><p>${escape(message.message)}</p>${message.sources.length ? `<ul class="source-list">${message.sources.map((source) => `<li>${escape(source.source_label)}${source.start_seconds !== null ? " · " + duration(source.start_seconds) : ""}</li>`).join("")}</ul>` : ""}</article>`).join("") || '<p class="empty">No saved messages.</p>'}</div>`;
      else
        content += `<p class="detail-meta">Observed watch time: ${duration(record.watched_seconds)} · Last position: ${duration(record.last_position_seconds)} · Furthest progress: ${Number(record.completion_percent).toFixed(1)}%</p><div class="table-wrap"><table><thead><tr><th>Event</th><th>Position</th><th>Seek</th><th>Watch added</th><th>Observed at</th></tr></thead><tbody>${result.events.map((event) => `<tr><td>${escape(event.event_type)}</td><td>${duration(event.position_seconds)}</td><td>${event.previous_position_seconds !== null ? duration(event.previous_position_seconds) + " → " + duration(event.new_position_seconds) : "—"}</td><td>${Number(event.watched_delta_seconds).toFixed(1)}s</td><td>${clock(event.occurred_at)}</td></tr>`).join("")}</tbody></table></div><div class="pagination"><span>${result.total} saved events · page ${result.page}</span><div class="pagination-actions"><button class="outline" id="detail-prev" ${result.page <= 1 ? "disabled" : ""}>Previous</button><button class="outline" id="detail-next" ${result.page * 100 >= result.total ? "disabled" : ""}>Next</button></div></div>`;
      body.innerHTML = content;
      document
        .querySelector("#detail-prev")
        ?.addEventListener("click", () => void details(id, eventPage - 1));
      document
        .querySelector("#detail-next")
        ?.addEventListener("click", () => void details(id, eventPage + 1));
    } catch (error) {
      body.innerHTML = `<p class="error">${escape(error.message)}</p>`;
    }
  }
  void open();
})();
