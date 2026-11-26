<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * In-app notifications (Laravel database notifications).
 *
 * Every query starts from $request->user()->notifications(), so a user can
 * only ever see or change THEIR OWN notification rows.
 */
class NotificationController extends Controller
{
    /** How many notifications the navbar dropdown shows. */
    private const DROPDOWN_LIMIT = 5;

    /** Safety overlap for the ?since= filter (see feed()). */
    private const SINCE_OVERLAP_SECONDS = 10;

    /** Notification history page with read/unread filter. */
    public function index(Request $request)
    {
        $query = $request->user()->notifications(); // newest first by default

        if ($request->input('filter') === 'unread') {
            $query->whereNull('read_at');
        } elseif ($request->input('filter') === 'read') {
            $query->whereNotNull('read_at');
        }

        $notifications = $query->paginate(15)->withQueryString();

        return view('notifications.index', ['notifications' => $notifications]);
    }

    /**
     * JSON endpoint polled every few seconds by public/js/notifications.js.
     *
     * Optional ?since=<ISO time>: also return unread notifications created at
     * or after that time, so the browser can show toasts for every new one,
     * even if more than five arrived between two polls.
     */
    public function feed(Request $request)
    {
        $request->validate([
            'since' => ['nullable', 'date'], // an invalid value returns 422 instead of crashing
        ]);

        $user = $request->user();

        // Taken BEFORE the queries: a notification inserted while this request runs
        // is then still "new" on the next poll (duplicates are filtered by ID in JS).
        $serverTime = now()->toIso8601String();

        $latest = $user->notifications()->take(self::DROPDOWN_LIMIT)->get();

        $newNotifications = collect();
        if ($request->filled('since')) {
            // Look 10 seconds further back than asked: a notification created inside a
            // transaction that had not committed yet during the previous poll is still found.
            // notifications.js ignores IDs it has already shown, so the overlap causes no duplicate toasts.
            $since = Carbon::parse($request->input('since'))->subSeconds(self::SINCE_OVERLAP_SECONDS);
            $newNotifications = $user->unreadNotifications()
                ->where('created_at', '>=', $since)
                ->take(20)
                ->get();
        }

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'notifications' => $latest->map(fn ($notification) => $this->formatNotification($notification)),
            'new_notifications' => $newNotifications->map(fn ($notification) => $this->formatNotification($notification)),
            // The browser sends this back as ?since= on the next poll.
            'server_time' => $serverTime,
        ]);
    }

    /**
     * Mark one notification as read.
     * - AJAX (fetch) call: returns JSON.
     * - Normal form on the history page: redirects to the ticket or back.
     */
    public function markAsRead(Request $request, string $id)
    {
        // findOrFail() on the user's own notifications => 404 for someone else's id.
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ]);
        }

        if ($request->boolean('open_ticket') && isset($notification->data['ticket_id'])) {
            return redirect()->route('tickets.show', $notification->data['ticket_id']);
        }

        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'unread_count' => 0]);
        }

        return back()->with('success', 'All notifications marked as read.');
    }

    /** The small JSON shape used by notifications.js. */
    private function formatNotification(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? '',
            'ticket_number' => $data['ticket_number'] ?? '',
            'event_type' => $data['event_type'] ?? '',
            'ticket_url' => isset($data['ticket_id']) ? route('tickets.show', $data['ticket_id']) : null,
            'is_read' => $notification->read_at !== null,
            'time_ago' => $notification->created_at->diffForHumans(),
        ];
    }
}
