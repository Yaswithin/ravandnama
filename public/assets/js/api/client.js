export class ApiError extends Error {
    constructor(message, status, errors = null) {
        super(message);
        this.name = "ApiError";
        this.status = status;
        this.errors = errors;
    }
}

export async function apiRequest(path, { method = "GET", body, csrfToken = null } = {}) {
    const headers = new Headers({ Accept: "application/json" });
    const options = {
        method,
        credentials: "same-origin",
        headers,
    };

    if (body !== undefined) {
        headers.set("Content-Type", "application/json");
        options.body = JSON.stringify(body);
    }

    if (csrfToken !== null) {
        headers.set("X-CSRF-Token", csrfToken);
    }

    const response = await fetch(path, options);
    const responseText = await response.text();
    let payload = null;

    if (responseText !== "") {
        try {
            payload = JSON.parse(responseText);
        } catch {
            if (response.ok) {
                throw new ApiError("The server returned an invalid JSON response.", response.status);
            }
        }
    }

    if (!response.ok) {
        throw new ApiError(
            typeof payload?.message === "string" ? payload.message : "The request could not be completed.",
            response.status,
            payload?.errors ?? null,
        );
    }

    return payload;
}
