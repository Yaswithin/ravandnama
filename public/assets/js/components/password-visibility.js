function createEyeIcon(crossedOut = false) {
    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("aria-hidden", "true");
    svg.setAttribute("focusable", "false");
    svg.classList.add("password-visibility-icon");

    const eye = document.createElementNS("http://www.w3.org/2000/svg", "path");
    eye.setAttribute("d", "M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z");
    svg.append(eye);

    const pupil = document.createElementNS("http://www.w3.org/2000/svg", "circle");
    pupil.setAttribute("cx", "12");
    pupil.setAttribute("cy", "12");
    pupil.setAttribute("r", "3");
    svg.append(pupil);

    if (crossedOut) {
        const slash = document.createElementNS("http://www.w3.org/2000/svg", "path");
        slash.setAttribute("d", "m4 4 16 16");
        svg.append(slash);
    }

    return svg;
}

export function createPasswordVisibilityControl(input, fieldLabel = "گذرواژه") {
    const button = document.createElement("button");
    button.className = "password-visibility-toggle";
    button.type = "button";
    button.setAttribute("aria-controls", input.id);

    let visible = false;
    function update() {
        input.type = visible ? "text" : "password";
        button.setAttribute("aria-pressed", String(visible));
        button.setAttribute("aria-label", `${visible ? "پنهان کردن" : "نمایش"} ${fieldLabel}`);
        button.title = `${visible ? "پنهان کردن" : "نمایش"} ${fieldLabel}`;
        button.dataset.visibility = visible ? "visible" : "hidden";
        button.replaceChildren(createEyeIcon(visible));
    }

    update();
    button.addEventListener("click", () => {
        visible = !visible;
        update();
    });

    return button;
}
