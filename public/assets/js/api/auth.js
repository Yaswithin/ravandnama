import { apiRequest } from "./client.js";

export async function getCsrfToken() {
    const response = await apiRequest("/api/auth/csrf");
    const token = response?.data?.csrf_token;

    if (typeof token !== "string" || token.length === 0) {
        throw new Error("The server did not provide a CSRF token.");
    }

    return token;
}

export async function getCurrentUser() {
    const response = await apiRequest("/api/auth/me");

    return response?.data?.user ?? null;
}

export async function getTimezones() {
    const response = await apiRequest("/api/auth/timezones");
    const timezones = response?.data?.timezones;

    if (!Array.isArray(timezones) || !timezones.every((timezone) => typeof timezone === "string")) {
        throw new Error("The server returned an invalid timezone list.");
    }

    return timezones;
}

export async function updateTimezone(timezone, csrfToken) {
    const response = await apiRequest("/api/auth/me", {
        method: "PUT",
        body: { timezone },
        csrfToken,
    });

    return response?.data?.user ?? null;
}

export async function login(credentials, csrfToken) {
    const response = await apiRequest("/api/auth/login", {
        method: "POST",
        body: credentials,
        csrfToken,
    });

    return response?.data?.user ?? null;
}

export async function register(details, csrfToken) {
    const response = await apiRequest("/api/auth/register", {
        method: "POST",
        body: details,
        csrfToken,
    });

    return response?.data?.user ?? null;
}

export function logout(csrfToken) {
    return apiRequest("/api/auth/logout", {
        method: "POST",
        csrfToken,
    });
}
