export function renderNavigation(header, user, onLogout, activePath = "") {
    header.querySelector(".account-navigation")?.remove();
    header.querySelector(".navigation-toggle")?.remove();

    if (!user) {
        return;
    }

    const navigationId = "account-navigation";
    const toggle = document.createElement("button");
    toggle.className = "navigation-toggle text-button";
    toggle.type = "button";
    toggle.textContent = "منو";
    toggle.setAttribute("aria-expanded", "false");
    toggle.setAttribute("aria-controls", navigationId);

    const nav = document.createElement("nav");
    nav.className = "account-navigation is-collapsed";
    nav.id = navigationId;
    nav.setAttribute("aria-label", "ناوبری حساب کاربری");

    toggle.addEventListener("click", () => {
        nav.classList.toggle("is-collapsed");
        toggle.setAttribute("aria-expanded", String(!nav.classList.contains("is-collapsed")));
    });

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
        if (activePath === path) {
            link.setAttribute("aria-current", "page");
        }
        nav.append(link);
    }

    nav.addEventListener("click", (event) => {
        if (event.target instanceof HTMLAnchorElement) {
            nav.classList.add("is-collapsed");
            toggle.setAttribute("aria-expanded", "false");
        }
    });

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
    header.append(toggle, nav);
}
