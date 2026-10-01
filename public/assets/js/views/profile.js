import * as authApi from "../api/auth.js";
import { ApiError } from "../api/client.js";
import { clearFeedback, showFeedback } from "../components/feedback.js";

function createElement(tag, className, text = null) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== null) element.textContent = text;
    return element;
}

function createProfileField(label, value, direction = "rtl") {
    const field = createElement("div", "profile-field");
    field.append(
        createElement("dt", "profile-label", label),
        createElement("dd", "profile-value", value || "—"),
    );
    field.lastElementChild.dir = direction;

    return field;
}

export function renderProfileView(user, onLogout, {
    getCsrfToken = () => null,
    refreshCsrfToken = async () => null,
    onAuthenticationExpired = () => {},
    onUserUpdated = () => {},
} = {}) {
    const page = createElement("div", "workspace-page profile-page");
    const heading = createElement("div", "page-heading");
    heading.append(
        createElement("p", "eyebrow", "حساب کاربری"),
        createElement("h1", "page-title", "پروفایل"),
    );

    const card = createElement("section", "profile-card surface-card");
    card.setAttribute("aria-labelledby", "profile-name");
    const identity = createElement("div", "profile-identity");
    const avatar = createElement("span", "profile-avatar", Array.from((user?.name || "ر").trim())[0] || "ر");
    avatar.setAttribute("aria-hidden", "true");
    const identityText = createElement("div", "profile-identity-text");
    identityText.append(
        createElement("p", "eyebrow", "حساب روندنما"),
        createElement("h2", "profile-name", user?.name || "کاربر"),
    );
    identityText.lastElementChild.id = "profile-name";
    identity.append(avatar, identityText);

    const details = document.createElement("dl");
    details.className = "profile-details";
    details.append(
        createProfileField("نام", user?.name),
        createProfileField("ایمیل", user?.email, "ltr"),
    );

    const timezoneField = createElement("div", "profile-field profile-timezone-field");
    const timezoneLabel = createElement("label", "profile-label", "منطقهٔ زمانی");
    const timezoneSelect = document.createElement("select");
    timezoneSelect.id = "profile-timezone";
    timezoneSelect.name = "timezone";
    timezoneSelect.required = true;
    timezoneSelect.disabled = true;
    timezoneSelect.setAttribute("aria-describedby", "profile-timezone-help profile-timezone-error");
    timezoneLabel.htmlFor = timezoneSelect.id;
    const timezoneHelp = createElement("p", "profile-timezone-help", "این منطقهٔ زمانی به‌عنوان ترجیح حساب شما ذخیره می‌شود.");
    timezoneHelp.id = "profile-timezone-help";
    const timezoneError = createElement("span", "field-error");
    timezoneError.id = "profile-timezone-error";
    timezoneField.append(timezoneLabel, timezoneSelect, timezoneHelp, timezoneError);

    const timezoneFeedback = createElement("div", "form-feedback profile-timezone-feedback");
    timezoneFeedback.setAttribute("aria-live", "polite");
    timezoneFeedback.setAttribute("aria-atomic", "true");
    const timezoneSave = createElement("button", "primary-button profile-timezone-save", "ذخیره منطقهٔ زمانی");
    timezoneSave.type = "button";
    timezoneSave.disabled = true;
    const timezoneCurrent = createElement("p", "profile-timezone-current", "");
    timezoneCurrent.dir = "auto";

    let currentTimezone = typeof user?.timezone === "string" ? user.timezone : "Asia/Tehran";
    let availableTimezones = [];

    function renderTimezoneLabel(timezone) {
        try {
            const parts = new Intl.DateTimeFormat("fa-IR", {
                timeZone: timezone,
                timeZoneName: "long",
            }).formatToParts(new Date());
            const name = parts.find((part) => part.type === "timeZoneName")?.value;
            return name ? `${name} — ${timezone}` : timezone;
        } catch {
            return timezone;
        }
    }

    function updateTimezoneControls() {
        timezoneCurrent.textContent = `منطقهٔ زمانی فعلی: ${renderTimezoneLabel(currentTimezone)}`;
        timezoneSave.disabled = timezoneSelect.disabled || timezoneSelect.value === currentTimezone;
    }

    async function loadTimezones() {
        timezoneSelect.disabled = true;
        timezoneSave.disabled = true;
        clearFeedback(timezoneFeedback);

        try {
            availableTimezones = await authApi.getTimezones();
            if (!availableTimezones.includes(currentTimezone)) availableTimezones.unshift(currentTimezone);
            timezoneSelect.replaceChildren(...availableTimezones.map((timezone) => {
                const option = document.createElement("option");
                option.value = timezone;
                option.textContent = renderTimezoneLabel(timezone);
                option.dir = "auto";
                return option;
            }));
            timezoneSelect.value = currentTimezone;
            timezoneSelect.disabled = false;
            updateTimezoneControls();
        } catch (error) {
            if (error instanceof ApiError && error.status === 401) {
                onAuthenticationExpired();
                return;
            }
            showFeedback(timezoneFeedback, "فهرست مناطق زمانی بارگذاری نشد.", "error", loadTimezones);
        }
    }

    async function saveTimezone() {
        if (timezoneSave.disabled) return;
        timezoneSave.disabled = true;
        timezoneSelect.disabled = true;
        timezoneError.textContent = "";
        timezoneSelect.removeAttribute("aria-invalid");
        clearFeedback(timezoneFeedback);
        timezoneSave.textContent = "در حال ذخیره…";

        try {
            const token = getCsrfToken() ?? await refreshCsrfToken();
            const updatedUser = await authApi.updateTimezone(timezoneSelect.value, token);
            if (!updatedUser || typeof updatedUser.timezone !== "string") {
                throw new Error("The server returned an invalid profile response.");
            }
            currentTimezone = updatedUser.timezone;
            timezoneSelect.value = currentTimezone;
            onUserUpdated(updatedUser);
            updateTimezoneControls();
            showFeedback(timezoneFeedback, "منطقهٔ زمانی ذخیره شد.", "success");
        } catch (error) {
            if (error instanceof ApiError && error.status === 401) {
                onAuthenticationExpired();
                return;
            }
            if (error instanceof ApiError && error.status === 403) {
                try {
                    await refreshCsrfToken();
                    showFeedback(timezoneFeedback, "کد امنیتی تازه شد؛ ذخیره را دوباره انجام دهید.", "error");
                } catch {
                    showFeedback(timezoneFeedback, "نشست تازه نشد. اتصال را بررسی کنید.", "error");
                }
            } else if (error instanceof ApiError && error.status === 422) {
                timezoneSelect.setAttribute("aria-invalid", "true");
                timezoneError.textContent = "منطقهٔ زمانی انتخاب‌شده معتبر نیست. فهرست را تازه کنید.";
            } else {
                showFeedback(timezoneFeedback, "ذخیرهٔ منطقهٔ زمانی انجام نشد. دوباره تلاش کنید.", "error");
            }
        } finally {
            timezoneSelect.disabled = false;
            timezoneSave.textContent = "ذخیره منطقهٔ زمانی";
            updateTimezoneControls();
        }
    }

    timezoneSelect.addEventListener("change", () => {
        timezoneError.textContent = "";
        timezoneSelect.removeAttribute("aria-invalid");
        updateTimezoneControls();
    });
    timezoneSave.addEventListener("click", saveTimezone);
    loadTimezones();

    const footer = createElement("div", "profile-footer");
    footer.append(
        createElement("p", "profile-note", "نام و ایمیل این صفحه فقط‌خواندنی هستند."),
    );

    const logout = createElement("button", "primary-button profile-logout", "خروج از حساب");
    logout.type = "button";
    logout.addEventListener("click", () => onLogout(logout));
    footer.append(logout);

    card.append(identity, details, timezoneField, timezoneCurrent, timezoneFeedback, timezoneSave, footer);
    page.append(heading, card);

    return page;
}
