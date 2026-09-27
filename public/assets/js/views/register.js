import { clearFeedback, setFieldErrors, showFeedback } from "../components/feedback.js";

function createElement(tag, className, text = null) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== null) element.textContent = text;
    return element;
}

function createField({ name, label, type, autocomplete, maxLength = null, minLength = null }) {
    const wrapper = createElement("div", "form-field");
    const inputId = `register-${name}`;
    const labelNode = document.createElement("label");
    labelNode.htmlFor = inputId;
    labelNode.textContent = label;

    const input = document.createElement("input");
    input.id = inputId;
    input.name = name;
    input.type = type;
    input.required = true;
    input.autocomplete = autocomplete;
    input.setAttribute("aria-describedby", `${name}-error`);
    if (maxLength !== null) input.maxLength = maxLength;
    if (minLength !== null) input.minLength = minLength;
    if (name === "email" || name === "password") input.dir = "ltr";

    const error = createElement("span", "field-error");
    error.id = `${name}-error`;
    wrapper.append(labelNode, input, error);

    return { wrapper, input };
}

export function renderRegisterView({ onRegister }) {
    const layout = createElement("div", "auth-layout auth-layout--register");
    const intro = createElement("aside", "auth-intro");
    const brand = document.createElement("img");
    brand.className = "auth-wordmark";
    brand.src = "/assets/images/brand/ravandnama-wordmark-fa-light.svg";
    brand.alt = "روندنما";
    intro.append(
        brand,
        createElement("h1", "auth-intro-title", "شروعی ساده برای قدم‌های بعدی."),
        createElement("p", "auth-intro-copy", "حساب خودت را بساز و فضای شخصی‌ات را برای برنامه‌ریزی روزمره آماده کن."),
        createElement("span", "auth-intro-caption", "اطلاعاتت فقط برای حساب خودت استفاده می‌شود."),
    );

    const panel = createElement("section", "auth-panel");
    panel.setAttribute("aria-labelledby", "register-title");
    const title = createElement("h2", "auth-title", "ثبت‌نام در روندنما");
    title.id = "register-title";
    panel.append(
        createElement("p", "auth-eyebrow", "ساخت حساب"),
        title,
        createElement("p", "auth-description", "چند مورد کوتاه را وارد کن تا حساب ساخته شود."),
    );

    const feedback = createElement("div", "form-feedback");
    feedback.setAttribute("aria-live", "polite");
    feedback.setAttribute("aria-atomic", "true");

    const form = document.createElement("form");
    form.className = "auth-form";
    const name = createField({ name: "name", label: "نام", type: "text", autocomplete: "name", maxLength: 120 });
    const email = createField({ name: "email", label: "ایمیل", type: "email", autocomplete: "email", maxLength: 254 });
    const password = createField({ name: "password", label: "گذرواژه", type: "password", autocomplete: "new-password", minLength: 8 });
    const submit = createElement("button", "primary-button", "ساخت حساب");
    submit.type = "submit";
    form.append(name.wrapper, email.wrapper, password.wrapper, submit);

    const switchLine = createElement("p", "auth-switch", "قبلاً حساب ساخته‌ای؟ ");
    const loginLink = createElement("a", "text-link", "وارد شو");
    loginLink.href = "#/login";
    switchLine.append(loginLink);

    form.addEventListener("input", () => setFieldErrors(form));
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        clearFeedback(feedback);
        setFieldErrors(form);
        submit.disabled = true;
        submit.textContent = "در حال ساخت حساب…";

        const values = {
            name: name.input.value.trim(),
            email: email.input.value.trim(),
            password: password.input.value,
        };

        try {
            await onRegister(values, {
                showError: (message, retry = false) => showFeedback(feedback, message, "error", retry ? () => form.requestSubmit() : null),
                showSuccess: (message) => showFeedback(feedback, message, "success"),
                setErrors: (errors) => setFieldErrors(form, errors),
            });
        } finally {
            submit.disabled = false;
            submit.textContent = "ساخت حساب";
        }
    });

    panel.append(feedback, form, switchLine);
    layout.append(intro, panel);
    return layout;
}
