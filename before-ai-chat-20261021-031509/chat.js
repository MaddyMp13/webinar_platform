"use strict";

const WebinarChat = (() => {
  const escape = (value) =>
    String(value).replace(
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
  async function mount(host, webinar, registration) {
    host.innerHTML =
      '<section class="chat-panel"><div class="video-heading"><h2>Transcript assistant</h2><span class="badge">Transcript excerpts</span></div><p class="body-copy" id="chat-status">Checking transcript availability…</p><div id="chat-messages" class="chat-messages" role="log" aria-live="polite"></div><form id="chat-form" hidden><label for="chat-question">Ask about this webinar</label><textarea id="chat-question" rows="3" maxlength="1500" required placeholder="What are the key points about data resilience?"></textarea><button class="button" type="submit">Ask a question →</button></form><p id="chat-feedback" class="field-error" role="alert" hidden></p></section>';
    const status = host.querySelector("#chat-status"),
      form = host.querySelector("#chat-form"),
      feedback = host.querySelector("#chat-feedback");
    const render = (messages) => {
      host.querySelector("#chat-messages").innerHTML = messages
        .map(
          (message) =>
            `<article class="chat-message ${message.role === "user" ? "from-user" : "from-assistant"}"><strong>${message.role === "user" ? "You" : "Transcript assistant"}</strong><p>${escape(message.message)}</p>${message.sources?.length ? `<ul class="chat-sources">${message.sources.map((source) => `<li>${escape(source.source_label)}${source.start_seconds !== null ? " · " + Math.floor(Number(source.start_seconds) / 60) + ":" + String(Math.floor(Number(source.start_seconds) % 60)).padStart(2, "0") : ""}</li>`).join("")}</ul>` : ""}</article>`,
        )
        .join("");
    };
    if (!registration.activityConsent) {
      status.textContent =
        "To save questions and chat history, enable activity consent under Edit registration.";
      return;
    }
    try {
      const result = await WebinarAPI.request("chat", {
        webinar_id: webinar.id,
      });
      render(result.messages);
      if (!result.transcriptReady) {
        status.textContent =
          "A transcript has not been added for this webinar. The assistant will be available when an authorized transcript is ready.";
        return;
      }
      status.textContent =
        "Answers show excerpts from this webinar’s transcript with source references. AI generation is not configured.";
      form.hidden = false;
    } catch (error) {
      status.textContent = error.message;
      return;
    }
    let busy = false;
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (busy) return;
      const question = host.querySelector("#chat-question");
      if (question.value.trim().length < 3) {
        feedback.textContent = "Ask a question with at least 3 characters.";
        feedback.hidden = false;
        return;
      }
      busy = true;
      form.querySelector("button").disabled = true;
      feedback.hidden = true;
      try {
        const result = await WebinarAPI.request(
          "chat",
          { webinar_id: webinar.id },
          "POST",
          { question: question.value.trim(), requestId: crypto.randomUUID() },
        );
        render(result.messages);
        question.value = "";
      } catch (error) {
        feedback.textContent = error.message;
        feedback.hidden = false;
      } finally {
        busy = false;
        form.querySelector("button").disabled = false;
      }
    });
  }
  return { mount };
})();
