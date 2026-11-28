<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Controllers\TicketCommentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ResolveDesk routes
|--------------------------------------------------------------------------
| Every data-changing action uses POST/PUT/PATCH/DELETE (never GET), so it
| is protected by Laravel's CSRF check.
|
| Run `php artisan route:list` to see every route with its name and middleware.
*/

Route::redirect('/', '/dashboard');

// V2 readiness check for load balancers / monitoring: "can this instance serve requests?"
// (/up, configured in bootstrap/app.php, is the simpler liveness check.)
// It answers only ok/unavailable - no versions, hostnames or error details are exposed.
Route::get('/health/ready', function () {
    try {
        DB::select('SELECT 1');
    } catch (Throwable $exception) {
        report($exception);

        return response()->json(['status' => 'unavailable'], 503);
    }

    return response()->json(['status' => 'ok']);
})->middleware('throttle:60,1')->name('health.ready');

// ---- Guests only (not logged in) ---------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

// ---- Logged-in AND active users ----------------------------------------------
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Tickets (all roles). Ticket-level permissions are checked by App\Policies\TicketPolicy.
    // There is no "destroy" route: tickets are never deleted through the UI.
    // Registered BEFORE the resource so "project-options" is not treated as a {ticket} id.
    Route::get('/tickets/project-options', [TicketController::class, 'projectOptions'])->name('tickets.project-options');

    Route::resource('tickets', TicketController::class)->except(['destroy', 'store']);
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('throttle:ticket-create')->name('tickets.store');
    Route::get('/tickets/{ticket}/attachments/{attachment}', [TicketAttachmentController::class, 'download'])->name('tickets.attachments.download');

    Route::post('/tickets/{ticket}/start', [TicketController::class, 'start'])->name('tickets.start');
    Route::post('/tickets/{ticket}/resolve', [TicketController::class, 'resolve'])->name('tickets.resolve');
    Route::post('/tickets/{ticket}/reopen', [TicketController::class, 'reopen'])->name('tickets.reopen');
    Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');
    Route::post('/tickets/{ticket}/comments', [TicketCommentController::class, 'store'])->middleware('throttle:comments')->name('tickets.comments.store');

    // Notifications: HTML history page + JSON endpoints used by public/js/notifications.js
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/feed', [NotificationController::class, 'feed'])->middleware('throttle:notifications')->name('notifications.feed');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');

    // ---- Administrators only -------------------------------------------------
    Route::middleware('role:admin')->group(function () {
        Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('tickets.assign');
        Route::post('/tickets/{ticket}/auto-assign', [TicketController::class, 'autoAssign'])->name('tickets.auto-assign');

        // Users are never deleted (they are referenced by tickets); they are deactivated instead.
        Route::resource('users', UserController::class)->except(['destroy']);
        Route::patch('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');

        Route::resource('categories', CategoryController::class)->except(['show']);

        // V2: projects and client organizations (deactivated, never deleted).
        Route::resource('projects', ProjectController::class)->except(['destroy']);
        Route::put('/projects/{project}/clients', [ProjectController::class, 'updateClients'])->name('projects.clients.update');
        Route::put('/projects/{project}/agents', [ProjectController::class, 'updateAgents'])->name('projects.agents.update');
        Route::resource('clients', ClientController::class)->except(['destroy']);

        // V2: reports + CSV export (export is rate limited: it can be an expensive query).
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->middleware('throttle:exports')->name('reports.export');
    });
});
