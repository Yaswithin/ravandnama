import * as projectsApi from "../api/projects.js";
import * as tasksApi from "../api/tasks.js";
import { ApiError } from "../api/client.js";
import { clearFeedback, setFieldErrors, showFeedback } from "../components/feedback.js";

function createElement(tag, className, text = null) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== null) element.textContent = text;
    return element;
}

function localDateInput(value) {
    if (typeof value !== "string") return "";
    const match = /^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})(?:\.\d+)?$/.exec(value);
    return match ? `${match[1]}T${match[2]}` : "";
}

function displayDate(value) {
    if (typeof value !== "string") return null;
    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?$/.exec(value);
    if (!match) return value;

    const [, year, month, day, hour, minute, second = "0"] = match;
    const date = new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second));
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat("fa-IR", {
        dateStyle: "medium",
        timeStyle: "short",
    }).format(date);
}

function translateValidationErrors(errors) {
    if (!errors || typeof errors !== "object" || Array.isArray(errors)) return {};

    const translated = {};
    if (typeof errors.title === "string") {
        translated.title = errors.title.includes("200 characters")
            ? "عنوان حداکثر ۲۰۰ نویسه باشد."
            : "عنوان وظیفه را وارد یا اصلاح کن.";
    }
    if (typeof errors.description === "string") {
        translated.description = "توضیحات را بررسی کن؛ حداکثر ۱۰۰۰۰ نویسه مجاز است.";
    }
    if (typeof errors.due_at === "string") translated.due_at = "موعد معتبر نیست؛ تاریخ و ساعت را دوباره انتخاب کن.";
    if (typeof errors.project_id === "string") translated.project_id = "پروژه انتخاب‌شده معتبر نیست. فهرست پروژه‌ها را تازه کن.";
    if (typeof errors.status === "string") translated.status = "وضعیت انتخاب‌شده معتبر نیست.";
    return translated;
}

export function renderTasksView({ getCsrfToken, refreshCsrfToken, onAuthenticationExpired }) {
    const page = createElement("div", "workspace-page tasks-page");
    const heading = createElement("div", "tasks-heading page-heading");
    const headingCopy = createElement("div", "page-heading-copy");
    headingCopy.append(
        createElement("p", "eyebrow", "برنامه‌ریزی روزانه"),
        createElement("h1", "page-title", "وظایف"),
        createElement("p", "page-lead", "قدم بعدی را روشن کن و با تمرکز پیش برو."),
    );
    headingCopy.lastElementChild.tabIndex = -1;
    heading.append(headingCopy);

    const createButton = createElement("button", "primary-button task-create-button", "ساخت وظیفه");
    createButton.type = "button";
    heading.append(createButton);

    const feedback = createElement("div", "tasks-feedback");
    feedback.setAttribute("role", "status");
    feedback.setAttribute("aria-live", "polite");
    feedback.setAttribute("aria-atomic", "true");

    const projectNotice = createElement("div", "tasks-project-notice");
    projectNotice.setAttribute("role", "status");
    projectNotice.setAttribute("aria-live", "polite");

    const filters = createElement("section", "task-filters");
    filters.setAttribute("aria-label", "فیلتر وظایف");
    const statusFilters = createElement("div", "task-status-filters");
    const statusOptions = [
        ["all", "همه"],
        ["pending", "در انتظار"],
        ["completed", "انجام‌شده"],
    ];
    const statusButtons = new Map();
    for (const [value, label] of statusOptions) {
        const button = createElement("button", "task-filter-button", label);
        button.type = "button";
        button.dataset.statusFilter = value;
        button.setAttribute("aria-pressed", "false");
        statusButtons.set(value, button);
        statusFilters.append(button);
    }

    const projectFilterLabel = createElement("label", "task-project-filter-label", "پروژه");
    const projectFilter = document.createElement("select");
    projectFilter.className = "task-project-filter";
    projectFilterLabel.append(projectFilter);
    filters.append(statusFilters, projectFilterLabel);

    const taskCount = createElement("p", "task-count");
    taskCount.setAttribute("aria-live", "polite");
    const taskListRegion = createElement("section", "task-list-region");
    taskListRegion.setAttribute("aria-label", "فهرست وظایف");

    const dialog = document.createElement("dialog");
    dialog.className = "task-dialog";
    dialog.setAttribute("aria-labelledby", "task-dialog-title");
    const form = document.createElement("form");
    form.className = "task-form";
    form.noValidate = true;
    const formHeader = createElement("div", "task-dialog-header");
    const formHeadingCopy = createElement("div", "dialog-heading-copy");
    const formTitle = createElement("h2", "section-title", "ساخت وظیفه");
    formTitle.id = "task-dialog-title";
    formHeadingCopy.append(createElement("p", "eyebrow", "قدم بعدی"), formTitle);
    const closeButton = createElement("button", "text-button dialog-close", "بستن");
    closeButton.type = "button";
    closeButton.addEventListener("click", () => dialog.close());
    formHeader.append(formHeadingCopy, closeButton);

    const formFeedback = createElement("div", "form-feedback task-form-feedback");
    formFeedback.setAttribute("aria-live", "polite");
    formFeedback.setAttribute("aria-atomic", "true");

    const titleField = createField({ name: "title", id: "task-title", label: "عنوان", type: "text", required: true });
    const descriptionField = createField({ name: "description", id: "task-description", label: "توضیحات", type: "textarea" });
    const dueField = createField({ name: "due_at", id: "task-due-at", label: "موعد", type: "datetime-local" });
    dueField.input.step = "1";

    const projectField = createField({ name: "project_id", id: "task-project-id", label: "پروژه", type: "select" });
    const statusField = createField({ name: "status", id: "task-status", label: "وضعیت", type: "select" });
    statusField.wrapper.hidden = true;

    const formActions = createElement("div", "task-form-actions");
    const cancelButton = createElement("button", "text-button", "انصراف");
    cancelButton.type = "button";
    cancelButton.addEventListener("click", () => dialog.close());
    const submitButton = createElement("button", "primary-button", "ساخت وظیفه");
    submitButton.type = "submit";
    formActions.append(cancelButton, submitButton);
    form.append(formHeader, formFeedback, titleField.wrapper, descriptionField.wrapper,
        projectField.wrapper, dueField.wrapper, statusField.wrapper, formActions);
    dialog.append(form);

    page.append(heading, feedback, projectNotice, filters, taskCount, taskListRegion, dialog);

    const state = {
        tasks: [],
        projects: [],
        tasksLoading: true,
        tasksError: false,
        projectsLoading: true,
        projectsError: false,
        statusFilter: "all",
        projectFilter: "all",
        activeTask: null,
        busyForm: false,
        busyTaskIds: new Set(),
        confirmDeleteId: null,
        returnFocus: null,
    };

    renderProjectOptions(projectField.input, "", false);

    function setNotice(message, kind = "info", retry = null) {
        if (!message) {
            clearFeedback(feedback);
            return;
        }
        showFeedback(feedback, message, kind, retry);
    }

    function projectById(id) {
        return state.projects.find((project) => String(project.id) === String(id)) ?? null;
    }

    function visibleTasks() {
        return state.tasks.filter((task) => {
            const matchesStatus = state.statusFilter === "all" || task.status === state.statusFilter;
            const matchesProject = state.projectFilter === "all"
                || (state.projectFilter === "none" ? task.project_id === null : String(task.project_id) === state.projectFilter);
            return matchesStatus && matchesProject;
        });
    }

    function renderProjectOptions(select, selected = "all", includeAll = true) {
        const options = [];
        if (includeAll) options.push(["all", "همه پروژه‌ها"]);
        else options.push(["", "بدون پروژه"]);
        if (includeAll) options.push(["none", "بدون پروژه"]);
        for (const project of state.projects) options.push([String(project.id), project.name]);
        select.replaceChildren(...options.map(([value, label]) => {
            const option = document.createElement("option");
            option.value = value;
            option.textContent = label;
            return option;
        }));
        select.value = selected;
    }

    function renderFilters() {
        for (const [value, button] of statusButtons) {
            const selected = value === state.statusFilter;
            button.setAttribute("aria-pressed", String(selected));
            button.classList.toggle("is-selected", selected);
        }
        const current = projectFilter.value || "all";
        renderProjectOptions(projectFilter, current, true);
        projectFilter.disabled = state.projectsLoading || state.projectsError;
    }

    function renderList() {
        renderFilters();
        taskListRegion.replaceChildren();

        if (state.tasksLoading) {
            const loading = createElement("p", "task-loading", "در حال بارگذاری وظایف…");
            loading.setAttribute("role", "status");
            taskListRegion.append(loading);
            taskCount.textContent = "";
            return;
        }

        if (state.tasksError) {
            const errorState = createElement("div", "task-empty-state");
            errorState.append(
                createElement("h2", "task-empty-title", "بارگذاری وظایف انجام نشد"),
                createElement("p", "task-empty-copy", "اتصال را بررسی کن و دوباره تلاش کن."),
            );
            const retry = createElement("button", "text-button", "تلاش دوباره");
            retry.type = "button";
            retry.addEventListener("click", loadTasks);
            errorState.append(retry);
            taskListRegion.append(errorState);
            taskCount.textContent = "";
            return;
        }

        const filtered = visibleTasks();
        taskCount.textContent = `${filtered.length} وظیفه`;

        if (filtered.length === 0) {
            const empty = createElement("div", "task-empty-state");
            const noTasks = state.tasks.length === 0 && state.statusFilter === "all" && state.projectFilter === "all";
            empty.append(
                createElement("span", "empty-state-mark", "ر"),
                createElement("h2", "task-empty-title", noTasks ? "هنوز وظیفه‌ای ثبت نشده" : "وظیفه‌ای در این فهرست نیست"),
                createElement("p", "task-empty-copy", noTasks
                    ? "یک قدم کوچک و روشن برای امروزت ثبت کن."
                    : "فیلترها را تغییر بده تا وظایف بیشتری ببینی."),
            );
            if (noTasks) {
                const action = createElement("button", "primary-button", "ساخت وظیفه");
                action.type = "button";
                action.disabled = state.projectsLoading || state.projectsError;
                action.addEventListener("click", () => openForm(null, action));
                empty.append(action);
            }
            taskListRegion.append(empty);
            return;
        }

        const list = createElement("ul", "task-list");
        for (const task of filtered) list.append(renderTask(task));
        taskListRegion.append(list);
    }

    function renderTask(task) {
        const item = createElement("li", `task-item${task.status === "completed" ? " is-completed" : ""}`);
        const card = createElement("article", "task-card surface-card");
        const main = createElement("div", "task-card-main");
        const headingRow = createElement("div", "task-card-heading");
        const title = createElement("h2", "task-card-title", task.title);
        headingRow.append(title);

        const statusLabel = task.status === "completed" ? "انجام‌شده" : "در انتظار";
        const statusBadge = createElement("span", `task-status-badge task-status-${task.status}`, statusLabel);
        headingRow.append(statusBadge);
        main.append(headingRow);

        if (typeof task.description === "string" && task.description.trim() !== "") {
            main.append(createElement("p", "task-card-description", task.description));
        }

        const metadata = createElement("div", "task-card-metadata");
        if (task.due_at) {
            const due = createElement("p", "task-card-meta");
            due.append(createElement("span", "task-meta-label", "موعد: "));
            const time = document.createElement("time");
            time.dateTime = task.due_at.replace(" ", "T");
            time.textContent = displayDate(task.due_at) ?? task.due_at;
            due.append(time);
            metadata.append(due);
        }

        if (task.project_id !== null) {
            const project = projectById(task.project_id);
            metadata.append(createElement("p", "task-card-meta", `پروژه: ${project?.name ?? "پروژه در دسترس نیست"}`));
        } else {
            metadata.append(createElement("p", "task-card-meta", "بدون پروژه"));
        }
        if (metadata.childElementCount > 0) main.append(metadata);

        const actions = createElement("div", "task-card-actions");
        const toggle = createElement("button", "text-button task-status-toggle", task.status === "completed" ? "بازگردانی" : "انجام شد");
        toggle.type = "button";
        toggle.disabled = state.busyTaskIds.has(task.id);
        toggle.setAttribute("aria-label", task.status === "completed"
            ? `علامت‌گذاری «${task.title}» به‌عنوان در انتظار`
            : `علامت‌گذاری «${task.title}» به‌عنوان انجام‌شده`);
        toggle.dataset.taskStatusId = String(task.id);
        toggle.addEventListener("click", () => toggleStatus(task));
        actions.append(toggle);

        const edit = createElement("button", "text-button", "ویرایش");
        edit.type = "button";
        edit.disabled = state.busyTaskIds.has(task.id) || state.projectsLoading || state.projectsError;
        edit.setAttribute("aria-label", `ویرایش وظیفه ${task.title}`);
        edit.addEventListener("click", () => openForm(task, edit));
        actions.append(edit);

        const remove = createElement("button", "text-button task-delete", "حذف");
        remove.type = "button";
        remove.dataset.deleteId = String(task.id);
        remove.disabled = state.busyTaskIds.has(task.id);
        remove.setAttribute("aria-label", `حذف وظیفه ${task.title}`);
        remove.addEventListener("click", () => {
            state.confirmDeleteId = task.id;
            renderList();
            taskListRegion.querySelector(`[data-delete-id="${task.id}"]`)?.focus({ preventScroll: true });
        });
        actions.append(remove);

        card.append(main, actions);
        if (state.confirmDeleteId === task.id) card.append(createDeleteConfirmation(task));
        item.append(card);
        return item;
    }

    function createDeleteConfirmation(task) {
        const box = createElement("div", "task-delete-confirmation");
        box.setAttribute("role", "group");
        box.setAttribute("aria-label", `تأیید حذف وظیفه ${task.title}`);
        box.append(createElement("p", "task-delete-copy", `وظیفه «${task.title}» حذف شود؟ این کار قابل بازگشت نیست.`));
        const actions = createElement("div", "task-confirm-actions");
        const cancel = createElement("button", "text-button", "انصراف");
        cancel.type = "button";
        cancel.addEventListener("click", () => {
            state.confirmDeleteId = null;
            renderList();
            taskListRegion.querySelector(`[data-delete-id="${task.id}"]`)?.focus({ preventScroll: true });
        });
        const confirm = createElement("button", "primary-button task-confirm-delete",
            state.busyTaskIds.has(task.id) ? "در حال حذف…" : "حذف وظیفه");
        confirm.type = "button";
        confirm.disabled = state.busyTaskIds.has(task.id);
        confirm.addEventListener("click", () => deleteTask(task));
        actions.append(cancel, confirm);
        box.append(actions);
        return box;
    }

    function renderProjectSelector(selected = "") {
        renderProjectOptions(projectField.input, selected, false);
    }

    function openForm(task, trigger) {
        if (state.projectsLoading || state.projectsError) {
            setNotice("برای مدیریت وظایف، ابتدا فهرست پروژه‌ها را بارگذاری کن.", "error");
            return;
        }
        state.activeTask = task;
        state.returnFocus = trigger;
        formTitle.textContent = task ? "ویرایش وظیفه" : "ساخت وظیفه";
        titleField.input.value = task?.title ?? "";
        descriptionField.input.value = task?.description ?? "";
        dueField.input.value = localDateInput(task?.due_at);
        renderProjectSelector(task?.project_id === null || task?.project_id === undefined ? "" : String(task.project_id));
        statusField.wrapper.hidden = !task;
        if (task) renderStatusSelector(task.status);
        submitButton.textContent = task ? "ذخیره تغییرات" : "ساخت وظیفه";
        setFieldErrors(form);
        clearFeedback(formFeedback);
        dialog.showModal();
        titleField.input.focus();
    }

    function renderStatusSelector(selected = "pending") {
        statusField.input.replaceChildren();
        for (const [value, label] of [["pending", "در انتظار"], ["completed", "انجام‌شده"]]) {
            const option = document.createElement("option");
            option.value = value;
            option.textContent = label;
            statusField.input.append(option);
        }
        statusField.input.value = selected;
    }

    function setBusyForm(busy) {
        state.busyForm = busy;
        for (const control of form.querySelectorAll("input, textarea, select, button")) control.disabled = busy;
        submitButton.textContent = busy
            ? (state.activeTask ? "در حال ذخیره…" : "در حال ساخت…")
            : (state.activeTask ? "ذخیره تغییرات" : "ساخت وظیفه");
    }

    function formReset() {
        form.reset();
        setFieldErrors(form);
        clearFeedback(formFeedback);
        state.activeTask = null;
    }

    async function getWriteToken() {
        return getCsrfToken() ?? await refreshCsrfToken();
    }

    function handleUnauthorized(error) {
        if (error instanceof ApiError && error.status === 401) {
            onAuthenticationExpired();
            return true;
        }
        return false;
    }

    async function explainError(error, operation) {
        if (handleUnauthorized(error)) return null;
        if (error instanceof ApiError) {
            if (error.status === 403) {
                try {
                    await refreshCsrfToken();
                    return `کد امنیتی تازه شد. برای ${operation} دوباره اقدام کن.`;
                } catch {
                    return "نشست تازه نشد. اتصال را بررسی و دوباره تلاش کن.";
                }
            }
            if (error.status === 400) return "درخواست کامل نشد؛ اطلاعات را بررسی کن.";
            if (error.status === 404) return "این وظیفه یا پروژه دیگر در دسترس نیست. فهرست را تازه کن.";
            if (error.status === 409) return "این تغییر با وضعیت فعلی وظیفه سازگار نیست.";
            if (error.status === 422) return "اطلاعات مشخص‌شده را اصلاح کن.";
            if (error.status === 500 || error.status === 503) return "سرور موقتاً در دسترس نیست. دوباره تلاش کن.";
        }
        return "ارتباط برقرار نشد. اتصال را بررسی و دوباره تلاش کن.";
    }

    async function loadTasks() {
        state.tasksLoading = true;
        state.tasksError = false;
        renderList();
        try {
            state.tasks = await tasksApi.listTasks();
            state.tasksError = false;
            state.confirmDeleteId = null;
        } catch (error) {
            if (handleUnauthorized(error)) return;
            state.tasksError = true;
        } finally {
            state.tasksLoading = false;
            renderList();
        }
    }

    async function loadProjects() {
        state.projectsLoading = true;
        state.projectsError = false;
        projectNotice.textContent = "در حال بارگذاری فهرست پروژه‌ها…";
        renderFilters();
        renderProjectSelector(projectField.input.value);
        try {
            state.projects = await projectsApi.listProjects();
            state.projectsError = false;
            renderProjectSelector(state.activeTask?.project_id == null ? "" : String(state.activeTask.project_id));
            projectNotice.replaceChildren();
        } catch (error) {
            if (handleUnauthorized(error)) return;
            state.projectsError = true;
            projectNotice.replaceChildren();
            const message = createElement("span", "", "فهرست پروژه‌ها بارگذاری نشد؛ تا زمان تلاش دوباره، ساخت و ویرایش وظایف غیرفعال است.");
            const retry = createElement("button", "text-button tasks-project-retry", "تلاش دوباره برای پروژه‌ها");
            retry.type = "button";
            retry.addEventListener("click", loadProjects);
            projectNotice.append(message, retry);
        } finally {
            state.projectsLoading = false;
            renderFilters();
            renderList();
        }
    }

    async function toggleStatus(task) {
        if (state.busyTaskIds.has(task.id)) return;
        state.busyTaskIds.add(task.id);
        renderList();
        try {
            const updated = await tasksApi.updateTask(task.id, {
                status: task.status === "completed" ? "pending" : "completed",
            }, await getWriteToken());
            state.tasks = state.tasks.map((current) => current.id === updated.id ? updated : current);
            setNotice(updated.status === "completed" ? "وظیفه انجام‌شده علامت خورد." : "وظیفه به فهرست در انتظار برگشت.", "success");
        } catch (error) {
            const message = await explainError(error, "تغییر وضعیت");
            if (message !== null) setNotice(message, "error", error instanceof ApiError && error.status === 404 ? loadTasks : null);
            if (error instanceof ApiError && error.status === 404) await loadTasks();
        } finally {
            state.busyTaskIds.delete(task.id);
            renderList();
            const nextFocus = taskListRegion.querySelector(`[data-task-status-id="${task.id}"]`)
                ?? statusButtons.get(state.statusFilter);
            nextFocus?.focus({ preventScroll: true });
        }
    }

    async function saveTask(event) {
        event.preventDefault();
        clearFeedback(formFeedback);
        setFieldErrors(form);

        const title = titleField.input.value.trim();
        const description = descriptionField.input.value.trim();
        const dueValue = dueField.input.value;
        const validation = {};
        if (title === "") validation.title = "عنوان وظیفه الزامی است.";
        if ([...title].length > 200) validation.title = "عنوان حداکثر ۲۰۰ نویسه باشد.";
        if ([...description].length > 10000) validation.description = "توضیحات حداکثر ۱۰۰۰۰ نویسه باشد.";

        const projectId = projectField.input.value === "" ? null : Number(projectField.input.value);
        if (projectId !== null && (!Number.isInteger(projectId) || projectId < 1 || !projectById(projectId))) {
            validation.project_id = "پروژه انتخاب‌شده معتبر نیست.";
        }
        if (dueValue !== "" && !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(dueValue)) {
            validation.due_at = "تاریخ و ساعت موعد را بررسی کن.";
        }

        if (Object.keys(validation).length > 0) {
            setFieldErrors(form, validation);
            showFeedback(formFeedback, "اطلاعات مشخص‌شده را اصلاح کن.", "error");
            setTimeout(() => form.querySelector('[aria-invalid="true"]')?.focus(), 0);
            return;
        }

        const data = {
            title,
            description,
            due_at: dueValue === "" ? null : dueValue.replace("T", " "),
            project_id: projectId,
        };
        if (state.activeTask) data.status = statusField.input.value;

        setBusyForm(true);
        try {
            const task = state.activeTask
                ? await tasksApi.updateTask(state.activeTask.id, data, await getWriteToken())
                : await tasksApi.createTask(data, await getWriteToken());
            if (state.activeTask) {
                state.tasks = state.tasks.map((current) => current.id === task.id ? task : current);
            } else {
                state.tasks = [task, ...state.tasks];
            }
            state.confirmDeleteId = null;
            state.returnFocus = state.activeTask ? headingCopy.lastElementChild : createButton;
            clearFeedback(feedback);
            setNotice(state.activeTask ? "تغییرات وظیفه ذخیره شد." : "وظیفه ساخته شد.", "success");
            dialog.close();
            formReset();
            renderList();
        } catch (error) {
            const message = await explainError(error, "ذخیره وظیفه");
            if (message !== null) {
                const serverErrors = error instanceof ApiError && error.status === 422
                    ? translateValidationErrors(error.errors)
                    : {};
                const hasFieldErrors = Object.keys(serverErrors).length > 0;
                if (hasFieldErrors) {
                    setFieldErrors(form, serverErrors);
                    setTimeout(() => form.querySelector('[aria-invalid="true"]')?.focus(), 0);
                }
                showFeedback(formFeedback, hasFieldErrors ? "اطلاعات مشخص‌شده را اصلاح کن." : message, "error");
            }
        } finally {
            setBusyForm(false);
        }
    }

    async function deleteTask(task) {
        if (state.busyTaskIds.has(task.id)) return;
        state.busyTaskIds.add(task.id);
        renderList();
        try {
            await tasksApi.deleteTask(task.id, await getWriteToken());
            state.tasks = state.tasks.filter((current) => current.id !== task.id);
            state.confirmDeleteId = null;
            setNotice("وظیفه حذف شد.", "success");
        } catch (error) {
            const message = await explainError(error, "حذف وظیفه");
            if (message !== null) setNotice(message, "error", error instanceof ApiError && error.status === 404 ? loadTasks : null);
            if (error instanceof ApiError && error.status === 404) await loadTasks();
        } finally {
            state.busyTaskIds.delete(task.id);
            renderList();
            if (state.tasks.length === 0) {
                taskListRegion.querySelector(".task-empty-state button")?.focus({ preventScroll: true });
            } else {
                const nextFocus = taskListRegion.querySelector(`[data-delete-id="${task.id}"]`)
                    ?? headingCopy.lastElementChild;
                nextFocus.focus({ preventScroll: true });
            }
        }
    }

    for (const [value, button] of statusButtons) {
        button.addEventListener("click", () => {
            state.statusFilter = value;
            renderList();
        });
    }
    projectFilter.addEventListener("change", () => {
        state.projectFilter = projectFilter.value || "all";
        renderList();
    });
    createButton.addEventListener("click", () => openForm(null, createButton));
    form.addEventListener("input", () => setFieldErrors(form));
    form.addEventListener("change", () => setFieldErrors(form));
    form.addEventListener("submit", saveTask);
    dialog.addEventListener("close", () => {
        formReset();
        const target = state.returnFocus;
        state.returnFocus = null;
        if (target?.isConnected) target.focus({ preventScroll: true });
        else headingCopy.lastElementChild.focus({ preventScroll: true });
    });
    dialog.addEventListener("cancel", (event) => {
        if (state.busyForm) event.preventDefault();
    });

    function createField({ name, id, label, type, required = false }) {
        const wrapper = createElement("div", "form-field task-form-field");
        const labelNode = createElement("label", "", label);
        labelNode.htmlFor = id;
        let input;
        if (type === "textarea") input = document.createElement("textarea");
        else if (type === "select") input = document.createElement("select");
        else input = document.createElement("input");
        input.id = id;
        input.name = name;
        input.required = required;
        input.setAttribute("aria-describedby", `${name}-error`);
        if (type === "text") {
            input.type = "text";
            input.autocomplete = "off";
        } else if (type === "textarea") {
            input.rows = 4;
            input.placeholder = "توضیحی کوتاه و کاربردی بنویس…";
        }
        if (name === "status") renderStatusSelectorInto(input);
        const error = createElement("span", "field-error");
        error.id = `${name}-error`;
        wrapper.append(labelNode, input, error);
        return { wrapper, input };
    }

    function renderStatusSelectorInto(select) {
        for (const [value, label] of [["pending", "در انتظار"], ["completed", "انجام‌شده"]]) {
            const option = document.createElement("option");
            option.value = value;
            option.textContent = label;
            select.append(option);
        }
    }

    renderList();
    loadTasks();
    loadProjects();

    return page;
}
