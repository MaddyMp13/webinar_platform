"use strict";

const WebinarAPI = (() => {
  let csrfToken = "";
  function url(resource, parameters = {}) {
    // Uses a relative path to api/index.php based on the current page location
    const endpoint = new URL("api/index.php", document.baseURI);
    endpoint.searchParams.set("resource", resource);
    for (const [key, value] of Object.entries(parameters))
      endpoint.searchParams.set(key, value);
    return endpoint.href;
  }
  async function request(
    resource,
    parameters = {},
    method = "GET",
    data,
    options = {},
  ) {
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
        ...options,
      });
    } catch {
      throw new Error(
        "Could not reach the PHP backend. Please ensure the server is configured correctly.",
      );
    }
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error(
        "The PHP API returned an invalid response. Please check your server logs.",
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
  async function exportData(data) {
    const response = await fetch(url("dashboard_export"), {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": csrfToken,
      },
      body: JSON.stringify(data),
    });
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || "Export failed.");
    }
    return response.blob();
  }
  return { url, request, exportData };
})();
