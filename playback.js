"use strict";

const WebinarPlayback = (() => {
  let dispose = () => {};
  const uuid = () => crypto.randomUUID();
  function attach(video, webinar, registration) {
    dispose();
    if (!registration.activityConsent) return;
    const sessionId = uuid();
    let created = false,
      creating,
      queue = [],
      sending = false,
      disposed = false;
    let started = false,
      paused = false,
      buffering = true,
      seeking = false;
    let watchDelta = 0,
      lastWall = performance.now(),
      lastPosition = video.currentTime,
      lastRate = video.playbackRate;
    let seekFrom = 0;
    const listeners = [];
    const finite = (value) => (Number.isFinite(value) ? Math.max(0, value) : 0);
    function sample() {
      const wall = performance.now();
      const seconds = Math.min(2, Math.max(0, (wall - lastWall) / 1000));
      const advance = video.currentTime - lastPosition;
      // Credit observed movement only; jumps, paused time, stalls, and long suspended gaps do not count.
      if (
        started &&
        !buffering &&
        !seeking &&
        advance > 0 &&
        advance <= seconds * lastRate + 0.5
      ) {
        watchDelta += Math.min(seconds, advance / Math.max(lastRate, 0.1));
      }
      lastWall = wall;
      lastPosition = video.currentTime;
      lastRate = video.playbackRate;
    }
    function event(type, extra = {}) {
      if (!started || disposed) return;
      sample();
      queue.push({
        id: uuid(),
        type,
        occurredAt: new Date().toISOString(),
        positionSeconds: finite(video.currentTime),
        durationSeconds: finite(video.duration),
        watchedDeltaSeconds: Math.min(30, watchDelta),
        playbackRate: video.playbackRate,
        ...extra,
      });
      watchDelta = 0;
      if (queue.length > 300) queue = queue.slice(-300); // At most ~25 minutes of metadata, no personal fields.
    }
    async function ensureSession() {
      if (created) return;
      if (!creating)
        creating = WebinarAPI.request(
          "playback_session",
          { webinar_id: webinar.id },
          "POST",
          { sessionId, durationSeconds: finite(video.duration) },
        )
          .then(() => {
            created = true;
          })
          .finally(() => {
            creating = null;
          });
      await creating;
    }
    async function flush(keepalive = false) {
      if (sending || !queue.length) return;
      sending = true;
      const batch = queue.slice(0, 30);
      try {
        await ensureSession();
        await WebinarAPI.request(
          "playback_events",
          { webinar_id: webinar.id },
          "POST",
          { sessionId, events: batch },
          { keepalive },
        );
        queue.splice(0, batch.length);
        const note = !disposed
          ? document.querySelector("#tracking-note")
          : null;
        if (note)
          note.textContent = "Video activity is being saved with your consent.";
      } catch {
        const note = !disposed
          ? document.querySelector("#tracking-note")
          : null;
        if (note)
          note.textContent =
            "Video activity could not be saved. We’ll retry while this page is open.";
      } finally {
        sending = false;
      }
    }
    function on(name, fn) {
      video.addEventListener(name, fn);
      listeners.push([name, fn]);
    }
    on("playing", () => {
      buffering = false;
      lastWall = performance.now();
      lastPosition = video.currentTime;
      if (!started) {
        started = true;
        event("started");
      } else if (paused) {
        event("resumed");
        paused = false;
      }
      event("playing");
      void flush();
    });
    on("pause", () => {
      sample();
      buffering = true;
      paused = true;
      event("paused");
      void flush();
    });
    on("waiting", () => {
      sample();
      buffering = true;
    });
    on("seeking", () => {
      seekFrom = lastPosition;
      seeking = true;
    });
    on("seeked", () => {
      event("seeked", {
        previousPositionSeconds: finite(seekFrom),
        newPositionSeconds: finite(video.currentTime),
      });
      seeking = false;
      lastPosition = video.currentTime;
      lastWall = performance.now();
      void flush();
    });
    on("ratechange", sample);
    on("ended", () => {
      sample();
      buffering = true;
      event("completed");
      void flush();
    });
    const sampler = setInterval(sample, 1000);
    const heartbeat = setInterval(() => {
      if (started && !disposed) {
        event("progress");
        void flush();
      }
    }, 5000);
    const leave = () => {
      event("session_ended");
      void flush(true);
    };
    window.addEventListener("pagehide", leave);
    dispose = () => {
      if (disposed) return;
      event("stopped");
      event("session_ended");
      void flush(true);
      disposed = true;
      clearInterval(sampler);
      clearInterval(heartbeat);
      window.removeEventListener("pagehide", leave);
      for (const [name, fn] of listeners) video.removeEventListener(name, fn);
    };
  }
  return { attach, stop: () => dispose() };
})();
