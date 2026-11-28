/*
 * Small shared behaviour for all pages.
 *
 * 1. Confirmation dialogs: any <form data-confirm="Are you sure?"> asks the user
 *    before submitting. If the user clicks "Cancel", the form is not sent.
 *
 * 2. Double-submit prevention (UI only): any <form data-disable-on-submit> disables
 *    its submit buttons after the first submit and shows a spinner.
 *    NOTE: this is only a convenience. The real protection against duplicate
 *    tickets is on the server (request_token + UNIQUE index, see TicketService).
 */
document.addEventListener('submit', function (event) {
    const form = event.target;
    const message = form.getAttribute('data-confirm');

    if (message && !window.confirm(message)) {
        event.preventDefault();
        return;
    }

    if (form.hasAttribute('data-disable-on-submit')) {
        if (form.dataset.submitting === '1') {
            event.preventDefault(); // second click while the first request is still running
            return;
        }
        form.dataset.submitting = '1';

        form.querySelectorAll('button[type="submit"]').forEach(function (button) {
            button.disabled = true;
            const spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm ms-2';
            spinner.setAttribute('aria-hidden', 'true');
            button.appendChild(spinner);
        });
    }
});

// When the user comes back with the browser "Back" button, re-enable the forms.
window.addEventListener('pageshow', function () {
    document.querySelectorAll('form[data-disable-on-submit]').forEach(function (form) {
        form.dataset.submitting = '';
        form.querySelectorAll('button[type="submit"]').forEach(function (button) {
            button.disabled = false;
            button.querySelectorAll('.spinner-border').forEach(function (spinner) { spinner.remove(); });
        });
    });
});
