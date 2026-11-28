/*
 * V2 real-time notifications: Laravel Echo + Laravel Reverb (WebSockets).
 *
 * Built by Vite into public/build (npm run build). This is the ONLY bundled
 * script; the rest of the UI keeps using plain files in public/js.
 *
 * What it does:
 *   1. Connects to the Reverb WebSocket server (keys from .env VITE_REVERB_*).
 *   2. Subscribes to the PRIVATE channel "users.{id}". Echo first calls
 *      POST /broadcasting/auth (with the session cookie + CSRF token);
 *      routes/channels.php allows only the matching user.
 *   3. Tells public/js/notifications.js what happens, using browser events:
 *        resolvedesk:realtime-status        {healthy: true|false}
 *        resolvedesk:realtime-notification  {notification_id: "..."}
 *
 * If Reverb is not running, Echo keeps retrying in the background and
 * notifications.js keeps polling, so the user still gets every notification.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const userIdMeta = document.querySelector('meta[name="user-id"]');

function announce(name, detail) {
    window.dispatchEvent(new CustomEvent(name, { detail: detail }));
}

if (userIdMeta && import.meta.env.VITE_REVERB_APP_KEY) {
    window.Pusher = Pusher;

    const echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        // Echo reads the CSRF token from <meta name="csrf-token"> for /broadcasting/auth.
    });

    let connected = false;
    let subscribed = false;

    function reportHealth() {
        announce('resolvedesk:realtime-status', { healthy: connected && subscribed });
    }

    // Pusher-protocol connection states: connecting, connected, unavailable, failed, disconnected.
    echo.connector.pusher.connection.bind('state_change', function (states) {
        connected = states.current === 'connected';
        if (!connected) {
            subscribed = false; // the channel is re-subscribed automatically after reconnecting
        }
        reportHealth();
    });

    echo.private('users.' + userIdMeta.content)
        .subscribed(function () {
            subscribed = true;
            reportHealth();
        })
        .error(function (error) {
            // e.g. 403 from /broadcasting/auth: stay on polling.
            subscribed = false;
            reportHealth();
            console.warn('Real-time channel subscription failed; using polling.', error);
        })
        .listen('.notification.created', function (payload) {
            announce('resolvedesk:realtime-notification', payload);
        });
}
