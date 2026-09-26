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

export async function login(credentials, csrfToken) {
    const response = await apiRequest("/api/auth/login", {
        method: "POST",
        body: credentials,
        csrfToken,
    });

    return response?.data?.user ?? null;
}

export function register(details, csrfToken) {
    return apiRequest("/api/auth/register", {
        method: "POST",
        body: details,
        csrfToken,
    });
}

export function logout(csrfToken) {
    return apiRequest("/api/auth/logout", {
        method: "POST",
        csrfToken,
    });
}
