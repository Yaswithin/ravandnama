import { ApiError, apiRequest } from "./client.js";

function projectFrom(response) {
    const project = response?.data?.project;

    if (!project || typeof project !== "object") {
        throw new ApiError("The server returned an invalid project response.", 500);
    }

    return project;
}

export async function listProjects() {
    const response = await apiRequest("/api/projects");

    if (!Array.isArray(response?.data?.projects)) {
        throw new ApiError("The server returned an invalid projects response.", 500);
    }

    return response.data.projects;
}

export async function getProject(id) {
    return projectFrom(await apiRequest(`/api/projects/${encodeURIComponent(id)}`));
}

export async function createProject(data, csrfToken) {
    return projectFrom(await apiRequest("/api/projects", {
        method: "POST",
        body: data,
        csrfToken,
    }));
}

export async function updateProject(id, data, csrfToken) {
    return projectFrom(await apiRequest(`/api/projects/${encodeURIComponent(id)}`, {
        method: "PUT",
        body: data,
        csrfToken,
    }));
}

export function deleteProject(id, csrfToken) {
    return apiRequest(`/api/projects/${encodeURIComponent(id)}`, {
        method: "DELETE",
        csrfToken,
    });
}
