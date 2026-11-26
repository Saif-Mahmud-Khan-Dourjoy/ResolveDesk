/*
 * Small shared behaviour for all pages.
 *
 * Confirmation dialogs: any <form data-confirm="Are you sure?"> asks the user
 * before submitting. If the user clicks "Cancel", the form is not sent.
 */
document.addEventListener('submit', function (event) {
    const form = event.target;
    const message = form.getAttribute('data-confirm');

    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
