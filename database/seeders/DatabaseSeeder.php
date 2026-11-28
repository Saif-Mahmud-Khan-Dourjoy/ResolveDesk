<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Client;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketComment;
use App\Models\User;
use App\Notifications\TicketNotification;
use App\Services\TicketService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Realistic demo data that follows the real business rules.
 *
 * Run:  php artisan db:seed            (on an empty database)
 *       php artisan migrate:fresh --seed  (DESTROYS all data first - local only)
 *
 * Every ticket is built with its full history (activities, comments and
 * in-app notifications) using timestamps in the past, so dashboards, SLA
 * deadlines and overdue indicators look realistic.
 *
 * No email jobs are dispatched and no emails are sent while seeding.
 *
 * LOCAL DEMO CREDENTIALS ONLY - every account uses the password "password".
 */
class DatabaseSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'password';

    private User $admin;

    /** @var User[] */
    private array $agents = [];

    /** @var User[] */
    private array $customers = [];

    /** @var array<string, Category> */
    private array $categories = [];

    public function run(): void
    {
        $this->createUsers();
        $this->createCategories();
        $this->createProjectsAndClients();
        $this->createTickets();

        // The database cache survives re-seeding, so clear anything stale.
        Cache::forget(TicketService::DASHBOARD_CACHE_KEY);
        Category::clearActiveCache();
    }

    // =========================================================================

    private function createUsers(): void
    {
        $this->admin = $this->makeUser('Nadia Rahman', 'admin@resolvedesk.test', User::ROLE_ADMIN);

        $this->agents = [
            $this->makeUser('Sarah Ahmed', 'sarah.agent@resolvedesk.test', User::ROLE_AGENT),
            $this->makeUser('David Chen', 'david.agent@resolvedesk.test', User::ROLE_AGENT),
            $this->makeUser('Fatima Khan', 'fatima.agent@resolvedesk.test', User::ROLE_AGENT),
            $this->makeUser('Michael Brown', 'michael.agent@resolvedesk.test', User::ROLE_AGENT),
        ];

        $this->customers = [
            $this->makeUser('John Carter', 'john.customer@example.com', User::ROLE_CUSTOMER),
            $this->makeUser('Emily Watson', 'emily.customer@example.com', User::ROLE_CUSTOMER),
            $this->makeUser('Rahim Uddin', 'rahim.customer@example.com', User::ROLE_CUSTOMER),
            $this->makeUser('Olivia Martin', 'olivia.customer@example.com', User::ROLE_CUSTOMER),
            // Inactive account: demonstrates that deactivated users cannot sign in.
            $this->makeUser('Tanvir Hasan', 'tanvir.customer@example.com', User::ROLE_CUSTOMER, false),
        ];
    }

    /**
     * V2: projects, clients and associations (V2DemoDataSeeder), then link the demo
     * customers to their organizations. Olivia and Tanvir stay standalone customers.
     */
    private function createProjectsAndClients(): void
    {
        $this->call(V2DemoDataSeeder::class);

        $acme = Client::where('code', 'ACME')->firstOrFail();
        $globex = Client::where('code', 'GLOBEX')->firstOrFail();

        foreach ([0 => $acme, 1 => $acme, 2 => $globex] as $customerIndex => $client) {
            $this->customers[$customerIndex]->client_id = $client->id;
            $this->customers[$customerIndex]->save();
        }
    }

    private function makeUser(string $name, string $email, string $role, bool $isActive = true): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => self::DEMO_PASSWORD, // hashed by the User model's 'hashed' cast
            'role' => $role,
            'is_active' => $isActive,
        ]);
    }

    private function createCategories(): void
    {
        $definitions = [
            ['Technical Issue', 'Errors, crashes, performance problems and integration failures.', true],
            ['Software Bug', 'Incorrect behaviour in one of our products.', true],
            ['Account Access', 'Login problems, password resets and permission issues.', true],
            ['Billing', 'Invoices, payments, refunds and subscription changes.', true],
            ['Feature Request', 'Suggestions for new features or improvements.', true],
            ['General Inquiry', 'Questions that do not fit another category.', true],
            // Inactive: hidden from new ticket forms, but old tickets keep it.
            ['Legacy Desktop App', 'The discontinued desktop client (no longer supported).', false],
        ];

        foreach ($definitions as [$name, $description, $isActive]) {
            $this->categories[$name] = Category::create([
                'name' => $name,
                'description' => $description,
                'is_active' => $isActive,
            ]);
        }
    }

    // =========================================================================

    private function createTickets(): void
    {
        // [creator, category, subject, description, priority, final status, agent index or null, hours ago, reopened?, project code?]
        // V2: no project code = global ticket. The client comes from the creator (customers only).
        // creator: 'c0'..'c4' customer, 'a1' agent index 1, 'admin'
        $scenarios = [
            // ---- Open (unassigned) ----
            ['c0', 'Billing', 'Invoice #INV-2291 shows the wrong VAT amount', 'The November invoice charges 20% VAT but our company is VAT-exempt. Please correct and resend the invoice.', 'medium', 'open', null, 2, false, 'PAYROLL'],
            ['c1', 'Account Access', 'Cannot reset my password', 'The password reset email never arrives. I have checked the spam folder as well.', 'high', 'open', null, 1, false],
            ['c2', 'Feature Request', 'Export reports to Excel', 'It would be very helpful to export the monthly usage report as an .xlsx file.', 'low', 'open', null, 20, false, 'ERP'],
            ['c3', 'Technical Issue', 'API returns 504 for bulk uploads', 'Uploading more than 500 records through the API times out with HTTP 504.', 'critical', 'open', null, 5, false],   // overdue (2h SLA)
            ['c0', 'General Inquiry', 'Do you offer on-site training?', 'We are onboarding 20 new staff next month and would like to know about training options.', 'low', 'open', null, 60, false],  // overdue (48h SLA)
            ['a1', 'Software Bug', 'Dashboard date picker ignores time zone', 'Reported by phone: customers in GMT+6 see yesterday\'s data when selecting today.', 'medium', 'open', null, 3, false, 'ERP'],

            // ---- Assigned ----
            ['c1', 'Software Bug', 'PDF export cuts off the last page', 'When exporting a 12-page report to PDF, page 12 is missing.', 'medium', 'assigned', 0, 4, false, 'HRMS'],
            ['c2', 'Billing', 'Charged twice for the annual plan', 'My credit card was charged twice on the renewal date. Please refund one charge.', 'high', 'assigned', 1, 3, false],
            ['c3', 'Account Access', 'Need admin rights for a new team lead', 'Please give our new team lead (Karim) admin permissions on our workspace.', 'low', 'assigned', 2, 10, false],
            ['c0', 'Technical Issue', 'Email notifications delayed by hours', 'Notifications from the system arrive 3-4 hours late since yesterday.', 'high', 'assigned', 3, 12, false],   // overdue (8h)
            ['admin', 'General Inquiry', 'Prepare SLA summary for Q4 review', 'Internal: collect SLA figures for the quarterly customer review meeting.', 'medium', 'assigned', 0, 6, false],
            ['c1', 'Feature Request', 'Dark mode for the web portal', 'Several of our users work night shifts and would appreciate a dark theme.', 'low', 'assigned', 1, 26, false, 'HRMS'],

            // ---- In Progress ----
            ['c2', 'Technical Issue', 'Mobile app crashes on Android 14', 'The app closes immediately after login on Samsung Galaxy S23 devices.', 'critical', 'in_progress', 0, 1, false, 'HRMS'],
            ['c3', 'Software Bug', 'Search results include archived projects', 'Archived projects should not appear in the global search results.', 'medium', 'in_progress', 0, 30, false],  // overdue (24h)
            ['c0', 'Account Access', 'Two-factor codes rejected', 'My authenticator codes are always rejected as invalid since this morning.', 'high', 'in_progress', 1, 5, false],
            ['c1', 'Billing', 'Update company billing address', 'We moved offices. Please update the billing address on future invoices.', 'low', 'in_progress', 2, 15, false, 'PAYROLL'],
            ['c2', 'Technical Issue', 'Slow page loads in the reports module', 'Reports take more than 30 seconds to load since the last release.', 'high', 'in_progress', 3, 9, false, 'ERP'],   // overdue (8h)
            ['c3', 'Software Bug', 'CSV import skips rows with accents', 'Customer names with é or ü are silently skipped during CSV import.', 'medium', 'in_progress', 1, 40, true],  // reopened, overdue
            ['c0', 'General Inquiry', 'Data retention policy question', 'How long do you keep deleted project data before permanent removal?', 'low', 'in_progress', 2, 22, false],

            // ---- Resolved (waiting for customer confirmation) ----
            ['c1', 'Account Access', 'Locked out after too many attempts', 'My account was locked after I mistyped my password several times.', 'high', 'resolved', 0, 20, false],
            ['c2', 'Software Bug', 'Wrong currency symbol on quotes', 'Quotes show $ instead of ৳ for our Bangladesh entity.', 'medium', 'resolved', 1, 50, false, 'ERP'],
            ['c3', 'Billing', 'Request a copy of October receipt', 'Please send a PDF copy of the October payment receipt.', 'low', 'resolved', 2, 70, false],
            ['c0', 'Technical Issue', 'Webhook signature validation fails', 'Our server rejects your webhooks because the signature does not match.', 'critical', 'resolved', 3, 8, false],
            ['c1', 'Feature Request', 'Add Bangla language support', 'Our field staff would prefer the interface in Bangla.', 'low', 'resolved', 0, 120, false, 'HRMS'],

            // ---- Closed ----
            ['c0', 'Account Access', 'Single sign-on setup with Azure AD', 'We need help configuring SSO with our Azure Active Directory tenant.', 'medium', 'closed', 0, 300, false, 'HRMS'],
            ['c1', 'Billing', 'Switch from monthly to annual billing', 'Please move our subscription to annual billing from next cycle.', 'low', 'closed', 1, 280, false],
            ['c2', 'Software Bug', 'Calendar shows events one day late', 'Events created for Friday appear on Saturday in the calendar view.', 'high', 'closed', 2, 240, false],
            ['c3', 'Technical Issue', 'File uploads over 10 MB fail', 'Uploading large attachments returns an error after 30 seconds.', 'high', 'closed', 3, 200, false],
            ['c4', 'General Inquiry', 'Close our trial account', 'We decided not to continue after the trial. Please close the account.', 'low', 'closed', 1, 330, false],
            ['c0', 'Legacy Desktop App', 'Desktop client will not start after Windows update', 'The legacy desktop client crashes on start after the latest Windows update.', 'medium', 'closed', 0, 320, false],
            ['c2', 'Software Bug', 'Duplicate notifications on the mobile app', 'Every push notification is shown twice on iOS.', 'medium', 'closed', 3, 150, false, 'INV'],
            ['c3', 'Account Access', 'Remove access for a former employee', 'Please remove access for rafiq@olivia-company.example immediately.', 'critical', 'closed', 2, 100, false],
        ];

        foreach ($scenarios as $scenario) {
            $this->createTicketWithHistory(...$scenario);
        }
    }

    /**
     * Builds one ticket and replays its lifecycle in the past:
     * created -> assigned -> in progress -> resolved -> (reopened) -> closed.
     */
    private function createTicketWithHistory(
        string $creatorKey,
        string $categoryName,
        string $subject,
        string $description,
        string $priority,
        string $finalStatus,
        ?int $agentIndex,
        int $hoursAgo,
        bool $wasReopened,
        ?string $projectCode = null,
    ): void {
        $creator = $this->resolveCreator($creatorKey);
        $agent = $agentIndex === null ? null : $this->agents[$agentIndex];
        $createdAt = now()->subHours($hoursAgo)->subMinutes(random_int(0, 50));
        $slaHours = Ticket::SLA_HOURS[$priority];

        // Timeline offsets (always in the past).
        $assignedAt = $createdAt->copy()->addMinutes(min(30, $hoursAgo * 6));
        $startedAt = $createdAt->copy()->addMinutes(min(90, $hoursAgo * 12));
        $resolvedAt = $createdAt->copy()->addMinutes((int) min($slaHours * 45, $hoursAgo * 36));
        $closedAt = $resolvedAt->copy()->addMinutes((int) min(240, max(10, ($hoursAgo * 60 - $resolvedAt->diffInMinutes($createdAt)) / 2)));

        $ticket = new Ticket;
        $ticket->subject = $subject;
        $ticket->description = $description;
        $ticket->category_id = $this->categories[$categoryName]->id;
        $ticket->priority = $priority;
        $ticket->created_by = $creator->id;
        $ticket->project_id = $projectCode ? Project::where('code', $projectCode)->value('id') : null;
        $ticket->client_id = $creator->isCustomer() ? $creator->client_id : null;
        $ticket->assigned_to = null;
        $ticket->status = Ticket::STATUS_OPEN;
        $ticket->created_at = $createdAt;
        $ticket->updated_at = $createdAt;
        $ticket->due_at = Ticket::calculateDueAt($createdAt, $priority);
        $ticket->save();
        $ticket->ticket_number = Ticket::makeTicketNumber($ticket->id);
        $ticket->save();

        $this->activity($ticket, $creator, 'created', null, 'Open', $createdAt);
        if ($creator->id !== $this->admin->id) {
            $this->notification($this->admin, $ticket, 'created', 'New ticket created',
                "{$creator->name} created ticket {$ticket->ticket_number} \"{$subject}\".", $createdAt);
        }
        $lastUpdate = $createdAt;

        if ($agent !== null) {
            $this->activity($ticket, $this->admin, 'assigned', null, $agent->name, $assignedAt);
            $this->activity($ticket, $this->admin, 'status_changed', 'Open', 'Assigned', $assignedAt);
            $this->notification($agent, $ticket, 'assigned', 'New ticket assigned',
                "Ticket {$ticket->ticket_number} \"{$subject}\" has been assigned to you.", $assignedAt);
            $ticket->assigned_to = $agent->id;
            $ticket->status = Ticket::STATUS_ASSIGNED;
            $lastUpdate = $assignedAt;
        }

        if (in_array($finalStatus, ['in_progress', 'resolved', 'closed'], true)) {
            $this->activity($ticket, $agent, 'status_changed', 'Assigned', 'In Progress', $startedAt);
            $this->comment($ticket, $agent, "Hi {$creator->name}, I am looking into this now. I will update you shortly.", $startedAt->copy()->addMinutes(2));
            $this->notification($creator, $ticket, 'comment', 'New comment from ' . $agent->name,
                "New comment on ticket {$ticket->ticket_number}: Hi {$creator->name}, I am looking into this now...", $startedAt->copy()->addMinutes(2));
            $ticket->status = Ticket::STATUS_IN_PROGRESS;
            $lastUpdate = $startedAt->copy()->addMinutes(2);
        }

        if ($subject === 'Mobile app crashes on Android 14') {
            // V2 demo: a staff-only internal note (customers never see it).
            $this->comment($ticket, $agent, 'Internal: reproduced on S23. Looks related to the push SDK upgrade in v4.2 - checking with the mobile team.', $startedAt->copy()->addMinutes(10), TicketComment::VISIBILITY_INTERNAL);
            $this->activity($ticket, $agent, 'internal_note_added', null, null, $startedAt->copy()->addMinutes(10), true);
        }

        if ($wasReopened) {
            // First resolution attempt, then the customer reopened it.
            $firstNote = 'Updated the import parser to UTF-8. Please retry the import.';
            $this->activity($ticket, $agent, 'resolved', 'In Progress', 'Resolved', $resolvedAt);
            $reopenedAt = $resolvedAt->copy()->addHours(2);
            $this->comment($ticket, $creator, 'The import still skips names with ü. Reopening.', $reopenedAt);
            $this->activity($ticket, $creator, 'reopened', 'Previous resolution: ' . $firstNote, 'In Progress', $reopenedAt);
            $this->notification($agent, $ticket, 'reopened', 'Ticket reopened',
                "Ticket {$ticket->ticket_number} was reopened and needs more work.", $reopenedAt);
            $lastUpdate = $reopenedAt;
        }

        if (in_array($finalStatus, ['resolved', 'closed'], true)) {
            $note = 'Issue identified and fixed. The change has been deployed and verified with your account.';
            $this->activity($ticket, $agent, 'resolved', 'In Progress', 'Resolved', $resolvedAt);
            $this->notification($creator, $ticket, 'resolved', 'Ticket resolved',
                "Ticket {$ticket->ticket_number} has been resolved. Please review the resolution note.", $resolvedAt);
            $ticket->status = Ticket::STATUS_RESOLVED;
            $ticket->resolved_at = $resolvedAt;
            $ticket->resolution_note = $note;
            $lastUpdate = $resolvedAt;
        }

        if ($finalStatus === 'closed') {
            $this->comment($ticket, $creator, 'Confirmed, everything works now. Thank you!', $closedAt->copy()->subMinute());
            $this->activity($ticket, $creator, 'closed', 'Resolved', 'Closed', $closedAt);
            $this->notification($agent, $ticket, 'closed', 'Ticket closed',
                "Ticket {$ticket->ticket_number} was confirmed and closed.", $closedAt);
            $ticket->status = Ticket::STATUS_CLOSED;
            $lastUpdate = $closedAt;
        }

        $ticket->updated_at = $lastUpdate;
        $ticket->save();
    }

    private function resolveCreator(string $key): User
    {
        if ($key === 'admin') {
            return $this->admin;
        }
        if (str_starts_with($key, 'a')) {
            return $this->agents[(int) substr($key, 1)];
        }

        return $this->customers[(int) substr($key, 1)];
    }

    private function activity(Ticket $ticket, User $user, string $action, ?string $old, ?string $new, Carbon $at, bool $isInternal = false): void
    {
        $activity = new TicketActivity;
        $activity->forceFill([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'is_internal' => $isInternal,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $activity->save();
    }

    private function comment(Ticket $ticket, User $user, string $message, Carbon $at, string $visibility = TicketComment::VISIBILITY_PUBLIC): void
    {
        $comment = new TicketComment;
        $comment->forceFill([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => $message,
            'visibility' => $visibility,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $comment->save();
    }

    /**
     * Stores an in-app notification with a past timestamp.
     * Notifications older than 24 hours are marked as read; newer ones stay unread.
     * The recipient never equals the actor here, matching TicketService rules.
     */
    private function notification(User $recipient, Ticket $ticket, string $eventType, string $title, string $message, Carbon $at): void
    {
        if (! $recipient->is_active) {
            return;
        }

        $data = (new TicketNotification($ticket, $eventType, $title, $message))->toArray($recipient);

        $recipient->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => TicketNotification::class,
            'data' => $data,
            'read_at' => $at->lessThan(now()->subDay()) ? $at->copy()->addHour() : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
