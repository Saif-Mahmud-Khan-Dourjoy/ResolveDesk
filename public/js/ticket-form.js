/*
 * V2: dependent dropdown on the Create Ticket page (administrators only).
 *
 * When the admin picks a client organization, we ask the server which projects
 * that client uses:
 *   GET /tickets/project-options?client_id=5  ->  {"projects":[{"id":1,"label":"HRMS - ..."}]}
 * and rebuild the Project <select>. The server repeats every check when the
 * ticket is saved, so this script is only a convenience, never a security control.
 */
(function () {
    'use strict';

    const clientSelect = document.getElementById('client_id');
    const projectSelect = document.getElementById('project_id');

    if (!clientSelect || !projectSelect || !clientSelect.dataset.projectOptionsUrl) {
        return; // not the admin create form
    }

    async function reloadProjects() {
        const url = new URL(clientSelect.dataset.projectOptionsUrl, window.location.origin);
        if (clientSelect.value) {
            url.searchParams.set('client_id', clientSelect.value);
        }

        const previouslySelected = projectSelect.value;
        projectSelect.disabled = true; // loading indicator: the list cannot be used while it reloads

        try {
            const response = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const data = await response.json();

            // Rebuild the options with textContent (safe: no HTML is interpreted).
            projectSelect.innerHTML = '';
            const globalOption = document.createElement('option');
            globalOption.value = '';
            globalOption.textContent = 'Global - not about a specific project';
            projectSelect.appendChild(globalOption);

            data.projects.forEach(function (project) {
                const option = document.createElement('option');
                option.value = project.id;
                option.textContent = project.label;
                if (String(project.id) === previouslySelected) {
                    option.selected = true;
                }
                projectSelect.appendChild(option);
            });
        } catch (error) {
            console.warn('Could not load projects for the selected client.', error);
        } finally {
            projectSelect.disabled = false;
        }
    }

    clientSelect.addEventListener('change', reloadProjects);

    // After a validation error the page reloads with a client already selected.
    if (clientSelect.value) {
        reloadProjects();
    }
})();
