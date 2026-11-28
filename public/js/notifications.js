/*
 * ResolveDesk notifications: real-time WebSocket signal + AJAX polling fallback.
 *
 * The DATABASE is the source of truth. Both paths end in the same call:
 *   GET /notifications/feed  ->  unread count, latest 5, new items since last call
 *
 * Path 1 - real time (V2): resources/js/realtime.js (Laravel Echo + Reverb) listens
 *   on the private channel "users.{id}". When the server broadcasts
 *   ".notification.created" it fires the browser event "resolvedesk:realtime-notification"
 *   and we fetch the feed immediately (instant toast).
 *
 * Path 2 - fallback polling (V1 behaviour): while the WebSocket is NOT healthy
 *   (Reverb stopped, network blocked, assets not built) we poll every
 *   POLL_INTERVAL_MS. While the WebSocket IS healthy, continuous polling stops.
 *
 * After every (re)connection we fetch once, so events missed while disconnected
 * are not lost. Toasts are de-duplicated by notification ID (shownToastIds), so a
 * broadcast + a poll for the same notification never show two toasts.
 */
(function () {
    'use strict';

    // ---- Configuration (change the polling interval here) -------------------
    const POLL_INTERVAL_MS = 5000;          // 5 seconds while the tab is visible
    const HIDDEN_POLL_INTERVAL_MS = 30000;  // slower polling while the tab is hidden

    const bell = document.getElementById('notificationBell');
    if (!bell) {
        return; // Not on a logged-in page.
    }

    const feedUrl = bell.dataset.feedUrl;
    const readUrlTemplate = bell.dataset.readUrl;   // contains "__ID__"
    const readAllUrl = bell.dataset.readAllUrl;
    const loginUrl = bell.dataset.loginUrl;
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    const countBadge = document.getElementById('notificationCount');
    const listContainer = document.getElementById('notificationList');
    const markAllButton = document.getElementById('markAllReadButton');
    const toastContainer = document.getElementById('toast-container');

    let lastServerTime = null;      // sent back as ?since= so the server returns only new items
    let isFirstLoad = true;         // historical notifications are not shown as toasts
    let timerId = null;             // the one and only pending setTimeout
    let isRequestRunning = false;   // prevents two requests at the same time
    let isStopped = false;          // set when the session has expired
    let fetchAgain = false;         // an update arrived while a request was running
    let realtimeHealthy = false;    // true while the WebSocket channel is connected + subscribed
    const shownToastIds = new Set();

    // =========================================================================
    // Polling loop: recursive setTimeout (the next poll is scheduled only
    // AFTER the current request has finished, so requests never overlap).
    // =========================================================================

    async function poll() {
        if (isStopped) {
            return;
        }
        if (isRequestRunning) {
            fetchAgain = true; // run once more when the current request finishes
            return;
        }
        isRequestRunning = true;

        try {
            let url = feedUrl;
            if (lastServerTime) {
                url += '?since=' + encodeURIComponent(lastServerTime);
            }

            const response = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (response.status === 401 || response.status === 419) {
                handleExpiredSession();
                return;
            }

            if (!response.ok) {
                throw new Error('Server responded with ' + response.status);
            }

            const data = await response.json();

            updateBadge(data.unread_count);
            renderDropdown(data.notifications);

            if (!isFirstLoad) {
                showToasts(data.new_notifications);
            } else {
                // Remember the existing IDs so they never pop up as "new".
                data.notifications.forEach(function (item) { shownToastIds.add(item.id); });
            }

            isFirstLoad = false;
            lastServerTime = data.server_time;
        } catch (error) {
            // Network problem or server error: keep the page working and try again later.
            console.warn('Notification polling failed:', error.message);
        } finally {
            isRequestRunning = false;
            if (fetchAgain) {
                fetchAgain = false;
                poll();
            } else {
                scheduleNextPoll();
            }
        }
    }

    function scheduleNextPoll() {
        clearTimeout(timerId); // guarantees only one polling loop exists
        if (isStopped || realtimeHealthy) {
            return; // WebSocket is healthy: no continuous polling needed
        }
        const delay = document.hidden ? HIDDEN_POLL_INTERVAL_MS : POLL_INTERVAL_MS;
        timerId = setTimeout(poll, delay);
    }

    // ---- Real-time bridge (events fired by resources/js/realtime.js) ---------

    // Connection state changed: connected+subscribed => stop polling; otherwise fall back.
    window.addEventListener('resolvedesk:realtime-status', function (event) {
        const wasHealthy = realtimeHealthy;
        realtimeHealthy = event.detail.healthy === true;

        if (realtimeHealthy && !wasHealthy) {
            poll(); // (re)connected: synchronize anything missed while disconnected
        } else if (!realtimeHealthy && wasHealthy) {
            scheduleNextPoll(); // WebSocket lost: resume fallback polling
        }
    });

    // A new notification exists: fetch the authorized data now (the broadcast only carries an id).
    window.addEventListener('resolvedesk:realtime-notification', function () {
        poll();
    });

    // When the user comes back to the tab, fetch immediately.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && !isStopped) {
            clearTimeout(timerId);
            poll();
        }
    });

    function handleExpiredSession() {
        isStopped = true;
        clearTimeout(timerId);
        listContainer.textContent = '';
        const message = document.createElement('div');
        message.className = 'p-3 small text-muted';
        message.textContent = 'Your session has expired. ';
        const link = document.createElement('a');
        link.href = loginUrl;
        link.textContent = 'Sign in again';
        message.appendChild(link);
        listContainer.appendChild(message);
    }

    // =========================================================================
    // Rendering. User-generated text (ticket subjects, comments) is always
    // inserted with textContent, never innerHTML, so it cannot run as HTML/JS (XSS).
    // =========================================================================

    function updateBadge(unreadCount) {
        if (unreadCount > 0) {
            countBadge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
            countBadge.classList.remove('d-none');
        } else {
            countBadge.classList.add('d-none');
        }
    }

    function renderDropdown(notifications) {
        listContainer.textContent = '';

        if (notifications.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'p-3 small text-muted text-center';
            empty.textContent = 'You have no notifications yet.';
            listContainer.appendChild(empty);
            return;
        }

        notifications.forEach(function (item) {
            const row = document.createElement('a');
            row.className = 'notification-item' + (item.is_read ? '' : ' unread');
            row.href = item.ticket_url || '#';

            const title = document.createElement('div');
            title.className = 'notification-title';
            title.textContent = item.ticket_number + ' · ' + item.title;

            const message = document.createElement('div');
            message.className = 'notification-message';
            message.textContent = item.message;

            const time = document.createElement('div');
            time.className = 'notification-time';
            time.textContent = item.time_ago;

            row.appendChild(title);
            row.appendChild(message);
            row.appendChild(time);

            row.addEventListener('click', function (event) {
                event.preventDefault();
                openNotification(item);
            });

            listContainer.appendChild(row);
        });
    }

    function showToasts(newNotifications) {
        newNotifications.forEach(function (item) {
            if (shownToastIds.has(item.id)) {
                return; // already shown once
            }
            shownToastIds.add(item.id);

            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');

            const header = document.createElement('div');
            header.className = 'toast-header';
            const icon = document.createElement('i');
            icon.className = 'bi bi-bell-fill text-primary me-2';
            const strong = document.createElement('strong');
            strong.className = 'me-auto';
            strong.textContent = item.ticket_number + ' · ' + item.title;
            const closeButton = document.createElement('button');
            closeButton.type = 'button';
            closeButton.className = 'btn-close';
            closeButton.setAttribute('data-bs-dismiss', 'toast');
            closeButton.setAttribute('aria-label', 'Close');
            header.appendChild(icon);
            header.appendChild(strong);
            header.appendChild(closeButton);

            const body = document.createElement('div');
            body.className = 'toast-body';
            body.style.cursor = 'pointer';
            body.textContent = item.message;
            body.addEventListener('click', function () { openNotification(item); });

            toast.appendChild(header);
            toast.appendChild(body);
            toastContainer.appendChild(toast);

            const bootstrapToast = new bootstrap.Toast(toast, { delay: 8000 });
            bootstrapToast.show();
            toast.addEventListener('hidden.bs.toast', function () { toast.remove(); });
        });
    }

    // =========================================================================
    // Data-changing requests (POST) include the CSRF token.
    // =========================================================================

    async function postJson(url) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (response.status === 401 || response.status === 419) {
            handleExpiredSession();
            throw new Error('Session expired');
        }
        if (!response.ok) {
            throw new Error('Server responded with ' + response.status);
        }
        return response.json();
    }

    /** Mark as read, then open the ticket. The ticket page checks permission again (403 if revoked). */
    async function openNotification(item) {
        try {
            if (!item.is_read) {
                await postJson(readUrlTemplate.replace('__ID__', encodeURIComponent(item.id)));
            }
        } catch (error) {
            console.warn('Could not mark notification as read:', error.message);
        }

        if (item.ticket_url) {
            window.location.href = item.ticket_url;
        } else {
            poll();
        }
    }

    markAllButton.addEventListener('click', async function () {
        try {
            const data = await postJson(readAllUrl);
            updateBadge(data.unread_count);
            listContainer.querySelectorAll('.notification-item.unread').forEach(function (row) {
                row.classList.remove('unread');
            });
        } catch (error) {
            console.warn('Could not mark all notifications as read:', error.message);
        }
    });

    // First request immediately on page load.
    poll();
})();
