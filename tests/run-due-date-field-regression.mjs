import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName;
        this.attributes = new Map();
        this.children = [];
        this.listeners = new Map();
        this.dataset = {};
        this.hidden = false;
        this.disabled = false;
        this.tabIndex = 0;
        this.className = "";
        this.value = "";
        this.classList = { add: (value) => { this.className = `${this.className} ${value}`.trim(); } };
    }

    set textContent(value) { this._textContent = String(value); this.children = []; }
    get textContent() { return this._textContent ?? this.children.map((child) => child.textContent).join(""); }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    removeAttribute(name) { this.attributes.delete(name); }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = [...children]; }
    addEventListener(name, listener) { this.listeners.set(name, listener); }
    focus() { globalThis.document.activeElement = this; }
    click() {
        if (!this.disabled) this.dispatch("click");
    }
    dispatch(name, values = {}) {
        const event = {
            key: "",
            preventDefault() { this.defaultPrevented = true; },
            stopPropagation() { this.propagationStopped = true; },
            ...values,
        };
        this.listeners.get(name)?.(event);
        return event;
    }
    querySelector(selector) {
        const match = /^\[data-date="([^"]+)"\]$/.exec(selector);
        const date = match?.[1];
        return find(this, (element) => date !== undefined && element.dataset?.date === date) ?? null;
    }
}

function find(root, predicate) {
    if (predicate(root)) return root;
    for (const child of root.children ?? []) {
        const result = find(child, predicate);
        if (result) return result;
    }
    return null;
}

globalThis.document = {
    activeElement: null,
    createElement: (tag) => new FakeElement(tag),
};

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
async function moduleUrl(path) {
    const source = readFileSync(path, "utf8");
    return JSON.stringify(`data:text/javascript;base64,${Buffer.from(source).toString("base64")}`);
}
const vendorReplace = async (text) => text
    .replace('"../vendor/jalaali-js.js"', await moduleUrl(resolve(root, "public/assets/js/vendor/jalaali-js.js")))
    .replace('"../vendor/luxon.js"', await moduleUrl(resolve(root, "public/assets/js/vendor/luxon.js")));
const adapterSource = await vendorReplace(readFileSync(resolve(root, "public/assets/js/utils/date-time.js"), "utf8"));
const adapterUrl = `data:text/javascript;base64,${Buffer.from(adapterSource).toString("base64")}`;
const fieldSource = readFileSync(resolve(root, "public/assets/js/components/due-date-field.js"), "utf8")
    .replace('"../utils/date-time.js"', JSON.stringify(adapterUrl));
const { createDueDateField } = await import(`data:text/javascript;base64,${Buffer.from(fieldSource).toString("base64")}`);
const dateApi = await import(adapterUrl);

let assertions = 0;
function check(actual, expected, label) {
    assert.deepEqual(actual, expected, label);
    assertions += 1;
}

const field = createDueDateField({ timeZone: "Asia/Tehran", now: new Date("2026-09-30T15:00:00Z") });
const trigger = find(field.wrapper, (element) => element.id === "task-due-date-trigger");
const calendar = find(field.wrapper, (element) => element.id === "task-due-date-calendar");
const gregorian = find(field.wrapper, (element) => element.id === "task-due-date-gregorian");
const time = find(field.wrapper, (element) => element.name === "due_at_utc");
const legacyNotice = find(field.wrapper, (element) => element.className.includes("due-date-legacy-notice"));
const clearButton = find(field.wrapper, (element) => element.className.includes("due-date-clear"));

check(field.getIntent(), { kind: "untouched" }, "new task begins without a due-date mutation");
check(find(field.wrapper, (element) => element.className.includes("due-date-timezone-value")).textContent, "Asia/Tehran", "saved timezone is shown directly");
check(time.type, "text", "24-hour time is rendered as locale-independent text");
check(time.placeholder, "18:30", "time field communicates the 24-hour format");
trigger.click();
check(calendar.hidden, false, "date picker opens");
check(trigger.getAttribute("aria-expanded"), "true", "expanded state is accessible");
check(find(calendar, (element) => element.id === "task-due-date-calendar-heading").textContent, "مهر ۱۴۰۵", "picker opens on today's Jalali month in the saved timezone");

const todayCell = calendar.querySelector('[data-date="1405-07-08"]');
check(todayCell.tabIndex, 0, "calendar has a keyboard tab stop");
todayCell.focus();
const moveRightToLeft = todayCell.dispatch("keydown", { key: "ArrowLeft" });
check(moveRightToLeft.defaultPrevented, true, "RTL day navigation handles arrow keys");
check(document.activeElement.dataset.date, "1405-07-09", "RTL left arrow advances one day");
document.activeElement.dispatch("keydown", { key: "Escape" });
check(calendar.hidden, true, "Escape closes the date picker");
check(trigger.getAttribute("aria-expanded"), "false", "collapsed state is accessible");

trigger.click();
calendar.querySelector('[data-date="1405-07-08"]').click();
check(gregorian.textContent, "معادل میلادی: 30 September 2026", "Gregorian equivalent updates immediately after Jalali selection");
check(trigger.getAttribute("aria-expanded"), "false", "selecting a date closes the picker");
check(field.getIntent(), { kind: "set", jalaliDate: "1405-07-08", time: "" }, "date selection is retained until time is chosen");
time.value = "18:30";
time.dispatch("input");
check(field.getIntent(), { kind: "set", jalaliDate: "1405-07-08", time: "18:30" }, "time entry is represented separately in 24-hour form");
check(find(field.wrapper, (element) => element.id === "task-due-time-equivalent").textContent.startsWith("معادل ۱۲ ساعته: "), true, "12-hour equivalent is displayed below the 24-hour input");
check(find(field.wrapper, (element) => element.id === "task-due-time-equivalent").textContent.endsWith("—"), false, "valid time has a rendered AM/PM equivalent");
check(dateApi.localDateTimeToUtc("1405-07-08", `${time.value}:00`, "Asia/Tehran"), "2026-09-30T15:00:00Z", "form selection converts with the saved user timezone");

field.setTask({ due_at: null, due_at_utc: "2026-09-30T15:00:00Z" });
check(trigger.textContent, "۸ مهر ۱۴۰۵", "canonical task edit restores its Jalali date");
check(time.value, "18:30", "canonical task edit restores local wall-clock time");
check(find(field.wrapper, (element) => element.id === "task-due-time-equivalent").textContent.endsWith("—"), false, "saved canonical time restores its 12-hour equivalent");
check(field.getIntent(), { kind: "untouched" }, "opening an existing canonical task does not create a date update");

field.setTask({ due_at: "2026-09-30 18:30:42.000000", due_at_utc: null });
check(field.wrapper.dataset.legacy, "true", "legacy task marker is retained");
check(trigger.textContent, "۸ مهر ۱۴۰۵", "legacy task displays converted Jalali date");
check(time.value, "18:30", "legacy task retains its original clock components");
check(legacyNotice.hidden, false, "legacy timezone uncertainty is explicit");
check(field.getIntent(), { kind: "untouched" }, "editing a legacy task does not silently migrate it");
time.value = "19:15";
time.dispatch("change");
check(field.getIntent(), { kind: "set", jalaliDate: "1405-07-08", time: "19:15" }, "an intentional legacy edit produces a new canonical intent");
check(legacyNotice.hidden, true, "legacy notice clears after choosing a new time");

clearButton.click();
check(time.value, "", "Clear empties the clock field");
check(field.getIntent(), { kind: "clear" }, "Clear creates explicit clear intent for both API fields");
check(gregorian.textContent, "معادل میلادی: —", "Clear removes the Gregorian equivalent");

console.log(`Due Date field regression passed: ${assertions} assertions.`);
