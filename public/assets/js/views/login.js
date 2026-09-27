import { clearFeedback, setFieldErrors, showFeedback } from "../components/feedback.js";

function createElement(tag, className, text = null) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== null) element.textContent = text;
    return element;
}

function createField({ name, label, type, autocomplete, inputMode = null }) {
    const wrapper = createElement("div", "form-field");
    const inputId = `login-${name}`;
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
    if (inputMode) input.inputMode = inputMode;
    if (name === "email") input.dir = "ltr";
    if (name === "password") input.dir = "ltr";

    const error = createElement("span", "field-error");
    error.id = `${name}-error`;
    wrapper.append(labelNode, input, error);

    return { wrapper, input };
}

export function renderLoginView({ onLogin, notice = null }) {
    const layout = createElement("div", "auth-layout");
    const intro = createElement("aside", "auth-intro");
    const brand = document.createElement("img");
    brand.className = "auth-wordmark";
    brand.src = "/assets/images/brand/ravandnama-wordmark-fa-light.svg";
    brand.alt = "روندنما";
    intro.append(
        brand,
        createElement("h1", "auth-intro-title", "برای کارهای مهم، جا باز کن."),
        createElement("p", "auth-intro-copy", "با ورود به حساب خود، مسیر روزمره‌ات را با تمرکز بیشتری ادامه بده."),
        createElement("span", "auth-intro-caption", "یک قدم روشن، هر روز."),
    );

    const panel = createElement("section", "auth-panel");
    panel.setAttribute("aria-labelledby", "login-title");
    const title = createElement("h2", "auth-title", "ورود به حساب");
    title.id = "login-title";
    panel.append(
        createElement("p", "auth-eyebrow", "خوش آمدی"),
        title,
        createElement("p", "auth-description", "برای ادامه، ایمیل و گذرواژه‌ات را وارد کن."),
    );

    const feedback = createElement("div", "form-feedback");
    feedback.setAttribute("aria-live", "polite");
    feedback.setAttribute("aria-atomic", "true");
    if (notice) showFeedback(feedback, notice.message, notice.kind);

    const form = document.createElement("form");
    form.className = "auth-form";
    form.noValidate = false;
    const email = createField({ name: "email", label: "ایمیل", type: "email", autocomplete: "email", inputMode: "email" });
    const password = createField({ name: "password", label: "گذرواژه", type: "password", autocomplete: "current-password" });
    const submit = createElement("button", "primary-button", "ورود");
    submit.type = "submit";
    form.append(email.wrapper, password.wrapper, submit);

    const switchLine = createElement("p", "auth-switch", "حساب کاربری نداری؟ ");
    const registerLink = createElement("a", "text-link", "ثبت‌نام کن");
    registerLink.href = "#/register";
    switchLine.append(registerLink);

    form.addEventListener("input", () => setFieldErrors(form));
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        clearFeedback(feedback);
        setFieldErrors(form);
        submit.disabled = true;
        submit.textContent = "در حال ورود…";

        const values = {
            email: email.input.value.trim(),
            password: password.input.value,
        };

        try {
            await onLogin(values, {
                showError: (message, retry = false) => showFeedback(feedback, message, "error", retry ? () => form.requestSubmit() : null),
                showSuccess: (message) => showFeedback(feedback, message, "success"),
                setErrors: (errors) => setFieldErrors(form, errors),
            });
        } finally {
            submit.disabled = false;
            submit.textContent = "ورود";
        }
    });

    panel.append(feedback, form, switchLine);
    layout.append(intro, panel);
    return layout;
}
