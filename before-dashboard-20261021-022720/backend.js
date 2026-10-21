"use strict";

// Resolves correctly at /Task/, /webinar-platform/, or a virtual-host root.
const WebinarAPI = (() => {
  let csrfToken = "";
  function url(resource, parameters = {}) {
    const endpoint = new URL("api/index.php", document.baseURI);
    endpoint.searchParams.set("resource", resource);
    for (const [key, value] of Object.entries(parameters))
      endpoint.searchParams.set(key, value);
    return endpoint.href;
  }
  async function request(resource, parameters = {}, method = "GET", data) {
    if (!["http:", "https:"].includes(location.protocol))
      throw new Error(
        "Open this app through XAMPP Apache, such as http://localhost/Task/.",
      );
    const headers = {};
    if (data) headers["Content-Type"] = "application/json";
    if (method !== "GET") headers["X-CSRF-Token"] = csrfToken;
    let response;
    try {
      response = await fetch(url(resource, parameters), {
        method,
        headers,
        credentials: "same-origin",
        cache: "no-store",
        ...(data ? { body: JSON.stringify(data) } : {}),
      });
    } catch {
      throw new Error(
        "Could not reach the PHP backend. Check that Apache and MySQL are running.",
      );
    }
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error(
        "The PHP API is unavailable. Open the app through XAMPP and import its database SQL.",
      );
    }
    if (!response.ok)
      throw Object.assign(
        new Error(result.message || "Unable to complete your request."),
        { fields: result.errors, status: response.status },
      );
    if (result.csrfToken) csrfToken = result.csrfToken;
    return result;
  }
  return { url, request };
})();
