export function renderNavigation(header, user, onLogout) {
    header.querySelector(".account-navigation")?.remove();

    if (!user) {
        return;
    }

    const nav = document.createElement("nav");
    nav.className = "account-navigation";
    nav.setAttribute("aria-label", "ناوبری حساب کاربری");

    const links = [
        ["/dashboard", "خانه"],
        ["/tasks", "کارها"],
        ["/projects", "پروژه‌ها"],
        ["/profile", "پروفایل"],
    ];

    for (const [path, label] of links) {
        const link = document.createElement("a");
        link.href = `#${path}`;
        link.textContent = label;
        nav.append(link);
    }

    const name = document.createElement("span");
    name.className = "account-name";
    name.textContent = user.name;

    const logoutButton = document.createElement("button");
    logoutButton.className = "text-button logout-button";
    logoutButton.type = "button";
    logoutButton.textContent = "خروج";
    logoutButton.addEventListener("click", () => onLogout(logoutButton));

    const account = document.createElement("div");
    account.className = "account-actions";
    account.append(name, logoutButton);
    nav.append(account);
    header.append(nav);
}
