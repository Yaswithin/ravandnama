export function showFeedback(region, message, kind = "error", onRetry = null) {
    region.replaceChildren();
    region.dataset.kind = kind;
    region.setAttribute("role", kind === "error" ? "alert" : "status");

    const text = document.createElement("span");
    text.textContent = message;
    region.append(text);

    if (onRetry) {
        const button = document.createElement("button");
        button.className = "text-button feedback-retry";
        button.type = "button";
        button.textContent = "تلاش دوباره";
        button.addEventListener("click", onRetry);
        region.append(button);
    }
}

export function clearFeedback(region) {
    region.replaceChildren();
    delete region.dataset.kind;
    region.setAttribute("role", "status");
}

export function setFieldErrors(form, errors = {}) {
    for (const input of form.querySelectorAll("[name]")) {
        const errorNode = form.querySelector(`#${input.name}-error`);
        const message = errors[input.name];
        input.toggleAttribute("aria-invalid", typeof message === "string");

        if (errorNode) {
            errorNode.textContent = typeof message === "string" ? message : "";
        }
    }
}
