import {
    currentDateInTimezone,
    formatGregorianLong,
    formatJalali,
    formatJalaliMonth,
    jalaliMonthLength,
    jalaliToGregorian,
    legacyWallClockToDisplay,
    jalaliWeekday,
    shiftJalaliDate,
    shiftJalaliMonth,
    utcToUserLocal,
} from "../utils/date-time.js";

const WEEKDAYS = ["شنبه", "یکشنبه", "دوشنبه", "سه‌شنبه", "چهارشنبه", "پنجشنبه", "جمعه"];
const MIN_GREGORIAN_YEAR = 1000;

function createElement(tag, className = "", text = null) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== null) element.textContent = text;
    return element;
}

function dateParts(value) {
    const [year, month] = value.split("-").map(Number);
    return { year, month };
}

function twelveHourEquivalent(value) {
    const match = /^(\d{2}):(\d{2})$/.exec(value);
    if (!match) return "معادل ۱۲ ساعته: —";

    const hour = Number(match[1]);
    const minute = Number(match[2]);
    if (hour > 23 || minute > 59) return "معادل ۱۲ ساعته: —";

    const clock = new Date(Date.UTC(2000, 0, 1, hour, minute));
    const localized = new Intl.DateTimeFormat("fa-IR", {
        hour: "numeric",
        minute: "2-digit",
        hour12: true,
        timeZone: "UTC",
    }).format(clock);
    return `معادل ۱۲ ساعته: ${localized}`;
}

export function createDueDateField({ timeZone, now = new Date() }) {
    const wrapper = createElement("fieldset", "task-form-field due-date-field");
    const legend = createElement("legend", "due-date-legend", "موعد");
    const zoneLine = createElement("p", "due-date-timezone");
    const zoneLabel = createElement("span", "", "منطقهٔ زمانی ذخیره‌شده: ");
    const zoneValue = createElement("bdi", "due-date-timezone-value", String(timeZone ?? ""));
    zoneLine.append(zoneLabel, zoneValue);

    const dateLabel = createElement("span", "due-date-control-label", "تاریخ شمسی");
    const dateButton = createElement("button", "due-date-trigger", "انتخاب تاریخ");
    dateButton.type = "button";
    dateButton.id = "task-due-date-trigger";
    dateButton.setAttribute("aria-haspopup", "grid");
    dateButton.setAttribute("aria-expanded", "false");
    dateButton.setAttribute("aria-controls", "task-due-date-calendar");
    dateButton.setAttribute("aria-describedby", "task-due-date-gregorian due_at_utc-error");
    dateLabel.id = "task-due-date-label";
    dateButton.setAttribute("aria-labelledby", dateLabel.id);

    const calendar = createElement("div", "due-date-calendar");
    calendar.id = "task-due-date-calendar";
    calendar.hidden = true;
    calendar.setAttribute("role", "group");
    calendar.setAttribute("aria-labelledby", "task-due-date-calendar-heading");

    const calendarHeader = createElement("div", "due-date-calendar-header");
    const yearControls = createElement("div", "due-date-calendar-controls");
    const previousYear = createElement("button", "due-date-calendar-nav", "سال پیش");
    previousYear.type = "button";
    previousYear.setAttribute("aria-label", "رفتن به سال پیش");
    const previousMonth = createElement("button", "due-date-calendar-nav", "ماه پیش");
    previousMonth.type = "button";
    previousMonth.setAttribute("aria-label", "رفتن به ماه پیش");
    const nextMonth = createElement("button", "due-date-calendar-nav", "ماه بعد");
    nextMonth.type = "button";
    nextMonth.setAttribute("aria-label", "رفتن به ماه بعد");
    const nextYear = createElement("button", "due-date-calendar-nav", "سال بعد");
    nextYear.type = "button";
    nextYear.setAttribute("aria-label", "رفتن به سال بعد");
    yearControls.append(previousYear, previousMonth, nextMonth, nextYear);
    const monthHeading = createElement("h3", "due-date-calendar-heading");
    monthHeading.id = "task-due-date-calendar-heading";
    monthHeading.setAttribute("aria-live", "polite");
    calendarHeader.append(yearControls, monthHeading);

    const calendarGrid = createElement("div", "due-date-calendar-grid");
    calendarGrid.setAttribute("role", "grid");
    calendarGrid.setAttribute("aria-labelledby", monthHeading.id);
    const weekdayRow = createElement("div", "due-date-weekday-row");
    weekdayRow.setAttribute("role", "row");
    for (const weekday of WEEKDAYS) {
        const heading = createElement("span", "due-date-weekday", weekday);
        heading.setAttribute("role", "columnheader");
        weekdayRow.append(heading);
    }
    calendarGrid.append(weekdayRow);

    const todayButton = createElement("button", "due-date-today", "امروز");
    todayButton.type = "button";
    calendar.append(calendarHeader, calendarGrid, todayButton);

    const gregorianLine = createElement("p", "due-date-gregorian", "معادل میلادی: —");
    gregorianLine.id = "task-due-date-gregorian";
    const legacyNotice = createElement("p", "due-date-legacy-notice", "این موعد قدیمی منطقهٔ زمانی مشخصی ندارد؛ ساعت آن بدون جابه‌جایی حفظ می‌شود.");
    legacyNotice.hidden = true;

    const timeLabel = createElement("label", "due-date-control-label", "ساعت (۲۴ ساعته)");
    timeLabel.htmlFor = "task-due-time";
    const timeInput = document.createElement("input");
    timeInput.type = "text";
    timeInput.id = "task-due-time";
    timeInput.name = "due_at_utc";
    timeInput.inputMode = "text";
    timeInput.dir = "ltr";
    timeInput.maxLength = 5;
    timeInput.placeholder = "18:30";
    timeInput.setAttribute("pattern", "(?:[01]\\d|2[0-3]):[0-5]\\d");
    timeInput.setAttribute("aria-describedby", "task-due-time-equivalent due_at_utc-error");
    const timeEquivalent = createElement("p", "due-date-time-equivalent", "معادل ۱۲ ساعته: —");
    timeEquivalent.id = "task-due-time-equivalent";

    const error = createElement("span", "field-error due-date-error");
    error.id = "due_at_utc-error";
    const clearButton = createElement("button", "text-button due-date-clear", "پاک کردن موعد");
    clearButton.type = "button";

    wrapper.append(legend, zoneLine, dateLabel, dateButton, calendar, gregorianLine,
        legacyNotice, timeLabel, timeInput, timeEquivalent, error, clearButton);

    let selectedDate = null;
    let visibleMonth = null;
    let focusedDate = null;
    let dirty = false;
    let explicitlyCleared = false;

    function localToday() {
        try {
            return currentDateInTimezone(timeZone, now).jalaliDate;
        } catch {
            setError("منطقهٔ زمانی ذخیره‌شده معتبر نیست.");
            return "1405-01-01";
        }
    }

    function dateIsPersistable(value) {
        return Number(jalaliToGregorian(value).slice(0, 4)) >= MIN_GREGORIAN_YEAR;
    }

    function updateSelection() {
        dateButton.textContent = selectedDate ? formatJalali(selectedDate) : "انتخاب تاریخ";
        gregorianLine.textContent = selectedDate
            ? `معادل میلادی: ${formatGregorianLong(jalaliToGregorian(selectedDate))}`
            : "معادل میلادی: —";
        legacyNotice.hidden = explicitlyCleared || wrapper.dataset.legacy !== "true";
    }

    function changeVisibleMonth(amount) {
        try {
            visibleMonth = shiftJalaliMonth(`${visibleMonth}-01`, amount).slice(0, 7);
            focusedDate = null;
            renderCalendar();
        } catch {
            // The month controls are bounded by the Jalali algorithm's supported range.
        }
    }

    function focusDate(value) {
        if (!dateIsPersistable(value)) return;
        if (value.slice(0, 7) !== visibleMonth) visibleMonth = value.slice(0, 7);
        focusedDate = value;
        renderCalendar();
        calendarGrid.querySelector(`[data-date="${value}"]`)?.focus();
    }

    function moveFocus(date, offset) {
        try {
            focusDate(shiftJalaliDate(date, offset));
        } catch {
            // Ignore navigation beyond the calendar's supported range.
        }
    }

    function handleDayKeydown(event, date) {
        const weekday = jalaliWeekday(date);
        const offsets = {
            ArrowRight: -1,
            ArrowLeft: 1,
            ArrowUp: -7,
            ArrowDown: 7,
            Home: -weekday,
            End: 6 - weekday,
        };
        if (Object.hasOwn(offsets, event.key)) {
            event.preventDefault();
            moveFocus(date, offsets[event.key]);
        } else if (event.key === "PageUp" || event.key === "PageDown") {
            event.preventDefault();
            try {
                const moved = shiftJalaliMonth(date, event.key === "PageUp" ? -1 : 1);
                focusDate(moved);
            } catch {
                // Ignore navigation beyond the supported Jalali range.
            }
        } else if (event.key === "Escape") {
            event.preventDefault();
            event.stopPropagation();
            closeCalendar(true);
        }
    }

    function selectDate(value) {
        if (!dateIsPersistable(value)) {
            setError("این تاریخ خارج از محدودهٔ قابل ذخیره است.");
            return;
        }
        selectedDate = value;
        visibleMonth = value.slice(0, 7);
        focusedDate = value;
        dirty = true;
        explicitlyCleared = false;
        delete wrapper.dataset.legacy;
        clearError();
        updateSelection();
        closeCalendar(true);
    }

    function renderCalendar() {
        if (!visibleMonth) return;
        const { year, month } = dateParts(`${visibleMonth}-01`);
        monthHeading.textContent = formatJalaliMonth(year, month);
        previousYear.disabled = previousMonth.disabled = false;
        nextYear.disabled = nextMonth.disabled = false;
        try { shiftJalaliMonth(`${visibleMonth}-01`, -12); } catch { previousYear.disabled = true; }
        try { shiftJalaliMonth(`${visibleMonth}-01`, -1); } catch { previousMonth.disabled = true; }
        try { shiftJalaliMonth(`${visibleMonth}-01`, 1); } catch { nextMonth.disabled = true; }
        try { shiftJalaliMonth(`${visibleMonth}-01`, 12); } catch { nextYear.disabled = true; }

        calendarGrid.replaceChildren(weekdayRow);
        const leading = jalaliWeekday(`${visibleMonth}-01`);
        const length = jalaliMonthLength(year, month);
        const totalCells = Math.ceil((leading + length) / 7) * 7;
        const today = localToday();
        const activeDate = focusedDate
            ?? (selectedDate?.slice(0, 7) === visibleMonth ? selectedDate : null)
            ?? (today.slice(0, 7) === visibleMonth ? today : `${visibleMonth}-01`);
        for (let rowIndex = 0; rowIndex < totalCells; rowIndex += 7) {
            const row = createElement("div", "due-date-week-row");
            row.setAttribute("role", "row");
            for (let column = 0; column < 7; column += 1) {
                const cellIndex = rowIndex + column;
                const day = cellIndex - leading + 1;
                const cell = createElement("div", "due-date-day-cell");
                cell.setAttribute("role", "gridcell");
                if (day < 1 || day > length) {
                    cell.setAttribute("aria-hidden", "true");
                } else {
                    const value = `${visibleMonth}-${String(day).padStart(2, "0")}`;
                    const button = createElement("button", "due-date-day", String(day));
                    button.type = "button";
                    button.dataset.date = value;
                    button.setAttribute("aria-label", `${formatJalali(value)}، ${formatGregorianLong(jalaliToGregorian(value))}`);
                    button.setAttribute("aria-pressed", String(value === selectedDate));
                    button.tabIndex = value === activeDate ? 0 : -1;
                    if (!dateIsPersistable(value)) button.disabled = true;
                    if (value === today) button.setAttribute("aria-current", "date");
                    button.addEventListener("click", () => selectDate(value));
                    button.addEventListener("keydown", (event) => handleDayKeydown(event, value));
                    cell.append(button);
                }
                row.append(cell);
            }
            calendarGrid.append(row);
        }
    }

    function openCalendar() {
        if (!visibleMonth) visibleMonth = (selectedDate ?? localToday()).slice(0, 7);
        calendar.hidden = false;
        dateButton.setAttribute("aria-expanded", "true");
        focusedDate = selectedDate ?? localToday();
        renderCalendar();
        calendarGrid.querySelector(`[data-date="${focusedDate}"]`)?.focus();
    }

    function closeCalendar(returnFocus) {
        calendar.hidden = true;
        dateButton.setAttribute("aria-expanded", "false");
        if (returnFocus) dateButton.focus({ preventScroll: true });
    }

    function clearError() {
        error.textContent = "";
        timeInput.removeAttribute("aria-invalid");
        dateButton.removeAttribute("aria-invalid");
    }

    function setError(message) {
        error.textContent = message;
        timeInput.setAttribute("aria-invalid", "true");
        dateButton.setAttribute("aria-invalid", "true");
    }

    function setTask(task) {
        selectedDate = null;
        visibleMonth = null;
        focusedDate = null;
        dirty = false;
        explicitlyCleared = false;
        delete wrapper.dataset.legacy;
        clearError();
        legacyNotice.hidden = true;
        timeInput.value = "";
        timeEquivalent.textContent = twelveHourEquivalent(timeInput.value);

        try {
            if (typeof task?.due_at_utc === "string" && task.due_at_utc !== "") {
                const local = utcToUserLocal(task.due_at_utc, timeZone);
                selectedDate = local.jalaliDate;
                timeInput.value = local.localTime.slice(0, 5);
            } else if (typeof task?.due_at === "string" && task.due_at !== "") {
                const local = legacyWallClockToDisplay(task.due_at);
                selectedDate = local.jalaliDate;
                timeInput.value = local.localTime.slice(0, 5);
                wrapper.dataset.legacy = "true";
            }
        } catch {
            setError("موعد ذخیره‌شده قابل نمایش نیست؛ آن را بررسی یا پاک کن.");
        }

        timeEquivalent.textContent = twelveHourEquivalent(timeInput.value);
        visibleMonth = (selectedDate ?? localToday()).slice(0, 7);
        updateSelection();
        renderCalendar();
    }

    dateButton.addEventListener("click", () => calendar.hidden ? openCalendar() : closeCalendar(false));
    previousYear.addEventListener("click", () => changeVisibleMonth(-12));
    previousMonth.addEventListener("click", () => changeVisibleMonth(-1));
    nextMonth.addEventListener("click", () => changeVisibleMonth(1));
    nextYear.addEventListener("click", () => changeVisibleMonth(12));
    todayButton.addEventListener("click", () => {
        try { selectDate(currentDateInTimezone(timeZone, now).jalaliDate); }
        catch { setError("منطقهٔ زمانی ذخیره‌شده معتبر نیست."); }
    });
    function updateTimeInput() {
        dirty = true;
        explicitlyCleared = false;
        delete wrapper.dataset.legacy;
        clearError();
        timeEquivalent.textContent = twelveHourEquivalent(timeInput.value);
        updateSelection();
    }
    timeInput.addEventListener("input", updateTimeInput);
    timeInput.addEventListener("change", updateTimeInput);
    clearButton.addEventListener("click", () => {
        selectedDate = null;
        timeInput.value = "";
        timeEquivalent.textContent = twelveHourEquivalent(timeInput.value);
        dirty = true;
        explicitlyCleared = true;
        delete wrapper.dataset.legacy;
        clearError();
        updateSelection();
        dateButton.focus({ preventScroll: true });
    });
    calendar.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            event.preventDefault();
            event.stopPropagation();
            closeCalendar(true);
        }
    });

    setTask(null);

    return {
        wrapper,
        setTask,
        clearError,
        setError,
        focus() { timeInput.focus(); },
        getIntent() {
        if (!dirty) return { kind: "untouched" };
            if (explicitlyCleared) return { kind: "clear" };
            return { kind: "set", jalaliDate: selectedDate, time: timeInput.value };
        },
    };
}
