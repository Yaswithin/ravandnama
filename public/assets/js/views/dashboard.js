function createElement(tag, className, text = null) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== null) element.textContent = text;
    return element;
}

function createShortcut({ href, eyebrow, title, description, action }) {
    const link = createElement("a", "shortcut-card");
    link.href = href;
    link.append(
        createElement("span", "shortcut-eyebrow", eyebrow),
        createElement("h3", "shortcut-title", title),
        createElement("p", "shortcut-description", description),
        createElement("span", "shortcut-action", action),
    );

    return link;
}

export function renderDashboardView(user) {
    const page = createElement("div", "workspace-page dashboard-page");
    const heading = createElement("div", "page-heading");
    heading.append(
        createElement("p", "eyebrow", "فضای شخصی تو"),
        createElement("h1", "page-title", "داشبورد"),
    );

    const welcome = createElement("section", "welcome-panel");
    welcome.setAttribute("aria-labelledby", "welcome-title");
    welcome.append(
        createElement("p", "welcome-kicker", "خوش آمدی"),
        createElement("h2", "welcome-title", user?.name || "دوست عزیز"),
        createElement("p", "welcome-copy", "روندنما فضایی آرام برای روشن‌کردن قدم بعدی و پیش‌بردن کارهای مهم روزمره است."),
    );

    const shortcutsHeading = createElement("div", "section-heading");
    shortcutsHeading.append(
        createElement("p", "eyebrow", "از اینجا شروع کن"),
        createElement("h2", "section-title", "دسترسی سریع"),
    );

    const shortcuts = createElement("div", "shortcut-grid");
    shortcuts.append(
        createShortcut({
            href: "#/tasks",
            eyebrow: "برنامه‌ریزی",
            title: "وظایف",
            description: "کارهای روزمره‌ات را در یک مسیر روشن دنبال کن.",
            action: "مشاهده وظایف",
        }),
        createShortcut({
            href: "#/projects",
            eyebrow: "تمرکز",
            title: "پروژه‌ها",
            description: "موضوع‌های بزرگ‌تر را در فضای مخصوص خود نگه دار.",
            action: "مشاهده پروژه‌ها",
        }),
        createShortcut({
            href: "#/profile",
            eyebrow: "حساب کاربری",
            title: "پروفایل",
            description: "اطلاعات حساب خود را ببین و از آن خارج شو.",
            action: "رفتن به پروفایل",
        }),
    );

    const note = createElement("section", "development-note");
    note.setAttribute("aria-label", "امکانات در دسترس برنامه");
    note.append(
        createElement("div", "development-copy"),
    );
    note.querySelector(".development-copy").append(
        createElement("h2", "development-title", "مسیرت را همین‌جا ادامه بده"),
        createElement("p", "development-description", "وظایف، پروژه‌ها و یادداشت‌هایت را در فضای شخصی خودت دنبال کن."),
    );

    page.append(heading, welcome, shortcutsHeading, shortcuts, note);

    return page;
}
