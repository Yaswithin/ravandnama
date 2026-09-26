import { ApiError, apiRequest } from "./client.js";

function noteFrom(response) {
    const note = response?.data?.note;

    if (!note || typeof note !== "object") {
        throw new ApiError("The server returned an invalid note response.", 500);
    }

    return note;
}

export async function listNotes() {
    const response = await apiRequest("/api/notes");

    if (!Array.isArray(response?.data?.notes)) {
        throw new ApiError("The server returned an invalid notes response.", 500);
    }

    return response.data.notes;
}

export async function getNote(id) {
    return noteFrom(await apiRequest(`/api/notes/${encodeURIComponent(id)}`));
}

export async function createNote(data, csrfToken) {
    return noteFrom(await apiRequest("/api/notes", { method: "POST", body: data, csrfToken }));
}

export async function updateNote(id, data, csrfToken) {
    return noteFrom(await apiRequest(`/api/notes/${encodeURIComponent(id)}`, { method: "PUT", body: data, csrfToken }));
}

export function deleteNote(id, csrfToken) {
    return apiRequest(`/api/notes/${encodeURIComponent(id)}`, { method: "DELETE", csrfToken });
}
