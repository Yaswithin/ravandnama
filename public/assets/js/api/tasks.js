import { ApiError, apiRequest } from "./client.js";

function taskFrom(response) {
    const task = response?.data?.task;

    if (!task || typeof task !== "object") {
        throw new ApiError("The server returned an invalid task response.", 500);
    }

    return task;
}

export async function listTasks() {
    const response = await apiRequest("/api/tasks");

    if (!Array.isArray(response?.data?.tasks)) {
        throw new ApiError("The server returned an invalid tasks response.", 500);
    }

    return response.data.tasks;
}

export async function getTask(id) {
    return taskFrom(await apiRequest(`/api/tasks/${encodeURIComponent(id)}`));
}

export async function createTask(data, csrfToken) {
    return taskFrom(await apiRequest("/api/tasks", {
        method: "POST",
        body: data,
        csrfToken,
    }));
}

export async function updateTask(id, data, csrfToken) {
    return taskFrom(await apiRequest(`/api/tasks/${encodeURIComponent(id)}`, {
        method: "PUT",
        body: data,
        csrfToken,
    }));
}

export function deleteTask(id, csrfToken) {
    return apiRequest(`/api/tasks/${encodeURIComponent(id)}`, {
        method: "DELETE",
        csrfToken,
    });
}
