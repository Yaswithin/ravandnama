const publicRoutes = new Set(["/login", "/register"]);
const protectedRoutes = new Set(["/dashboard", "/tasks", "/projects", "/profile"]);
const knownRoutes = new Set(["/", ...publicRoutes, ...protectedRoutes]);

function currentPath() {
    const path = window.location.hash.slice(1);

    return path === "" ? "/" : path;
}

export function startRouter(getState, renderPage) {
    const render = () => {
        const path = currentPath();
        const state = getState();

        if (!knownRoutes.has(path)) {
            renderPage({ type: "not-found", path });
            return;
        }

        if (state.status === "loading") {
            renderPage({ type: "loading", path });
            return;
        }

        if (state.status === "error") {
            renderPage({ type: "session-error", path });
            return;
        }

        if (path === "/") {
            window.location.hash = state.user ? "#/dashboard" : "#/login";
            return;
        }

        if (state.user && publicRoutes.has(path)) {
            window.location.hash = "#/dashboard";
            return;
        }

        if (!state.user && protectedRoutes.has(path)) {
            window.location.hash = "#/login";
            return;
        }

        renderPage({ type: "route", path });
        document.querySelector("#app-view")?.focus({ preventScroll: true });
    };

    window.addEventListener("hashchange", render);
    render();

    return render;
}
