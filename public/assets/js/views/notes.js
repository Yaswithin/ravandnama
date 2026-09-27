import * as notesApi from "../api/notes.js";
import { ApiError } from "../api/client.js";
import { clearFeedback, setFieldErrors, showFeedback } from "../components/feedback.js";

function createElement(tag, className, text = null) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== null) element.textContent = text;
    return element;
}

function formatDate(value) {
    if (typeof value !== "string" || value === "") return null;
    const date = new Date(value.includes("T") ? value : value.replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return null;
    return new Intl.DateTimeFormat("fa-IR", { dateStyle: "medium", timeStyle: "short" }).format(date);
}

export function renderNotesView({ getCsrfToken, refreshCsrfToken, onAuthenticationExpired }) {
    const page = createElement("div", "workspace-page notes-page");
    const heading = createElement("div", "notes-heading page-heading");
    const headingCopy = createElement("div", "page-heading-copy");
    const pageTitle = createElement("h1", "page-title", "یادداشت‌ها");
    pageTitle.tabIndex = -1;
    headingCopy.append(
        createElement("p", "eyebrow", "یادداشت‌های شخصی"),
        pageTitle,
        createElement("p", "page-lead", "ایده‌ها و نکته‌هایی را که می‌خواهی نگه داری، یک‌جا ثبت کن."),
    );
    const createButton = createElement("button", "primary-button note-create-button", "یادداشت تازه");
    createButton.type = "button";
    heading.append(headingCopy, createButton);

    const feedback = createElement("div", "notes-feedback");
    feedback.setAttribute("role", "status");
    feedback.setAttribute("aria-live", "polite");
    feedback.setAttribute("aria-atomic", "true");

    const tools = createElement("div", "notes-tools");
    const searchLabel = createElement("label", "note-search-label", "جست‌وجو در یادداشت‌ها");
    const search = document.createElement("input");
    search.type = "search";
    search.id = "notes-search";
    search.autocomplete = "off";
    search.placeholder = "عنوان یا متن یادداشت…";
    search.setAttribute("aria-label", "جست‌وجو در عنوان و متن یادداشت‌ها");
    searchLabel.htmlFor = search.id;
    searchLabel.append(search);
    const count = createElement("span", "note-count");
    count.setAttribute("aria-live", "polite");
    tools.append(searchLabel, count);

    const listRegion = createElement("section", "note-list-region");
    listRegion.setAttribute("aria-label", "فهرست یادداشت‌ها");

    const dialog = document.createElement("dialog");
    dialog.className = "note-dialog";
    dialog.setAttribute("aria-labelledby", "note-dialog-title");
    const form = document.createElement("form");
    form.className = "note-form";
    const formHeader = createElement("div", "note-dialog-header");
    const formHeading = createElement("div", "dialog-heading-copy");
    const dialogEyebrow = createElement("p", "eyebrow", "فضای یادداشت‌ها");
    const dialogTitle = createElement("h2", "section-title", "یادداشت تازه");
    dialogTitle.id = "note-dialog-title";
    formHeading.append(dialogEyebrow, dialogTitle);
    const closeButton = createElement("button", "text-button dialog-close", "بستن");
    closeButton.type = "button";
    closeButton.addEventListener("click", () => dialog.close());
    formHeader.append(formHeading, closeButton);

    const formFeedback = createElement("div", "form-feedback note-dialog-feedback");
    formFeedback.setAttribute("aria-live", "polite");
    formFeedback.setAttribute("aria-atomic", "true");

    const titleField = createField("title", "note-title", "عنوان", "text", 200);
    const contentField = createField("content", "note-content", "متن یادداشت", "textarea", 50000);
    contentField.input.rows = 12;
    contentField.input.placeholder = "یادداشتت را اینجا بنویس…";
    contentField.input.dir = "auto";

    const actions = createElement("div", "note-form-actions");
    const cancelButton = createElement("button", "text-button", "انصراف");
    cancelButton.type = "button";
    cancelButton.addEventListener("click", () => dialog.close());
    const editButton = createElement("button", "text-button note-view-edit", "ویرایش یادداشت");
    editButton.type = "button";
    const saveButton = createElement("button", "primary-button note-submit", "ساخت یادداشت");
    saveButton.type = "submit";
    actions.append(editButton, cancelButton, saveButton);
    form.append(formHeader, formFeedback, titleField.wrapper, contentField.wrapper, actions);
    dialog.append(form);

    page.append(heading, feedback, tools, count, listRegion, dialog);

    const state = {
        notes: [],
        loading: true,
        loadError: false,
        activeNote: null,
        readonly: false,
        busy: false,
        busyDeleteId: null,
        confirmDeleteId: null,
        returnFocus: null,
    };

    function showMessage(message, kind = "info", retry = null) {
        showFeedback(feedback, message, kind, retry);
    }

    function openForm(note, trigger, readonly = false) {
        state.activeNote = note;
        state.readonly = readonly;
        state.returnFocus = trigger;
        dialogTitle.textContent = readonly ? "نمایش یادداشت" : (note ? "ویرایش یادداشت" : "یادداشت تازه");
        titleField.input.value = note?.title ?? "";
        contentField.input.value = note?.content ?? "";
        titleField.input.readOnly = readonly;
        contentField.input.readOnly = readonly;
        titleField.input.required = !readonly;
        saveButton.hidden = readonly;
        editButton.hidden = !readonly;
        cancelButton.textContent = readonly ? "بستن" : "انصراف";
        setFieldErrors(form);
        clearFeedback(formFeedback);
        dialog.showModal();
        (readonly ? closeButton : titleField.input).focus();
    }

    function visibleNotes() {
        const query = search.value.trim().toLocaleLowerCase();
        if (!query) return state.notes;
        return state.notes.filter((note) => `${note.title}\n${note.content}`.toLocaleLowerCase().includes(query));
    }

    function renderList() {
        listRegion.replaceChildren();
        const visible = visibleNotes();
        count.textContent = state.loading ? "" : `${visible.length} از ${state.notes.length}`;

        if (state.loading) {
            const loading = createElement("p", "notes-loading", "در حال بارگذاری یادداشت‌ها…");
            loading.setAttribute("role", "status");
            listRegion.append(loading);
            return;
        }

        if (state.loadError) {
            const error = createElement("div", "notes-error-state");
            error.append(createElement("p", "notes-empty-copy", "دریافت یادداشت‌ها انجام نشد. اتصال را بررسی و دوباره تلاش کن."));
            const retry = createElement("button", "text-button", "تلاش دوباره");
            retry.type = "button";
            retry.addEventListener("click", loadNotes);
            error.append(retry);
            listRegion.append(error);
            return;
        }

        if (state.notes.length === 0) {
            const empty = createElement("div", "notes-empty-state");
            empty.append(
                createElement("h2", "empty-state-title", "هنوز یادداشتی نداری"),
                createElement("p", "notes-empty-copy", "هر فکری که می‌خواهی بعداً به آن برگردی، از همین‌جا ثبت کن."),
            );
            const add = createElement("button", "primary-button", "ساخت اولین یادداشت");
            add.type = "button";
            add.addEventListener("click", () => openForm(null, add));
            empty.append(add);
            listRegion.append(empty);
            return;
        }

        if (visible.length === 0) {
            listRegion.append(createElement("p", "notes-no-results", "یادداشتی با این عبارت پیدا نشد."));
            return;
        }

        const grid = createElement("div", "note-grid");
        for (const note of visible) grid.append(renderNoteCard(note));
        listRegion.append(grid);
    }

    function renderNoteCard(note) {
        const article = createElement("article", "note-card surface-card");
        const cardHeader = createElement("div", "note-card-header");
        const title = createElement("h3", "note-card-title", note.title);
        const actions = createElement("div", "note-card-actions");
        const view = createElement("button", "text-button", "نمایش");
        view.type = "button";
        view.setAttribute("aria-label", `نمایش یادداشت ${note.title}`);
        view.addEventListener("click", () => openForm(note, view, true));
        const edit = createElement("button", "text-button", "ویرایش");
        edit.type = "button";
        edit.setAttribute("aria-label", `ویرایش یادداشت ${note.title}`);
        edit.disabled = state.busyDeleteId === note.id;
        edit.addEventListener("click", () => openForm(note, edit));
        const remove = createElement("button", "text-button note-delete", "حذف");
        remove.type = "button";
        remove.dataset.deleteId = String(note.id);
        remove.setAttribute("aria-label", `حذف یادداشت ${note.title}`);
        remove.disabled = state.busyDeleteId === note.id;
        remove.addEventListener("click", () => {
            state.confirmDeleteId = note.id;
            renderList();
            listRegion.querySelector(`[data-delete-id="${note.id}"]`)?.focus({ preventScroll: true });
        });
        actions.append(view, edit, remove);
        cardHeader.append(title, actions);
        article.append(cardHeader);

        const excerpt = note.content.length > 240 ? `${[...note.content].slice(0, 240).join("")}…` : note.content;
        article.append(createElement("p", "note-card-content", excerpt || "این یادداشت هنوز متنی ندارد."));
        const dateLabel = formatDate(note.updated_at);
        if (dateLabel) {
            const metadata = createElement("p", "note-card-meta", `آخرین ویرایش ${dateLabel}`);
            const time = document.createElement("time");
            time.dateTime = note.updated_at;
            time.textContent = dateLabel;
            metadata.replaceChildren(createElement("span", "visually-hidden", "آخرین ویرایش در "), time);
            article.append(metadata);
        }
        if (state.confirmDeleteId === note.id) article.append(createDeleteConfirmation(note));
        return article;
    }

    function createDeleteConfirmation(note) {
        const confirmation = createElement("div", "delete-confirmation");
        confirmation.setAttribute("role", "group");
        confirmation.setAttribute("aria-label", `تأیید حذف یادداشت ${note.title}`);
        confirmation.append(createElement("p", "delete-confirmation-copy", `یادداشت «${note.title}» حذف شود؟ این کار برگشت‌پذیر نیست.`));
        const buttons = createElement("div", "delete-confirmation-actions");
        const cancel = createElement("button", "text-button", "انصراف");
        cancel.type = "button";
        cancel.addEventListener("click", () => {
            state.confirmDeleteId = null;
            renderList();
            listRegion.querySelector(`[data-delete-id="${note.id}"]`)?.focus({ preventScroll: true });
        });
        const confirm = createElement("button", "primary-button note-confirm-delete", state.busyDeleteId === note.id ? "در حال حذف…" : "حذف یادداشت");
        confirm.type = "button";
        confirm.disabled = state.busyDeleteId === note.id;
        confirm.addEventListener("click", () => deleteNote(note));
        buttons.append(cancel, confirm);
        confirmation.append(buttons);
        return confirmation;
    }

    function handleUnauthorized(error) {
        if (error instanceof ApiError && error.status === 401) {
            onAuthenticationExpired();
            return true;
        }
        return false;
    }

    async function loadNotes() {
        state.loading = true;
        state.loadError = false;
        renderList();
        try {
            state.notes = await notesApi.listNotes();
            state.loading = false;
            state.loadError = false;
            if (!state.notes.some((note) => note.id === state.confirmDeleteId)) state.confirmDeleteId = null;
        } catch (error) {
            state.loading = false;
            if (handleUnauthorized(error)) return;
            state.loadError = true;
        }
        renderList();
    }

    async function explainError(error, operation) {
        if (handleUnauthorized(error)) return null;
        if (error instanceof ApiError && error.status === 403) {
            try {
                await refreshCsrfToken();
                return `کد امنیتی تازه شد. برای ${operation} دوباره تلاش کن.`;
            } catch {
                return "نشست تازه نشد. اتصال را بررسی و دوباره تلاش کن.";
            }
        }
        if (error instanceof ApiError && error.status === 400) return "درخواست کامل نشد. اطلاعات را بررسی کن.";
        if (error instanceof ApiError && error.status === 404) return "این یادداشت دیگر در دسترس نیست. فهرست را تازه کن.";
        if (error instanceof ApiError && error.status === 422) return "عنوان و متن یادداشت را بررسی کن.";
        if (error instanceof ApiError && (error.status === 500 || error.status === 503)) return "سرور موقتاً در دسترس نیست. دوباره تلاش کن.";
        return "ارتباط برقرار نشد. اتصال را بررسی و دوباره تلاش کن.";
    }

    function applyValidationErrors(errors) {
        if (!errors || typeof errors !== "object" || Array.isArray(errors)) return false;
        const fieldErrors = {};
        if (typeof errors.title === "string") {
            fieldErrors.title = errors.title.includes("200 characters")
                ? "عنوان حداکثر ۲۰۰ نویسه باشد."
                : "عنوان را وارد کن؛ این فیلد الزامی است.";
        }
        if (typeof errors.content === "string") {
            fieldErrors.content = errors.content.includes("50000 characters")
                ? "متن یادداشت حداکثر ۵۰٬۰۰۰ نویسه باشد."
                : "متن یادداشت باید رشته باشد.";
        }
        if (Object.keys(fieldErrors).length === 0) return false;
        setFieldErrors(form, fieldErrors);
        form.querySelector('[aria-invalid="true"]')?.focus();
        return true;
    }

    function setBusy(busy) {
        state.busy = busy;
        for (const control of form.querySelectorAll("input, textarea, button")) control.disabled = busy;
        saveButton.textContent = busy ? "در حال ذخیره…" : (state.activeNote ? "ذخیره تغییرات" : "ساخت یادداشت");
    }

    async function submitNote(event) {
        event.preventDefault();
        clearFeedback(formFeedback);
        setFieldErrors(form);
        const title = titleField.input.value.trim();
        const content = contentField.input.value;
        const fieldErrors = {};
        if (!title) fieldErrors.title = "عنوان یادداشت الزامی است.";
        if ([...title].length > 200) fieldErrors.title = "عنوان حداکثر ۲۰۰ نویسه باشد.";
        if ([...content].length > 50000) fieldErrors.content = "متن یادداشت حداکثر ۵۰٬۰۰۰ نویسه باشد.";
        if (Object.keys(fieldErrors).length > 0) {
            setFieldErrors(form, fieldErrors);
            showFeedback(formFeedback, "اطلاعات مشخص‌شده را اصلاح کن.", "error");
            form.querySelector('[aria-invalid="true"]')?.focus();
            return;
        }

        setBusy(true);
        try {
            const note = state.activeNote
                ? await notesApi.updateNote(state.activeNote.id, { title, content }, await getWriteToken())
                : await notesApi.createNote({ title, content }, await getWriteToken());
            state.notes = state.activeNote
                ? state.notes.map((current) => current.id === note.id ? note : current)
                : [note, ...state.notes];
            state.notes.sort((left, right) => right.updated_at.localeCompare(left.updated_at));
            state.confirmDeleteId = null;
            state.returnFocus = createButton;
            showMessage(state.activeNote ? "یادداشت به‌روزرسانی شد." : "یادداشت ساخته شد.", "success");
            dialog.close();
            renderList();
        } catch (error) {
            const message = await explainError(error, "ذخیره یادداشت");
            if (message !== null) {
                const fieldErrorsFound = error instanceof ApiError && error.status === 422 && applyValidationErrors(error.errors);
                showFeedback(formFeedback, fieldErrorsFound ? "اطلاعات مشخص‌شده را اصلاح کن." : message, "error");
            }
            if (error instanceof ApiError && error.status === 404) await loadNotes();
        } finally {
            setBusy(false);
        }
    }

    async function getWriteToken() {
        return getCsrfToken() ?? await refreshCsrfToken();
    }

    async function deleteNote(note) {
        state.busyDeleteId = note.id;
        renderList();
        try {
            await notesApi.deleteNote(note.id, await getWriteToken());
            state.notes = state.notes.filter((current) => current.id !== note.id);
            state.confirmDeleteId = null;
            showMessage("یادداشت حذف شد.", "success");
            renderList();
            pageTitle.focus({ preventScroll: true });
        } catch (error) {
            if (handleUnauthorized(error)) return;
            if (error instanceof ApiError && error.status === 403) {
                try {
                    await refreshCsrfToken();
                    showMessage("کد امنیتی تازه شد. حذف را دوباره تأیید کن.", "error");
                } catch {
                    showMessage("نشست تازه نشد. اتصال را بررسی کن و دوباره تلاش کن.", "error");
                }
            } else if (error instanceof ApiError && error.status === 404) {
                showMessage("این یادداشت دیگر در دسترس نیست. فهرست تازه شد.", "error");
                state.confirmDeleteId = null;
                await loadNotes();
            } else {
                showMessage("نتیجه حذف روشن نیست. فهرست را تازه کن و وضعیت را بررسی کن.", "error", loadNotes);
            }
        } finally {
            state.busyDeleteId = null;
            renderList();
        }
    }

    function resetForm() {
        form.reset();
        titleField.input.readOnly = false;
        contentField.input.readOnly = false;
        setFieldErrors(form);
        clearFeedback(formFeedback);
        state.activeNote = null;
        state.readonly = false;
    }

    createButton.addEventListener("click", () => openForm(null, createButton));
    search.addEventListener("input", renderList);
    form.addEventListener("input", () => setFieldErrors(form));
    form.addEventListener("submit", submitNote);
    editButton.addEventListener("click", () => {
        state.readonly = false;
        titleField.input.readOnly = false;
        contentField.input.readOnly = false;
        saveButton.hidden = false;
        editButton.hidden = true;
        cancelButton.textContent = "انصراف";
        dialogTitle.textContent = "ویرایش یادداشت";
        titleField.input.focus();
    });
    dialog.addEventListener("close", () => {
        resetForm();
        const target = state.returnFocus;
        state.returnFocus = null;
        if (target?.isConnected) target.focus({ preventScroll: true });
        else createButton.focus({ preventScroll: true });
    });
    dialog.addEventListener("cancel", (event) => {
        if (state.busy) event.preventDefault();
    });
    contentField.input.addEventListener("keydown", (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key === "Enter" && !state.readonly && !state.busy) {
            form.requestSubmit();
        }
    });

    function createField(name, id, label, type, maxLength) {
        const wrapper = createElement("div", "form-field note-form-field");
        const labelNode = createElement("label", "", label);
        labelNode.htmlFor = id;
        const input = type === "textarea" ? document.createElement("textarea") : document.createElement("input");
        input.id = id;
        input.name = name;
        input.maxLength = maxLength;
        input.required = true;
        input.setAttribute("aria-describedby", `${name}-error`);
        if (type === "text") {
            input.type = "text";
            input.autocomplete = "off";
        }
        const error = createElement("span", "field-error");
        error.id = `${name}-error`;
        wrapper.append(labelNode, input, error);
        return { wrapper, input };
    }

    renderList();
    loadNotes();
    return page;
}
