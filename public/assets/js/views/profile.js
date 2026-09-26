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

export function renderProfileView(user, onLogout) {
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

    const footer = createElement("div", "profile-footer");
    footer.append(
        createElement("p", "profile-note", "اطلاعات این صفحه فقط‌خواندنی است."),
    );

    const logout = createElement("button", "primary-button profile-logout", "خروج از حساب");
    logout.type = "button";
    logout.addEventListener("click", () => onLogout(logout));
    footer.append(logout);

    card.append(identity, details, footer);
    page.append(heading, card);

    return page;
}
