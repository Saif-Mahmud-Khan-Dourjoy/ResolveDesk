<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TicketCommentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
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
    Route::resource('tickets', TicketController::class)->except(['destroy']);

    Route::post('/tickets/{ticket}/start', [TicketController::class, 'start'])->name('tickets.start');
    Route::post('/tickets/{ticket}/resolve', [TicketController::class, 'resolve'])->name('tickets.resolve');
    Route::post('/tickets/{ticket}/reopen', [TicketController::class, 'reopen'])->name('tickets.reopen');
    Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');
    Route::post('/tickets/{ticket}/comments', [TicketCommentController::class, 'store'])->name('tickets.comments.store');

    // Notifications: HTML history page + JSON endpoints used by public/js/notifications.js
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
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
    });
});
