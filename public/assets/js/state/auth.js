const state = {
    status: "loading",
    user: null,
    csrfToken: null,
};

export function getAuthState() {
    return state;
}

export function setCsrfToken(token) {
    state.csrfToken = token;
}

export function setAuthenticatedUser(user) {
    state.user = user;
    state.status = "ready";
}

export function setUnauthenticated() {
    state.user = null;
    state.status = "ready";
}

export function setAuthLoading() {
    state.status = "loading";
}

export function setAuthError() {
    state.status = "error";
    state.user = null;
}

export function clearAuthState() {
    state.user = null;
    state.csrfToken = null;
    state.status = "ready";
}
