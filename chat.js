"use strict";

const WebinarChat = (() => {
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
  const time = (value) => {
    const n = Math.max(0, Math.floor(Number(value)));
    return `${Math.floor(n / 60)}:${String(n % 60).padStart(2, "0")}`;
  };
  const sleep = (milliseconds) =>
    new Promise((resolve) => setTimeout(resolve, milliseconds));

  function sourceList(list) {
    if (!(list || []).length) return "";
    return `<ul class="chat-sources">${list
      .map(
        (source) =>
          `<li>${
            source.start_seconds !== null && source.transcript_chunk_id !== null
              ? `<button class="source-link" type="button" data-seek="${Number(source.start_seconds)}">${time(source.start_seconds)} · ${escape(source.source_label)}</button>`
              : `<span>${escape(source.source_label)}${source.transcript_chunk_id === null ? " · previous transcript version" : ""}</span>`
          }${source.quote ? `<blockquote>${escape(source.quote)}</blockquote>` : ""}</li>`,
      )
      .join("")}</ul>`;
  }

  async function mount(host, webinar, registration, options = {}) {
    if (!host) return;
    if (typeof host._webinarChatCleanup === "function")
      host._webinarChatCleanup();
    document.body.classList.remove("assistant-open");
    host.innerHTML = `<button class="assistant-launcher" id="assistant-launcher" type="button" aria-controls="assistant-drawer" aria-expanded="false"><span class="assistant-launcher-icon" aria-hidden="true">✦</span><span><strong>Webinar AI</strong><small>Summary &amp; questions</small></span></button>
      <div class="assistant-backdrop" id="assistant-backdrop" hidden></div>
      <aside class="assistant-drawer" id="assistant-drawer" aria-label="Webinar AI assistant" aria-hidden="true">
        <header class="assistant-drawer-header"><div><span class="assistant-kicker">AI companion</span><h2>Explore this webinar</h2></div><button class="assistant-close" id="assistant-close" type="button" aria-label="Close assistant">×</button></header>
        <div class="assistant-connection"><span class="assistant-dot" aria-hidden="true"></span><span id="assistant-badge">Connecting…</span><span id="chat-status" role="status">Checking the transcript</span></div>
        <nav class="assistant-tabs" role="tablist" aria-label="Webinar resources">
          <button type="button" id="tab-ask" role="tab" aria-controls="panel-ask" aria-selected="true" data-tab="ask">Chat</button>
          <button type="button" id="tab-summary" role="tab" aria-controls="panel-summary" aria-selected="false" tabindex="-1" data-tab="summary">Summary</button>
          <button type="button" id="tab-transcript" role="tab" aria-controls="panel-transcript" aria-selected="false" tabindex="-1" data-tab="transcript">Transcript</button>
        </nav>
        <div class="assistant-drawer-scroll">
          <section role="tabpanel" id="panel-ask" aria-labelledby="tab-ask">
            <div class="assistant-overview"><span aria-hidden="true">✦</span><div><strong>Video summary</strong><p id="summary-preview">The transcript is being prepared. Your summary will appear here automatically.</p><button type="button" class="assistant-inline-button" id="open-summary">View full summary →</button></div></div>
            <div class="conversation-tools"><label for="chat-conversation">Saved chats</label><select id="chat-conversation"><option value="">New conversation</option></select><button class="text-button" id="chat-new" type="button">+ New</button></div>
            <div id="chat-messages" class="chat-messages" role="log" aria-live="polite"></div>
            <p class="assistant-hint" id="chat-hint"></p><button class="button secondary assistant-consent-button" id="enable-chat-consent" type="button" hidden>Enable chat &amp; activity</button>
          </section>
          <section role="tabpanel" id="panel-summary" aria-labelledby="tab-summary" hidden>
            <div class="assistant-section-heading"><div><span class="assistant-kicker">From the recording</span><h3>Summary &amp; takeaways</h3></div></div>
            <p class="assistant-hint">The summary uses this webinar’s transcript and links its claims to source passages.</p>
            <div class="summary-actions"><button class="button" type="button" id="summary-generate">Generate summary</button><button class="text-button" type="button" id="summary-stop" hidden>Stop after this section</button></div>
            <p id="summary-status" role="status" class="assistant-hint"></p><div id="summary-content"></div>
          </section>
          <section role="tabpanel" id="panel-transcript" aria-labelledby="tab-transcript" hidden>
            <div class="assistant-section-heading"><div><span class="assistant-kicker">Searchable text</span><h3>Video transcript</h3></div><a class="transcript-download" id="transcript-download" href="#" download hidden>Download ↓</a></div>
            <form id="transcript-search" class="transcript-search"><label for="transcript-query">Search the transcript</label><div><input id="transcript-query" type="search" maxlength="120" placeholder="Find a topic or phrase"><button class="button" type="submit">Search</button></div></form>
            <p id="transcript-count" class="assistant-hint" role="status"></p><div id="transcript-chunks"></div><button class="text-button" type="button" id="transcript-more" hidden>Load more passages ↓</button>
          </section>
          <p id="chat-feedback" class="assistant-error" role="alert" hidden></p>
        </div>
        <form id="chat-form" class="assistant-composer" hidden><label class="visually-hidden" for="chat-question">Ask about this webinar</label><textarea id="chat-question" rows="1" minlength="3" maxlength="1500" required placeholder="Ask a question about the video…"></textarea><button type="submit" aria-label="Send question">↑</button></form>
      </aside>`;

    const $ = (selector) => host.querySelector(selector);
    const params = { webinar_id: webinar.id };
    let state = null;
    let conversationId = null;
    let newConversation = false;
    let retry = null;
    let busy = false;
    let transcriptLoaded = false;
    let transcriptRequest = 0;
    let nextOffset = null;
    let search = "";
    let summaryLoaded = false;
    let summaryLoading = false;
    let summary = null;
    let generating = false;
    let stopSummary = false;
    let transcriptPolling = false;
    let transcriptFailure = "";
    const hasActivityConsent = () =>
      Boolean(state?.activityConsent ?? registration.activityConsent);

    const clearError = () => {
      $("#chat-feedback").hidden = true;
    };
    const showError = (error) => {
      $("#chat-feedback").textContent = error.message || String(error);
      $("#chat-feedback").hidden = false;
    };
    const downloadUrl = WebinarAPI.url("transcript_download", params);

    function openDrawer() {
      $("#assistant-drawer").classList.add("is-open");
      $("#assistant-drawer").setAttribute("aria-hidden", "false");
      $("#assistant-launcher").setAttribute("aria-expanded", "true");
      $("#assistant-backdrop").hidden = false;
      requestAnimationFrame(() =>
        $("#assistant-backdrop").classList.add("is-open"),
      );
      document.body.classList.add("assistant-open");
      setTimeout(() => $("#assistant-close").focus(), 100);
    }
    function closeDrawer() {
      $("#assistant-drawer").classList.remove("is-open");
      $("#assistant-drawer").setAttribute("aria-hidden", "true");
      $("#assistant-launcher").setAttribute("aria-expanded", "false");
      $("#assistant-backdrop").classList.remove("is-open");
      setTimeout(() => {
        if ($("#assistant-drawer").getAttribute("aria-hidden") === "true")
          $("#assistant-backdrop").hidden = true;
      }, 220);
      document.body.classList.remove("assistant-open");
      $("#assistant-launcher").focus();
    }
    $("#assistant-launcher").addEventListener("click", openDrawer);
    $("#assistant-close").addEventListener("click", closeDrawer);
    $("#assistant-backdrop").addEventListener("click", closeDrawer);
    const escapeHandler = (event) => {
      const drawer = $("#assistant-drawer");
      if (event.key === "Escape" && drawer?.classList.contains("is-open"))
        closeDrawer();
    };
    document.addEventListener("keydown", escapeHandler, { once: false });
    host._webinarChatCleanup = () => {
      document.removeEventListener("keydown", escapeHandler);
      document.body.classList.remove("assistant-open");
    };

    function renderMessages(messages) {
      $("#chat-messages").innerHTML = messages.length
        ? messages
            .map(
              (message) =>
                `<article class="chat-message ${message.role === "user" ? "from-user" : "from-assistant"}"><div class="message-author"><strong>${message.role === "user" ? "You" : "Webinar AI"}</strong>${message.role !== "user" ? `<span>${escape(message.model_name === "transcript-excerpts" ? "Transcript excerpts" : message.model_name === "insufficient-evidence" ? "Not found in transcript" : message.model_name || "Saved answer")}</span>` : ""}</div><p>${escape(message.message)}</p>${sourceList(message.sources)}</article>`,
            )
            .join("")
        : '<div class="chat-welcome"><span aria-hidden="true">✦</span><h3>Ask about the video</h3><p>Try “What are the main ideas?” or ask about something the speaker said.</p></div>';
      const log = $("#chat-messages");
      log.scrollTop = log.scrollHeight;
    }
    function listConversations(list) {
      $("#chat-conversation").innerHTML =
        '<option value="">New conversation</option>' +
        list
          .map(
            (item) =>
              `<option value="${escape(item.id)}">${escape(item.title || "Conversation")}</option>`,
          )
          .join("");
      $("#chat-conversation").value = conversationId || "";
    }
    function setBusy(value) {
      busy = value;
      const ready =
        state?.transcriptReady &&
        hasActivityConsent() &&
        state?.assistant.available;
      $("#chat-form button").disabled = value || !ready;
      $("#chat-question").disabled = value || !ready;
      $("#chat-conversation").disabled = value || !ready;
      $("#chat-new").disabled = value || !ready;
      $("#chat-form button").textContent = value ? "…" : "↑";
    }
    function updateSummaryControl() {
      const button = $("#summary-generate");
      const ready = Boolean(state?.transcriptReady);
      const assistantReady = Boolean(
        state?.assistant.available && state?.mode === "ollama",
      );
      const saved = summary ? Object.keys(summary.parts || {}).length : 0;
      const complete = Boolean(summary && saved === summary.totalParts);
      if (transcriptFailure) button.textContent = "Transcript setup required";
      else if (!ready) button.textContent = "Waiting for transcript";
      else if (summaryLoading) button.textContent = "Loading summary…";
      else if (generating) button.textContent = "Generating…";
      else if (complete) button.textContent = "Summary complete";
      else if (saved) button.textContent = "Continue summary";
      else button.textContent = "Generate summary";
      button.disabled =
        !ready || !assistantReady || summaryLoading || generating || complete;
      button.setAttribute("aria-busy", String(summaryLoading || generating));
    }
    function updateAvailability() {
      const ready = Boolean(state?.transcriptReady);
      const consent = hasActivityConsent();
      $("#transcript-download").hidden = !ready;
      $("#transcript-download").href = ready ? downloadUrl : "#";
      $("#chat-form").hidden = !consent;
      $("#enable-chat-consent").hidden = consent;
      $("#chat-new").textContent = ready && consent ? "+ New" : "Unavailable";
      updateSummaryControl();
      setBusy(false);
      if (!consent)
        $("#chat-hint").textContent =
          "Chat history is off. Enable activity consent to ask questions and save conversations.";
      else if (transcriptFailure)
        $("#chat-hint").textContent =
          "Chat will unlock after the transcription service is configured and the transcript is ready.";
      else if (!ready)
        $("#chat-hint").textContent =
          "Faster-Whisper is preparing the transcript. Chat will unlock automatically.";
      else if (!state?.assistant.available)
        $("#chat-hint").textContent =
          state?.assistant.message ||
          "The AI service is unavailable. The transcript can still be read and downloaded.";
      else
        $("#chat-hint").textContent =
          "Answers are based only on this webinar transcript and include source links.";
    }
    async function refreshState() {
      state = await WebinarAPI.request("chat", params);
      transcriptFailure = "";
      conversationId = state.conversationId;
      renderMessages(state.messages);
      listConversations(state.conversations);
      $("#assistant-badge").textContent =
        state.mode === "ollama"
          ? `Ollama · ${state.assistant.model || "AI"}`
          : "Transcript mode";
      $("#chat-status").textContent = state.transcriptReady
        ? "Transcript ready"
        : "Preparing transcript";
      updateAvailability();
    }
    async function loadConversation(id) {
      clearError();
      setBusy(true);
      try {
        const result = await WebinarAPI.request("chat", {
          ...params,
          ...(id ? { conversation_id: id } : {}),
        });
        conversationId = result.conversationId;
        newConversation = false;
        renderMessages(result.messages);
        listConversations(result.conversations);
      } catch (error) {
        showError(error);
      } finally {
        setBusy(false);
      }
    }
    async function loadTranscript(offset = 0) {
      if (!state?.transcriptReady) {
        $("#transcript-count").textContent =
          "The transcript is still being prepared.";
        return;
      }
      const request = ++transcriptRequest;
      const phrase = search;
      clearError();
      $("#transcript-more").disabled = true;
      try {
        const result = await WebinarAPI.request("transcript", params, "POST", {
          search: phrase,
          offset,
        });
        if (request !== transcriptRequest) return;
        const markup = result.chunks
          .map(
            (chunk) =>
              `<article class="transcript-passage">${chunk.start_seconds !== null ? `<button type="button" class="source-link" data-seek="${Number(chunk.start_seconds)}">${time(chunk.start_seconds)} – ${time(chunk.end_seconds)}</button>` : '<span class="passage-label">Text transcript · no timestamp</span>'}<p>${escape(chunk.chunk_text)}</p></article>`,
          )
          .join("");
        if (!offset) $("#transcript-chunks").innerHTML = markup;
        else $("#transcript-chunks").insertAdjacentHTML("beforeend", markup);
        $("#transcript-count").textContent = result.total
          ? `${result.total} passage${result.total === 1 ? "" : "s"}${phrase ? ` matching “${phrase}”` : ""}.`
          : phrase
            ? "No matching passages. Try another phrase."
            : "No transcript passages are available.";
        if (result.transcription)
          $("#transcript-count").textContent +=
            ` ${result.transcription.notice}`;
        nextOffset = result.nextOffset;
        $("#transcript-more").hidden = nextOffset === null;
        transcriptLoaded = true;
      } catch (error) {
        if (request === transcriptRequest) showError(error);
      } finally {
        if (request === transcriptRequest)
          $("#transcript-more").disabled = false;
      }
    }
    function renderSummary() {
      if (!summary) return;
      const parts = Object.entries(summary.parts).sort(
        (a, b) => Number(a[0]) - Number(b[0]),
      );
      const saved = parts.length;
      $("#summary-status").textContent =
        `${saved} of ${summary.totalParts} transcript sections summarized${saved === summary.totalParts ? " · complete." : "."}`;
      $("#summary-content").innerHTML = parts
        .map(
          ([index, part]) =>
            `<section class="summary-section"><h3>Section ${Number(index) + 1}</h3>${[
              "summary",
              "takeaway",
              "topic",
              "action",
            ]
              .map((kind) => {
                const items = part.items.filter((item) => item.kind === kind);
                return items.length
                  ? `<h4>${{ summary: "Summary", takeaway: "Key takeaways", topic: "Topics & concepts", action: "Action items" }[kind]}</h4>${items.map((item) => `<div class="summary-point"><p>${escape(item.text)}</p>${sourceList(item.sources)}</div>`).join("")}`
                  : "";
              })
              .join("")}</section>`,
        )
        .join("");
      const preview =
        parts
          .flatMap(([, part]) => part.items || [])
          .find((item) => item.kind === "summary") ||
        parts.flatMap(([, part]) => part.items || [])[0];
      if (preview) $("#summary-preview").textContent = preview.text;
      updateSummaryControl();
    }
    async function loadSummary(autoGenerate = false) {
      if (!state?.transcriptReady) {
        $("#summary-status").textContent =
          transcriptFailure ||
          "The summary will be available after transcription finishes.";
        updateAvailability();
        return;
      }
      if (summaryLoading) return;
      let shouldGenerate = false;
      summaryLoading = true;
      updateSummaryControl();
      try {
        summary = await WebinarAPI.request("insights", params);
        summaryLoaded = true;
        renderSummary();
        shouldGenerate =
          autoGenerate &&
          state.assistant.available &&
          state.mode === "ollama" &&
          Object.keys(summary.parts).length < summary.totalParts;
      } catch (error) {
        showError(error);
      } finally {
        summaryLoading = false;
        updateSummaryControl();
      }
      if (shouldGenerate) await generateSummary();
    }
    async function generateSummary() {
      if (generating || !summary) return;
      clearError();
      generating = true;
      stopSummary = false;
      $("#summary-stop").hidden = false;
      $("#summary-stop").disabled = false;
      renderSummary();
      try {
        for (let part = 0; part < summary.totalParts; part += 1) {
          if (summary.parts[part]) continue;
          if (stopSummary || !host.isConnected) break;
          $("#summary-status").textContent =
            `Generating section ${part + 1} of ${summary.totalParts}…`;
          const result = await WebinarAPI.request("insights", params, "POST", {
            part,
            version: summary.version,
          });
          summary.parts[result.part] = result.result;
          renderSummary();
        }
      } catch (error) {
        showError(error);
      } finally {
        generating = false;
        $("#summary-stop").hidden = true;
        renderSummary();
      }
    }
    async function prepareTranscript() {
      if (state?.transcriptReady || transcriptPolling) return;
      transcriptPolling = true;
      transcriptFailure = "";
      updateAvailability();
      try {
        let progress = await WebinarAPI.request(
          "transcription_request",
          params,
          "POST",
          {},
        );
        for (
          let attempt = 0;
          attempt < 100 && host.isConnected && !progress.ready;
          attempt += 1
        ) {
          $("#chat-status").textContent =
            progress.job?.message ||
            progress.message ||
            "Faster-Whisper is transcribing the video";
          await sleep(6000);
          progress = await WebinarAPI.request("transcription_request", params);
          if (["failed", "cancelled"].includes(progress.job?.status))
            throw new Error(
              progress.job.message ||
                "Automatic transcription could not finish.",
            );
        }
        if (!progress.ready)
          throw new Error(
            "Transcription is taking longer than expected. It will continue on the server; reopen the assistant shortly.",
          );
        await refreshState();
        transcriptLoaded = false;
        summaryLoaded = false;
        await loadSummary(true);
      } catch (error) {
        transcriptFailure =
          error.message || "Automatic transcription could not start.";
        $("#chat-status").textContent = "Transcript unavailable";
        $("#summary-preview").textContent = transcriptFailure;
        $("#summary-status").textContent = transcriptFailure;
        $("#transcript-count").textContent = transcriptFailure;
        updateAvailability();
        showError(error);
      } finally {
        transcriptPolling = false;
      }
    }

    const tabs = [...host.querySelectorAll('[role="tab"]')];
    function showTab(tab) {
      clearError();
      for (const item of tabs) {
        const active = item === tab;
        item.setAttribute("aria-selected", String(active));
        item.tabIndex = active ? 0 : -1;
        $(`#panel-${item.dataset.tab}`).hidden = !active;
      }
      if (tab.dataset.tab === "transcript" && !transcriptLoaded)
        void loadTranscript();
      if (tab.dataset.tab === "summary" && !summaryLoaded) void loadSummary();
      $("#chat-form").classList.toggle(
        "is-hidden-by-tab",
        tab.dataset.tab !== "ask",
      );
    }
    tabs.forEach((tab, index) => {
      tab.addEventListener("click", () => showTab(tab));
      tab.addEventListener("keydown", (event) => {
        let next;
        if (event.key === "ArrowRight") next = (index + 1) % tabs.length;
        if (event.key === "ArrowLeft")
          next = (index + tabs.length - 1) % tabs.length;
        if (event.key === "Home") next = 0;
        if (event.key === "End") next = tabs.length - 1;
        if (next !== undefined) {
          event.preventDefault();
          tabs[next].focus();
          showTab(tabs[next]);
        }
      });
    });
    $("#open-summary").addEventListener("click", () => {
      showTab($("#tab-summary"));
      $("#tab-summary").focus();
    });
    $("#enable-chat-consent").addEventListener("click", () => {
      closeDrawer();
      document.querySelector("#edit-registration")?.click();
      setTimeout(() => document.querySelector("#activityConsent")?.focus(), 0);
    });
    $("#transcript-search").addEventListener("submit", (event) => {
      event.preventDefault();
      search = $("#transcript-query").value.trim();
      void loadTranscript();
    });
    $("#transcript-more").addEventListener("click", () => {
      if (nextOffset !== null) void loadTranscript(nextOffset);
    });
    function beginNewConversation() {
      if (busy || !state?.transcriptReady || !hasActivityConsent()) return;
      conversationId = null;
      newConversation = true;
      retry = null;
      $("#chat-conversation").value = "";
      $("#chat-question").value = "";
      renderMessages([]);
      clearError();
      $("#chat-hint").textContent =
        "New conversation ready. Ask the first question to save it.";
      $("#chat-question").focus();
    }
    $("#chat-conversation").addEventListener("change", () => {
      const id = $("#chat-conversation").value;
      if (id) void loadConversation(id);
      else beginNewConversation();
    });
    $("#chat-new").addEventListener("click", beginNewConversation);
    $("#summary-stop").addEventListener("click", () => {
      stopSummary = true;
      $("#summary-stop").disabled = true;
    });
    $("#summary-generate").addEventListener("click", async () => {
      if (!summaryLoaded) await loadSummary();
      if (summary) await generateSummary();
    });
    host.addEventListener("click", (event) => {
      const source = event.target.closest("[data-seek]");
      if (!source) return;
      const video = document.querySelector("#webinar-video");
      const position = Number(source.dataset.seek);
      if (!video || !Number.isFinite(position) || position < 0) return;
      if (Number.isFinite(video.duration) && position > video.duration) {
        showError(
          new Error(
            "This timestamp is beyond the current video. Check that the transcript matches the recording.",
          ),
        );
        return;
      }
      video.currentTime = position;
      video.focus();
      $("#chat-status").textContent = `Video moved to ${time(position)}`;
    });
    $("#chat-form").addEventListener("submit", async (event) => {
      event.preventDefault();
      if (busy) return;
      clearError();
      const question = $("#chat-question").value.trim();
      if (question.length < 3) {
        showError(new Error("Ask a question with at least 3 characters."));
        return;
      }
      if (
        !retry ||
        retry.question !== question ||
        retry.conversationId !== conversationId
      )
        retry = {
          question,
          conversationId,
          newConversation,
          requestId: crypto.randomUUID(),
        };
      setBusy(true);
      $("#chat-hint").textContent =
        "Reading the transcript and preparing an answer…";
      try {
        const result = await WebinarAPI.request("chat", params, "POST", retry);
        conversationId = result.conversationId;
        newConversation = false;
        renderMessages(result.messages);
        $("#chat-question").value = "";
        retry = null;
        const refreshed = await WebinarAPI.request("chat", {
          ...params,
          conversation_id: conversationId,
        });
        listConversations(refreshed.conversations);
      } catch (error) {
        showError(error);
      } finally {
        setBusy(false);
        $("#chat-hint").textContent =
          "Answers are based only on this webinar transcript and include source links.";
      }
    });

    try {
      await refreshState();
      if (options.openOnMount) openDrawer();
      if (state.transcriptReady) void loadSummary(true);
      else void prepareTranscript();
    } catch (error) {
      $("#assistant-badge").textContent = "Unavailable";
      $("#chat-status").textContent = error.message;
      showError(error);
      if (options.openOnMount) openDrawer();
    }
  }

  function unmount(host) {
    if (!host) return;
    if (typeof host._webinarChatCleanup === "function")
      host._webinarChatCleanup();
    host._webinarChatCleanup = null;
    host.innerHTML = "";
  }

  return { mount, unmount };
})();
