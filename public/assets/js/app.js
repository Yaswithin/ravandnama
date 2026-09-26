import * as authApi from "./api/auth.js";
import { ApiError } from "./api/client.js";
import { renderNavigation } from "./components/navigation.js";
import { clearFeedback, showFeedback } from "./components/feedback.js";
import {
    clearAuthState,
    getAuthState,
    setAuthError,
    setAuthLoading,
    setAuthenticatedUser,
    setCsrfToken,
    setUnauthenticated,
} from "./state/auth.js";
import { renderLoginView } from "./views/login.js";
import { renderRegisterView } from "./views/register.js";
import { startRouter } from "./router.js";

const appHeader = document.querySelector("#app-header");
const statusRegion = document.querySelector("#app-status");
const main = document.querySelector("#app-view");

let pageNotice = null;
let renderCurrentRoute = () => {};

function setGlobalMessage(message, kind = "info", onRetry = null) {
    if (message === "") {
        clearFeedback(statusRegion);
        return;
    }

    showFeedback(statusRegion, message, kind, onRetry);
}

function renderMessagePage(title, message, { retry = false } = {}) {
    main.replaceChildren();
    main.classList.remove("app-main--auth");

    const heading = document.createElement("h1");
    heading.textContent = title;
    const description = document.createElement("p");
    description.textContent = message;
    main.append(heading, description);

    if (retry) {
        const button = document.createElement("button");
        button.className = "primary-button retry-button";
        button.type = "button";
        button.textContent = "تلاش دوباره";
        button.addEventListener("click", restoreSession);
        main.append(button);
    }
}

function renderPlaceholder(path) {
    const labels = {
        "/dashboard": "خانه",
        "/tasks": "کارها",
        "/projects": "پروژه‌ها",
        "/profile": "پروفایل و تنظیمات",
    };
    renderMessagePage(labels[path], "این بخش در مرحله‌ای بعدی آماده می‌شود.");
}

function renderPage(page) {
    const state = getAuthState();
    renderNavigation(appHeader, state.user, handleLogout);
    main.replaceChildren();

    if (page.type === "loading") {
        renderMessagePage("روندنما", "در حال بررسی وضعیت نشست…");
        return;
    }

    if (page.type === "session-error") {
        renderMessagePage("ارتباط با سرور برقرار نشد", "وضعیت نشست بررسی نشد؛ اتصال را بررسی و دوباره تلاش کنید.", { retry: true });
        return;
    }

    if (page.type === "not-found") {
        renderMessagePage("صفحه پیدا نشد", "نشانی انتخاب‌شده در برنامه تعریف نشده است.");
        return;
    }

    if (page.path === "/login") {
        main.classList.add("app-main--auth");
        main.append(renderLoginView({ onLogin: handleLogin, notice: pageNotice }));
        pageNotice = null;
        return;
    }

    if (page.path === "/register") {
        main.classList.add("app-main--auth");
        main.append(renderRegisterView({ onRegister: handleRegister }));
        return;
    }

    renderPlaceholder(page.path);
}

function displayActionError(error, ui, retryAction, action) {
    if (error instanceof ApiError && error.status === 422) {
        ui.setErrors(error.errors ?? {});
        ui.showError("اطلاعات واردشده را بررسی و اصلاح کنید.");
        return;
    }

    if (error instanceof ApiError && error.status === 409 && action === "register") {
        ui.setErrors({ email: "این ایمیل قبلاً ثبت شده است." });
        ui.showError("برای این ایمیل حسابی وجود دارد. وارد شوید یا ایمیل دیگری وارد کنید.");
        return;
    }

    if (error instanceof ApiError && error.status === 401 && action === "login") {
        ui.showError("ایمیل یا گذرواژه درست نیست.");
        return;
    }

    if (error instanceof ApiError && error.status === 403) {
        refreshCsrfToken()
            .then(() => ui.showError("نشست یا کد امنیتی تازه شد. برای ادامه دوباره تلاش کنید.", retryAction))
            .catch(() => ui.showError("نشست در دسترس نیست. اتصال را بررسی و دوباره تلاش کنید.", retryAction));
        return;
    }

    if (error instanceof ApiError && error.status === 400) {
        ui.showError("درخواست کامل نشد. اطلاعات فرم را بررسی و دوباره تلاش کنید.");
    } else if (error instanceof ApiError && error.status === 404) {
        ui.showError("مورد درخواستی پیدا نشد.");
    } else if (error instanceof ApiError && error.status === 401) {
        ui.showError("نشست معتبر نیست. دوباره وارد شوید.", retryAction);
    } else {
        ui.showError("مشکلی در ارتباط با سرور پیش آمد. دوباره تلاش کنید.", retryAction);
    }
}

async function refreshCsrfToken() {
    const token = await authApi.getCsrfToken();
    setCsrfToken(token);

    return token;
}

async function handleLogin(credentials, ui) {
    const submitAgain = () => handleLogin(credentials, ui);

    try {
        const token = getAuthState().csrfToken ?? await refreshCsrfToken();
        const user = await authApi.login(credentials, token);

        if (!user) throw new Error("The server did not return a user.");
        setAuthenticatedUser(user);
        pageNotice = null;
        clearFeedback(statusRegion);
        window.location.hash = "#/dashboard";
    } catch (error) {
        displayActionError(error, ui, submitAgain, "login");
    }
}

async function handleRegister(details, ui) {
    const submitAgain = () => handleRegister(details, ui);

    try {
        const token = getAuthState().csrfToken ?? await refreshCsrfToken();
        await authApi.register(details, token);
        setUnauthenticated();
        pageNotice = {
            kind: "success",
            message: "ثبت‌نام با موفقیت انجام شد. حالا با ایمیل و گذرواژه وارد شوید.",
        };
        window.location.hash = "#/login";
    } catch (error) {
        displayActionError(error, ui, submitAgain, "register");
    }
}

async function handleLogout(button) {
    const retry = () => handleLogout(button);
    button.disabled = true;
    button.textContent = "در حال خروج…";

    try {
        const token = getAuthState().csrfToken ?? await refreshCsrfToken();
        await authApi.logout(token);
        clearAuthState();
        clearFeedback(statusRegion);
        window.location.hash = "#/login";
    } catch (error) {
        if (error instanceof ApiError && error.status === 403) {
            try {
                await refreshCsrfToken();
                setGlobalMessage("کد امنیتی تازه شد. برای خروج دوباره تلاش کنید.", "error", retry);
            } catch {
                setGlobalMessage("نشست در دسترس نیست. اتصال را بررسی و دوباره تلاش کنید.", "error", retry);
            }
        } else {
            setGlobalMessage("خروج انجام نشد. اتصال را بررسی و دوباره تلاش کنید.", "error", retry);
        }
    } finally {
        button.disabled = false;
        button.textContent = "خروج";
    }
}

async function restoreSession() {
    setAuthLoading();
    setGlobalMessage("در حال بررسی نشست…");
    renderCurrentRoute();

    try {
        await refreshCsrfToken();
    } catch {
        setAuthError();
        setGlobalMessage("ارتباط با سرور برقرار نشد. دوباره تلاش کنید.", "error", restoreSession);
        renderCurrentRoute();
        return;
    }

    try {
        const user = await authApi.getCurrentUser();
        if (user) {
            setAuthenticatedUser(user);
            setGlobalMessage("");
        } else {
            setUnauthenticated();
            setGlobalMessage("");
        }
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            setUnauthenticated();
            setGlobalMessage("");
        } else {
            setAuthError();
            setGlobalMessage("ارتباط با سرور برقرار نشد. دوباره تلاش کنید.", "error", restoreSession);
        }
    }

    renderCurrentRoute();
}

renderCurrentRoute = startRouter(getAuthState, renderPage);
restoreSession();
