import * as projectsApi from "../api/projects.js";
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

    return new Intl.DateTimeFormat("fa-IR", { dateStyle: "medium" }).format(date);
}

export function renderProjectsView({ getCsrfToken, refreshCsrfToken, onAuthenticationExpired }) {
    const page = createElement("div", "workspace-page projects-page");
    const heading = createElement("div", "projects-heading page-heading");
    heading.append(
        createElement("div", "page-heading-copy"),
    );
    heading.firstElementChild.append(
        createElement("p", "eyebrow", "برنامه‌ریزی و تمرکز"),
        createElement("h1", "page-title", "پروژه‌ها"),
        createElement("p", "page-lead", "موضوع‌های بزرگ‌تر را در فضاهایی روشن و مستقل دنبال کن."),
    );
    heading.firstElementChild.lastElementChild.tabIndex = -1;

    const createButton = createElement("button", "primary-button project-create-button", "ساخت پروژه");
    createButton.type = "button";
    heading.append(createButton);

    const feedback = createElement("div", "projects-feedback");
    feedback.setAttribute("role", "status");
    feedback.setAttribute("aria-live", "polite");
    feedback.setAttribute("aria-atomic", "true");

    const listHeading = createElement("div", "projects-list-heading");
    listHeading.append(
        createElement("h2", "section-title", "همه پروژه‌ها"),
        createElement("span", "project-count"),
    );

    const listRegion = createElement("section", "project-list-region");
    listRegion.setAttribute("aria-labelledby", "projects-list-title");
    listHeading.firstElementChild.id = "projects-list-title";
    const projectList = createElement("div", "project-grid");

    const dialog = document.createElement("dialog");
    dialog.className = "project-dialog";
    dialog.setAttribute("aria-labelledby", "project-dialog-title");
    const dialogForm = document.createElement("form");
    dialogForm.className = "project-form";

    const dialogHeader = createElement("div", "project-dialog-header");
    const dialogHeading = createElement("div", "dialog-heading-copy");
    const dialogTitle = createElement("h2", "section-title", "ساخت پروژه");
    dialogTitle.id = "project-dialog-title";
    dialogHeading.append(
        createElement("p", "eyebrow", "فضای تازه"),
        dialogTitle,
    );
    const closeButton = createElement("button", "text-button dialog-close", "بستن");
    closeButton.type = "button";
    closeButton.addEventListener("click", () => dialog.close());
    dialogHeader.append(dialogHeading, closeButton);

    const dialogFeedback = createElement("div", "form-feedback project-dialog-feedback");
    dialogFeedback.setAttribute("aria-live", "polite");
    dialogFeedback.setAttribute("aria-atomic", "true");

    const nameField = createProjectField({
        name: "name",
        id: "project-name",
        label: "نام پروژه",
        type: "text",
        required: true,
    });
    const descriptionField = createProjectField({
        name: "description",
        id: "project-description",
        label: "توضیحات",
        type: "textarea",
        required: false,
    });

    const formActions = createElement("div", "project-form-actions");
    const cancelButton = createElement("button", "text-button project-cancel", "انصراف");
    cancelButton.type = "button";
    cancelButton.addEventListener("click", () => dialog.close());
    const submitButton = createElement("button", "primary-button project-submit", "ساخت پروژه");
    submitButton.type = "submit";
    formActions.append(cancelButton, submitButton);
    dialogForm.append(dialogHeader, dialogFeedback, nameField.wrapper, descriptionField.wrapper, formActions);
    dialog.append(dialogForm);

    page.append(heading, feedback, listHeading, listRegion, dialog);

    const state = {
        projects: [],
        loading: true,
        loadError: false,
        activeProject: null,
        busy: false,
        busyDeleteId: null,
        confirmDeleteId: null,
        returnFocus: null,
    };

    function showMessage(message, kind = "info", retry = null) {
        showFeedback(feedback, message, kind, retry);
    }

    function openForm(project, trigger) {
        state.activeProject = project;
        state.returnFocus = trigger;
        dialogTitle.textContent = project ? "ویرایش پروژه" : "ساخت پروژه";
        nameField.input.value = project?.name ?? "";
        descriptionField.input.value = project?.description ?? "";
        submitButton.textContent = project ? "ذخیره تغییرات" : "ساخت پروژه";
        setFieldErrors(dialogForm);
        clearFeedback(dialogFeedback);
        dialog.showModal();
        nameField.input.focus();
    }

    function renderList() {
        listRegion.replaceChildren();
        listHeading.lastElementChild.textContent = state.loading ? "" : String(state.projects.length);

        if (state.loading) {
            const loading = createElement("p", "projects-loading", "در حال بارگذاری پروژه‌ها…");
            loading.setAttribute("role", "status");
            listRegion.append(loading);
            return;
        }

        if (state.loadError) {
            const errorState = createElement("div", "projects-error-state");
            errorState.append(
                createElement("p", "projects-error-copy", "دریافت پروژه‌ها انجام نشد. اتصال را بررسی و دوباره تلاش کن."),
            );
            const retry = createElement("button", "text-button", "تلاش دوباره");
            retry.type = "button";
            retry.addEventListener("click", loadProjects);
            errorState.append(retry);
            listRegion.append(errorState);
            return;
        }

        if (state.projects.length === 0) {
            const empty = createElement("div", "projects-empty-state");
            empty.append(
                createElement("span", "empty-state-mark", "ر"),
                createElement("h2", "empty-state-title", "هنوز پروژه‌ای نساخته‌ای"),
                createElement("p", "empty-state-copy", "برای موضوع‌های مهمت یک فضای روشن بساز و قدم بعدی را مشخص کن."),
            );
            const emptyCreate = createElement("button", "primary-button", "ساخت پروژه");
            emptyCreate.type = "button";
            emptyCreate.addEventListener("click", () => openForm(null, emptyCreate));
            empty.append(emptyCreate);
            listRegion.append(empty);
            return;
        }

        const grid = createElement("div", "project-grid");
        for (const project of state.projects) {
            grid.append(renderProjectCard(project));
        }
        listRegion.append(grid);
    }

    function renderProjectCard(project) {
        const article = createElement("article", "project-card surface-card");
        const cardHeader = createElement("div", "project-card-header");
        const title = createElement("h3", "project-card-title", project.name);
        title.tabIndex = -1;
        cardHeader.append(title);

        const actions = createElement("div", "project-card-actions");
        const edit = createElement("button", "text-button project-edit", "ویرایش");
        edit.type = "button";
        edit.setAttribute("aria-label", `ویرایش پروژه ${project.name}`);
        edit.disabled = state.busyDeleteId === project.id;
        edit.addEventListener("click", () => openForm(project, edit));
        actions.append(edit);

        const remove = createElement("button", "text-button project-delete", "حذف");
        remove.type = "button";
        remove.dataset.deleteId = String(project.id);
        remove.setAttribute("aria-label", `حذف پروژه ${project.name}`);
        remove.disabled = state.busyDeleteId === project.id;
        remove.addEventListener("click", () => {
            state.confirmDeleteId = project.id;
            renderList();
            listRegion.querySelector(`[data-delete-id="${project.id}"]`)?.focus({ preventScroll: true });
        });
        actions.append(remove);
        cardHeader.append(actions);
        article.append(cardHeader);

        if (typeof project.description === "string" && project.description.trim() !== "") {
            article.append(createElement("p", "project-card-description", project.description));
        } else {
            article.append(createElement("p", "project-card-description project-card-description--empty", "بدون توضیحات"));
        }

        const dateLabel = formatDate(project.created_at);
        if (dateLabel) {
            const metadata = createElement("p", "project-card-meta", `ایجاد ${dateLabel}`);
            const time = document.createElement("time");
            time.dateTime = project.created_at;
            time.textContent = dateLabel;
            metadata.replaceChildren(createElement("span", "visually-hidden", "ایجادشده در "), time);
            article.append(metadata);
        }

        if (state.confirmDeleteId === project.id) {
            article.append(createDeleteConfirmation(project));
        }

        return article;
    }

    function createDeleteConfirmation(project) {
        const confirmation = createElement("div", "delete-confirmation");
        confirmation.setAttribute("role", "group");
        confirmation.setAttribute("aria-label", `تأیید حذف پروژه ${project.name}`);
        confirmation.append(
            createElement("p", "delete-confirmation-copy", `از حذف «${project.name}» مطمئنی؟ وظایف مرتبط حذف نمی‌شوند و بدون پروژه باقی می‌مانند.`),
        );

        const actions = createElement("div", "delete-confirmation-actions");
        const cancel = createElement("button", "text-button", "انصراف");
        cancel.type = "button";
        cancel.addEventListener("click", () => {
            state.confirmDeleteId = null;
            renderList();
            listRegion.querySelector(`[data-delete-id="${project.id}"]`)?.focus({ preventScroll: true });
        });

        const confirm = createElement("button", "primary-button project-confirm-delete", state.busyDeleteId === project.id ? "در حال حذف…" : "حذف پروژه");
        confirm.type = "button";
        confirm.disabled = state.busyDeleteId === project.id;
        confirm.addEventListener("click", () => deleteProject(project));
        actions.append(cancel, confirm);
        confirmation.append(actions);

        return confirmation;
    }

    async function loadProjects() {
        state.loading = true;
        state.loadError = false;
        renderList();

        try {
            state.projects = await projectsApi.listProjects();
            state.loading = false;
            state.loadError = false;
            if (!state.projects.some((project) => project.id === state.confirmDeleteId)) {
                state.confirmDeleteId = null;
            }
            renderList();
        } catch (error) {
            state.loading = false;
            if (handleUnauthorized(error)) return;
            state.loadError = true;
            renderList();
        }
    }

    function handleUnauthorized(error) {
        if (error instanceof ApiError && error.status === 401) {
            onAuthenticationExpired();
            return true;
        }

        return false;
    }

    function describeError(error, operation) {
        if (handleUnauthorized(error)) return null;

        if (error instanceof ApiError) {
            if (error.status === 403) {
                return refreshCsrfToken()
                    .then(() => `کد امنیتی تازه شد. برای ${operation} دوباره دکمهٔ مربوط را بزن.`)
                    .catch(() => "نشست تازه نشد. اتصال را بررسی کن و دوباره تلاش کن.");
            }
            if (error.status === 400) return Promise.resolve("درخواست کامل نشد. اطلاعات را بررسی کن.");
            if (error.status === 404) return Promise.resolve("این پروژه دیگر در دسترس نیست. فهرست را تازه کن.");
            if (error.status === 409) return Promise.resolve("این تغییر با وضعیت فعلی پروژه سازگار نیست.");
            if (error.status === 422) return Promise.resolve("اطلاعات پروژه را بررسی و اصلاح کن.");
            if (error.status === 500 || error.status === 503) return Promise.resolve("سرور موقتاً در دسترس نیست. دوباره تلاش کن.");
        }

        return Promise.resolve("ارتباط برقرار نشد. اتصال را بررسی و دوباره تلاش کن.");
    }

    function applyValidationErrors(errors) {
        if (!errors || typeof errors !== "object" || Array.isArray(errors)) {
            return false;
        }

        const fieldErrors = {};
        if (typeof errors.name === "string") {
            fieldErrors.name = errors.name.includes("200 characters")
                ? "نام پروژه حداکثر ۲۰۰ نویسه باشد."
                : "نام پروژه را بررسی کن؛ این فیلد الزامی است.";
        }
        if (typeof errors.description === "string") {
            fieldErrors.description = "توضیحات پروژه را بررسی کن؛ حداکثر ۱۰۰۰۰ نویسه مجاز است.";
        }

        if (Object.keys(fieldErrors).length === 0) {
            return false;
        }

        setFieldErrors(dialogForm, fieldErrors);
        dialogForm.querySelector('[aria-invalid="true"]')?.focus();
        return true;
    }

    function setFormBusy(busy) {
        state.busy = busy;
        for (const control of dialogForm.querySelectorAll("input, textarea, button")) {
            control.disabled = busy;
        }
        submitButton.textContent = busy
            ? (state.activeProject ? "در حال ذخیره…" : "در حال ساخت…")
            : (state.activeProject ? "ذخیره تغییرات" : "ساخت پروژه");
    }

    async function submitProject(event) {
        event.preventDefault();
        clearFeedback(dialogFeedback);
        setFieldErrors(dialogForm);

        const name = nameField.input.value.trim();
        const description = descriptionField.input.value.trim();
        const fieldErrors = {};

        if (name === "") fieldErrors.name = "نام پروژه الزامی است.";
        if ([...name].length > 200) fieldErrors.name = "نام پروژه حداکثر ۲۰۰ نویسه باشد.";
        if ([...description].length > 10000) fieldErrors.description = "توضیحات حداکثر ۱۰۰۰۰ نویسه باشد.";

        if (Object.keys(fieldErrors).length > 0) {
            setFieldErrors(dialogForm, fieldErrors);
            showFeedback(dialogFeedback, "اطلاعات مشخص‌شده را اصلاح کن.", "error");
            dialogForm.querySelector(`[name="${Object.keys(fieldErrors)[0]}"]`)?.focus();
            return;
        }

        setFormBusy(true);
        try {
            const data = { name, description };
            const project = state.activeProject
                ? await projectsApi.updateProject(state.activeProject.id, data, await getWriteToken())
                : await projectsApi.createProject(data, await getWriteToken());

            if (state.activeProject) {
                state.projects = state.projects.map((current) => current.id === project.id ? project : current);
            } else {
                state.projects = [project, ...state.projects];
            }

            state.confirmDeleteId = null;
            state.returnFocus = heading.firstElementChild.lastElementChild;
            clearFeedback(feedback);
            showMessage(state.activeProject ? "تغییرات پروژه ذخیره شد." : "پروژه ساخته شد.", "success");
            dialog.close();
            formReset();
            renderList();
        } catch (error) {
            const message = await describeError(error, "ذخیره پروژه");
            if (message !== null) {
                const hasFieldErrors = error instanceof ApiError
                    && error.status === 422
                    && applyValidationErrors(error.errors);
                showFeedback(
                    dialogFeedback,
                    hasFieldErrors ? "اطلاعات مشخص‌شده را اصلاح کن." : message,
                    "error",
                );
            }
            if (error instanceof ApiError && error.status === 404) await loadProjects();
        } finally {
            setFormBusy(false);
        }
    }

    async function getWriteToken() {
        return getCsrfToken() ?? await refreshCsrfToken();
    }

    function formReset() {
        dialogForm.reset();
        setFieldErrors(dialogForm);
        clearFeedback(dialogFeedback);
        state.activeProject = null;
    }

    async function deleteProject(project) {
        state.busyDeleteId = project.id;
        renderList();

        try {
            await projectsApi.deleteProject(project.id, await getWriteToken());
            state.projects = state.projects.filter((current) => current.id !== project.id);
            state.confirmDeleteId = null;
            state.busyDeleteId = null;
            showMessage("پروژه حذف شد.", "success");
            renderList();
            heading.firstElementChild.lastElementChild.focus({ preventScroll: true });
        } catch (error) {
            state.busyDeleteId = null;
            if (handleUnauthorized(error)) return;

            if (error instanceof ApiError && error.status === 403) {
                try {
                    await refreshCsrfToken();
                    showMessage("کد امنیتی تازه شد. حذف پروژه را دوباره تأیید کن.", "error");
                } catch {
                    showMessage("نشست تازه نشد. اتصال را بررسی کن و حذف را دوباره تأیید کن.", "error");
                }
            } else if (error instanceof ApiError && error.status === 404) {
                showMessage("این پروژه دیگر در دسترس نیست. فهرست به‌روزرسانی شد.", "error");
                state.confirmDeleteId = null;
                await loadProjects();
            } else if (error instanceof ApiError && error.status === 409) {
                showMessage("حذف پروژه با وضعیت فعلی آن سازگار نیست.", "error");
                renderList();
            } else if (error instanceof ApiError && (error.status === 400 || error.status === 422)) {
                showMessage("حذف پروژه انجام نشد. فهرست را تازه و دوباره بررسی کن.", "error", loadProjects);
                renderList();
            } else {
                showMessage("نتیجه حذف روشن نیست. فهرست را تازه کن و وضعیت را بررسی کن.", "error", loadProjects);
                renderList();
            }
        }
    }

    createButton.addEventListener("click", () => openForm(null, createButton));
    dialogForm.addEventListener("input", () => setFieldErrors(dialogForm));
    dialogForm.addEventListener("submit", submitProject);
    dialog.addEventListener("close", () => {
        formReset();
        const target = state.returnFocus;
        state.returnFocus = null;
        if (target?.isConnected) target.focus({ preventScroll: true });
        else heading.firstElementChild.lastElementChild.focus({ preventScroll: true });
    });
    dialog.addEventListener("cancel", (event) => {
        if (state.busy) event.preventDefault();
    });

    function createProjectField({ name, id, label, type, required }) {
        const wrapper = createElement("div", "form-field project-form-field");
        const labelNode = createElement("label", "", label);
        labelNode.htmlFor = id;

        const input = type === "textarea" ? document.createElement("textarea") : document.createElement("input");
        input.id = id;
        input.name = name;
        input.required = required;
        input.setAttribute("aria-describedby", `${id}-error`);
        if (type === "textarea") {
            input.rows = 5;
            input.placeholder = "توضیح کوتاهی برای این پروژه بنویس…";
        } else {
            input.type = type;
            input.autocomplete = "off";
        }

        const error = createElement("span", "field-error");
        error.id = `${name}-error`;
        wrapper.append(labelNode, input, error);

        return { wrapper, input };
    }

    renderList();
    loadProjects();

    return page;
}
