import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName;
        this.attributes = new Map();
        this.children = [];
        this.listeners = new Map();
        this.dataset = {};
        this.hidden = false;
        this.className = "";
        this.classList = { add: (value) => { this.className = `${this.className} ${value}`.trim(); } };
    }

    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = [...children]; }
    addEventListener(name, listener) { this.listeners.set(name, listener); }
    click() { this.listeners.get("click")?.({ currentTarget: this }); }
}

globalThis.document = {
    createElement: (tagName) => new FakeElement(tagName),
    createElementNS: (_namespace, tagName) => new FakeElement(tagName),
};

let storageTouches = 0;
const storageGuard = new Proxy({}, {
    get() { storageTouches += 1; throw new Error("Password controls must not access browser storage."); },
    set() { storageTouches += 1; throw new Error("Password controls must not write browser storage."); },
});
globalThis.localStorage = storageGuard;
globalThis.sessionStorage = storageGuard;

const moduleUrl = new URL("../public/assets/js/components/password-visibility.js", import.meta.url);
const source = await readFile(moduleUrl, "utf8");
const { createPasswordVisibilityControl } = await import(`data:text/javascript;base64,${Buffer.from(source).toString("base64")}`);

let assertions = 0;
function check(actual, expected, label) {
    assert.deepEqual(actual, expected, label);
    assertions += 1;
}

function makeInput(id, autocomplete) {
    const input = new FakeElement("input");
    input.id = id;
    input.name = "password";
    input.type = "password";
    input.autocomplete = autocomplete;
    input.value = "synthetic-password-value";
    return input;
}

const loginInput = makeInput("login-password", "current-password");
const loginToggle = createPasswordVisibilityControl(loginInput, "گذرواژه");
check(loginInput.type, "password", "login password starts hidden");
check(loginToggle.type, "button", "control uses button semantics");
check(loginToggle.getAttribute("aria-label"), "نمایش گذرواژه", "hidden state has a show label");
check(loginToggle.getAttribute("aria-pressed"), "false", "hidden state is announced as not pressed");
check(loginToggle.title, "نمایش گذرواژه", "hidden state has a Persian title");
check(loginToggle.dataset.visibility, "hidden", "hidden state is available to visual styling");
check(loginToggle.children.length, 1, "password field has a single visible control icon");
check(loginToggle.getAttribute("aria-controls"), loginInput.id, "control identifies its input");

loginToggle.click();
check(loginInput.type, "text", "toggle reveals login password");
check(loginInput.value, "synthetic-password-value", "revealing does not change the password value");
check(loginToggle.getAttribute("aria-label"), "پنهان کردن گذرواژه", "visible state has a hide label");
check(loginToggle.getAttribute("aria-pressed"), "true", "visible state is announced as pressed");
check(loginToggle.title, "پنهان کردن گذرواژه", "visible state has a Persian title");
check(loginToggle.dataset.visibility, "visible", "visible state is available to visual styling");
check(loginToggle.children.length, 1, "password field still has only one control icon");

loginToggle.click();
check(loginInput.type, "password", "second toggle hides login password");
check(loginInput.value, "synthetic-password-value", "hiding also preserves the password value");
check(loginInput.autocomplete, "current-password", "login autocomplete remains unchanged");

const registerInput = makeInput("register-password", "new-password");
const confirmationInput = makeInput("register-confirm-password", "new-password");
const registerToggle = createPasswordVisibilityControl(registerInput, "گذرواژه");
const confirmationToggle = createPasswordVisibilityControl(confirmationInput, "تأیید گذرواژه");
check(registerInput.type, "password", "registration password starts hidden");
check(confirmationInput.type, "password", "confirmation starts hidden independently");
check(confirmationToggle.getAttribute("aria-label"), "نمایش تأیید گذرواژه", "confirmation control has a contextual label");

registerToggle.click();
check(registerInput.type, "text", "registration password can be revealed");
check(confirmationInput.type, "password", "revealing registration password leaves confirmation hidden");
confirmationToggle.click();
check(confirmationInput.type, "text", "confirmation password toggles independently");
check(registerInput.type, "text", "confirmation toggle leaves registration password visible");
registerToggle.click();
check(registerInput.type, "password", "registration password can be hidden independently");
check(confirmationInput.type, "text", "hiding registration password leaves confirmation visible");
check(registerInput.autocomplete, "new-password", "registration autocomplete remains unchanged");
check(storageTouches, 0, "password control never reads or writes browser storage");

console.log(`Password visibility regression passed: ${assertions} assertions.`);
