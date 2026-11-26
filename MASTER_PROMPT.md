# MASTER DEVELOPMENT SPECIFICATION

# ResolveDesk — Customer Support & Workflow Automation System

## 1. Your role and my primary objective

Act as a senior PHP Laravel developer, software architect, database designer, UI/UX designer, QA engineer, technical documentation writer and personal Laravel instructor.

Build a complete, functional and professional business application named **ResolveDesk — Customer Support & Workflow Automation System**.

I am preparing for a Laravel developer technical interview.

During the interview, I may be required to:

- Demonstrate the application.
- Explain its architecture.
- Explain its business logic.
- Explain the database design.
- Explain individual source-code files.
- Explain Laravel and PHP concepts.
- Modify existing functionality.
- Implement a new requirement.
- Fix bugs.
- Write database queries.
- Explain system design decisions.

I understand general programming concepts but need to strengthen my practical PHP and Laravel knowledge.

Therefore, this is both a business application and a learning project.

My most important requirement is that I must be able to understand, explain, debug and modify all important custom code independently without AI assistance.

Code simplicity, readability, correctness, security and maintainability are more important than architectural sophistication.

Do not overengineer the application.

---

## 2. Mandatory local development environment

My development environment is Windows.

Multiple PHP versions are installed on my PC.

The global Windows PATH contains an older PHP version used by other projects.

This project must use:

PHP 8.4.28

PHP executable:

C:\php-8.4.28\php.exe

Before starting:

1. Inspect the current project directory.
2. Determine whether Laravel is already installed.
3. Verify the actual PHP executable.
4. Verify PHP version.
5. Verify the PHP version used by Composer.
6. Verify the loaded php.ini file.
7. Verify required PHP extensions.
8. Verify Laravel compatibility.
9. Verify MySQL availability.
10. Check Node.js and npm only if needed.

Use the following commands where appropriate:

```powershell
php -v
composer -V
php --ini
php -m
where.exe php
where.exe composer
```

For PowerShell, use:

```powershell
$env:Path = "C:\php-8.4.28;$env:Path"
```

For Git Bash, use:

```bash
export PATH="/c/php-8.4.28:$PATH"
```

Use the absolute PHP executable path when necessary.

Do not assume temporary PATH changes persist across separate shell executions.

Never modify the permanent Windows PATH.

Never modify or uninstall other PHP versions.

Never use --ignore-platform-reqs to bypass PHP compatibility requirements.

If Laravel is already installed, continue using the existing installation.

Do not reinstall or overwrite the project unnecessarily.

If the directory is empty, initialize Laravel 13 in the current directory.

Do not create an unnecessary nested project directory.

Report genuine environment blockers before proceeding.

---

## 3. Technology stack

Use the following approved technology stack.

| Component | Technology |
|---|---|
| Programming language | PHP 8.4.28 |
| Backend framework | Laravel 13.x |
| Database | MySQL 8.x |
| Frontend | Laravel Blade |
| CSS framework | Bootstrap 5.3 |
| JavaScript | Vanilla JavaScript |
| AJAX | Native JavaScript Fetch API |
| ORM | Eloquent |
| Authentication | Laravel session authentication |
| Authorization | Middleware and Laravel Policies |
| Notifications | Laravel Database Notifications |
| Email | Laravel Mail and SMTP |
| Queue | Laravel Database Queue |
| Cache | Laravel Database Cache |
| Charts | Chart.js |
| Testing | PHPUnit |
| Dependency management | Composer |
| Version control | Git |
| Architecture | Traditional MVC with minimal service layer |

Use local frontend assets where practical so the project can be demonstrated without internet access.

Avoid unnecessary Node.js dependencies.

Use Composer's actual platform requirements to verify compatibility.

---

## 4. Architecture and development restrictions

Follow traditional Laravel MVC architecture.

Use standard Laravel conventions:

- Routes.
- Controllers.
- Middleware.
- Models.
- Eloquent relationships.
- Blade views.
- Policies.
- Migrations.
- Seeders.
- Notifications.
- Jobs.
- Mailables.
- Cache.
- PHPUnit tests.

Keep ordinary CRUD logic inside controllers.

Use a small TicketService only where ticket operations involve multiple coordinated business rules or need to be reused.

Do not introduce a service class for every CRUD operation.

### Do not use

- React.
- Vue.
- Angular.
- Livewire.
- Inertia.js.
- TypeScript.
- jQuery.
- Axios.
- Repository Pattern.
- Domain-Driven Design.
- CQRS.
- Microservices.
- Complex design patterns.
- Unnecessary interfaces.
- Unnecessary abstract classes.
- Third-party role and permission packages.
- Redis.
- RabbitMQ.
- Kafka.
- WebSockets.
- Laravel Reverb.
- Pusher.
- Laravel Echo.
- Complex event-listener architecture.
- Multi-tenancy.
- Kubernetes.
- Unnecessary Docker dependencies.
- Generic workflow engines.

Use a single Laravel monolithic application.

Use database-backed cache and queue drivers.

Explain more advanced architectural alternatives in documentation only.

---

## 5. Business problem

A software company provides products and services to multiple customers.

Customers report technical issues, software bugs, account problems, billing problems and feature requests through phone calls, emails and messaging applications.

Without a centralized support system:

- Customer issues may be forgotten.
- Support engineers may work on duplicate issues.
- Customers cannot track issue resolution.
- Management cannot monitor support workloads.
- Critical issues may not receive sufficient attention.
- Support engineers may receive unequal workloads.
- Resolution deadlines may be missed.
- There is no reliable accountability.
- There is no centralized communication history.

ResolveDesk solves these problems through:

- Centralized ticket management.
- Role-based access.
- Manual and automatic ticket assignment.
- Controlled ticket lifecycle.
- SLA tracking.
- Customer-agent communication.
- Activity history.
- Automatic in-app notifications.
- Queued email notifications.
- Dashboard and operational reporting.

---

## 6. User roles and permissions

Implement three fixed user roles using a role column in the users table.

Internal role values:

- admin
- agent
- customer

Do not implement dynamic permission management.

Do not create separate roles and permissions tables.

### 6.1 Administrator

An administrator can:

- Access the administrative dashboard.
- Create and manage users.
- Activate and deactivate users.
- Manage ticket categories.
- View all tickets.
- Create tickets.
- Edit tickets where permitted.
- Manually assign tickets.
- Automatically assign tickets.
- Reassign tickets.
- Change ticket priority.
- Manage valid ticket status transitions.
- Add ticket comments.
- View ticket activity history.
- View agent workload.
- View operational statistics.
- Receive relevant notifications.

### 6.2 Support Agent

A support agent can:

- Access a personal dashboard.
- View tickets assigned to them.
- View tickets they created.
- Start working on assigned tickets.
- Resolve assigned tickets.
- Add comments.
- Add resolution notes.
- View relevant activity history.
- Receive assignment notifications.
- Receive relevant email notifications.
- View personal workload.
- View overdue assigned tickets.

Support agents cannot access unauthorized tickets belonging to other users.

An agent who created a ticket may perform creator-level actions on that ticket, subject to the defined business rules.

### 6.3 Customer

A customer can:

- Access a personal dashboard.
- Create support tickets.
- View their own tickets.
- Edit their own Open and unassigned tickets.
- Add comments.
- View ticket status.
- View SLA deadlines.
- View resolution notes.
- Confirm resolution and close tickets.
- Reopen resolved tickets.
- Receive relevant notifications.

Customers cannot view other customers' tickets.

### 6.4 Authorization

Use middleware for general role-based restrictions.

Use Laravel Policies for ticket-level authorization.

Enforce authorization on the backend for every protected operation.

Hiding buttons in the UI is not sufficient.

Prevent unauthorized access through manually modified URLs, form submissions and AJAX requests.

Inactive users must not be able to log in or continue accessing protected functionality through an existing session.

---

## 7. Functional modules

### Module 1: Authentication

Implement:

- Login.
- Logout.
- Session-based authentication.
- Password hashing.
- Login validation.
- Login rate limiting.
- Session regeneration after login.
- Session invalidation after logout.
- Authentication middleware.
- Active user verification.
- Role-based navigation.

Public registration is not required.

Administrators will create user accounts.

Use Laravel's built-in authentication functionality.

Do not create a custom authentication framework.

### Module 2: User Management

Implement:

- User list.
- Create user.
- View user details.
- Edit user.
- Activate or deactivate user.
- Search by name or email.
- Filter by role.
- Filter by active status.
- Pagination.

User fields:

- Name.
- Email.
- Password.
- Role.
- Active status.

Business rules:

- Email must be unique.
- Password must be securely hashed.
- Password updates must be optional during editing.
- Referenced users must not be permanently deleted.
- Administrators cannot deactivate themselves.
- The last active administrator cannot be deactivated or demoted.
- An agent cannot be deactivated or demoted while responsible for unresolved or reopenable tickets unless those tickets are appropriately reassigned.

### Module 3: Category Management

Implement standard CRUD functionality.

Fields:

- Name.
- Description.
- Active status.

Example categories:

- Technical Issue.
- Software Bug.
- Account Access.
- Billing.
- Feature Request.
- General Inquiry.

Business rules:

- Category names must be unique.
- Only active categories appear in new ticket forms.
- Existing tickets retain their category relationships.
- Categories referenced by tickets cannot be permanently deleted.
- Administrators can deactivate obsolete categories.
- Existing tickets with inactive categories must remain viewable.
- Editing an existing ticket must not unnecessarily force a category change.

Cache active category dropdown data.

### Module 4: Ticket Management

Implement:

- Ticket list.
- Create ticket.
- View ticket details.
- Edit ticket where authorized.
- Manual assignment.
- Automatic assignment.
- Reassignment.
- Status updates.
- Priority updates.
- Ticket search.
- Category filter.
- Status filter.
- Priority filter.
- Assigned agent filter.
- Date-range filter.
- Overdue filter.
- Pagination.

Ticket fields:

- Unique ticket number.
- Subject.
- Description.
- Category.
- Created by.
- Assigned agent.
- Priority.
- Status.
- SLA due date.
- Resolution date.
- Resolution note.
- Created at.
- Updated at.

Ticket priorities:

- Low.
- Medium.
- High.
- Critical.

Internal values:

- low
- medium
- high
- critical

Ticket statuses:

- Open.
- Assigned.
- In Progress.
- Resolved.
- Closed.

Internal values:

- open
- assigned
- in_progress
- resolved
- closed

Do not permanently delete tickets through the UI.

### Module 5: Ticket Comments

Implement ticket conversation functionality.

Each comment contains:

- Ticket reference.
- User reference.
- Comment message.
- Creation timestamp.

Display comments chronologically.

Show:

- Author name.
- Author role.
- Comment message.
- Date and time.

Only authorized users can add comments.

Allow comments on Open, Assigned, In Progress and Resolved tickets.

Closed tickets are read-only.

Generate relevant in-app notifications when comments are added.

Do not send an email for every comment.

### Module 6: Ticket Activity History

Implement a simple audit trail.

Record:

- Ticket creation.
- Ticket editing.
- Agent assignment.
- Agent reassignment.
- Priority changes.
- Status changes.
- Ticket resolution.
- Ticket reopening.
- Ticket closure.

Each activity contains:

- Ticket reference.
- Responsible user.
- Action.
- Previous value.
- New value.
- Timestamp.

Display activity history as a chronological timeline.

Preserve previous resolution notes when tickets are reopened.

Do not introduce a third-party audit logging package.

### Module 7: Dashboard and Reporting

Create role-specific dashboards.

Administrator dashboard:

- Total tickets.
- Open tickets.
- Assigned tickets.
- In-progress tickets.
- Resolved tickets.
- Closed tickets.
- Overdue tickets.
- Tickets by priority.
- Tickets by category.
- Recent tickets.
- Support agent workload.
- Ticket creation trend.
- Average resolution time.

Calculate average resolution time for currently Resolved and Closed tickets using created_at and resolved_at.

Document that this simplified metric measures time from original creation to the latest resolution.

Agent dashboard:

- My assigned tickets.
- My pending tickets.
- My in-progress tickets.
- My resolved tickets.
- My overdue tickets.
- Recent assigned tickets.

Customer dashboard:

- My total tickets.
- My active tickets.
- My resolved tickets.
- My closed tickets.
- Recent ticket updates.

Use actual database queries.

Use Chart.js for a small number of meaningful charts.

Do not use fake dashboard statistics.

Do not build a complicated reporting engine.

### Module 8: Notification Management

Implement:

- Laravel database notifications.
- Notification bell.
- Unread notification count.
- Notification dropdown.
- Latest five notifications.
- Dedicated notification history page.
- Read/unread indicators.
- Mark individual notification as read.
- Mark all notifications as read.
- Read/unread filtering.
- Pagination.
- Click notification to open associated ticket.
- Automatic notification updates.
- Bootstrap toast notifications.

Notifications must appear automatically without refreshing or reloading the page.

### Module 9: Email and Queue

Implement asynchronous email notifications for important ticket events.

Use Laravel database queues.

Use one reusable Job, one reusable Mailable and one reusable email template.

### Module 10: Caching

Implement minimal caching for:

- Administrator dashboard aggregate statistics.
- Active ticket categories.

Use Laravel Cache::remember() and Cache::forget().

---

## 8. Complete business logic

### 8.1 Ticket creation

When an authorized user creates a ticket:

1. Validate submitted data.
2. Identify the authenticated user.
3. Verify that the selected category is active.
4. Validate priority.
5. Create the ticket.
6. Generate a unique ticket number.
7. Set initial status to Open.
8. Set assigned_to to null.
9. Calculate the SLA deadline.
10. Record ticket creation activity.
11. Commit the database transaction.
12. Invalidate dashboard cache.
13. Redirect to the ticket details page.
14. Display a success message.

Example ticket number:

TK-000001

Generate ticket numbers using the database-generated ID or another straightforward collision-safe approach.

Do not use MAX(id) + 1.

If an initial insert followed by an update is required, ensure the database schema supports that operation safely.

Derive created_by from the authenticated user.

Do not trust a submitted creator ID.

### 8.2 Manual ticket assignment

An administrator selects an active support agent.

When the administrator clicks Assign:

1. Verify administrator permission.
2. Validate ticket status.
3. Validate selected agent.
4. Verify the agent is active.
5. Set assigned_to.
6. Change Open status to Assigned.
7. Record assignment activity.
8. Create an in-app notification.
9. Commit the transaction.
10. Invalidate dashboard cache.
11. Dispatch the email job after commit.
12. Return a success response.

The assigned agent must receive an automatic in-app notification without refreshing their browser.

### 8.3 Automatic ticket assignment

Provide an Auto Assign button for administrators.

Use a simple least-workload assignment algorithm.

Count tickets with Assigned or In Progress status for each active support agent.

Example:

| Agent | Active tickets |
|---|---:|
| Agent A | 5 |
| Agent B | 2 |
| Agent C | 4 |

Assign the ticket to Agent B.

If multiple agents have the same workload, use their user ID as a deterministic tie-breaker.

Use a readable Eloquent withCount() query.

If no active agent is available:

- Leave the ticket Open.
- Do not create assignment notifications.
- Do not dispatch an email.
- Display a meaningful message.

Reuse manual assignment business logic where practical.

Use a transaction and straightforward row locking where necessary to protect against simultaneous modification of the same ticket.

Document that simultaneous assignment of different tickets may still select the same least-busy agent.

Do not implement distributed locking.

Explain that this is application-level workload distribution, not infrastructure load balancing.

### 8.4 Ticket reassignment

Allow administrators to reassign Assigned or In Progress tickets to another active agent.

When reassigning:

- Preserve the current status.
- Record the previous and new agent.
- Notify the newly assigned agent.
- Dispatch a queued email.
- Remove the ticket from the previous agent's assigned list.
- Invalidate dashboard cache.

Do not generate duplicate activities, notifications or emails when the assigned agent has not changed.

### 8.5 Ticket lifecycle

Implement the following valid transitions.

| Current status | Next status | Action |
|---|---|---|
| Open | Assigned | Assign |
| Assigned | In Progress | Start Working |
| In Progress | Resolved | Resolve |
| Resolved | Closed | Close |
| Resolved | In Progress | Reopen |

Business rules:

- Open to Assigned occurs through assignment.
- Assigned to In Progress can be performed by the assigned agent or administrator.
- In Progress to Resolved can be performed by the assigned agent or administrator.
- Resolution requires a resolution note.
- Resolved to Closed can be performed by the ticket creator or administrator.
- Resolved to In Progress can be performed by the ticket creator or administrator.
- Reopening requires a valid active assigned agent.
- Reject invalid status transitions.
- Closed tickets cannot be modified.
- Do not allow arbitrary status changes through the general edit form.

Use simple conditions or a centralized transition array.

Do not introduce a state machine package.

### 8.6 Ticket resolution

When a ticket is resolved:

- Verify authorization.
- Verify current status.
- Require a resolution note.
- Change status to Resolved.
- Set resolved_at to the current time.
- Save resolution note.
- Record activity.
- Notify the ticket creator.
- Dispatch a queued email.
- Invalidate dashboard cache.

### 8.7 Ticket reopening

When a resolved ticket is reopened:

- Verify authorization.
- Verify current status.
- Verify the assigned agent is valid and active.
- Change status to In Progress.
- Clear resolved_at.
- Preserve previous resolution notes in activity history.
- Clear the current resolution note.
- Notify the assigned agent.
- Dispatch a queued email.
- Invalidate dashboard cache.

Preserve the original SLA deadline.

If the deadline has passed, the reopened ticket becomes overdue.

Document this as a deliberate simplified business rule.

### 8.8 Ticket closure

When the ticket creator confirms resolution:

- Verify authorization.
- Verify Resolved status.
- Change status to Closed.
- Preserve resolved_at.
- Record closure activity.
- Notify the assigned agent.
- Invalidate dashboard cache.

Closed tickets become read-only.

### 8.9 SLA deadline calculation

SLA means Service Level Agreement.

Use the following resolution deadlines:

| Priority | Resolution deadline |
|---|---:|
| Low | 48 hours |
| Medium | 24 hours |
| High | 8 hours |
| Critical | 2 hours |

Use Laravel Carbon for date calculations.

Calculate due_at from the original ticket creation time.

If an administrator changes priority:

- Recalculate due_at from the original creation time.
- Record previous and new priority.
- Record the changed deadline.
- Invalidate relevant cache.

A ticket is currently overdue when:

- Current time is greater than due_at.
- Status is Open, Assigned or In Progress.

Resolved and Closed tickets are not currently overdue.

Use continuous calendar hours.

Do not implement business hours, holiday calendars or weekend calculations.

Calculate overdue status dynamically.

Do not implement scheduled SLA escalation in this version.

Explain how Laravel Scheduler could support automatic escalation in the future.

### 8.10 Database transactions

Use DB::transaction() for coordinated database operations.

Examples:

- Ticket creation and activity logging.
- Assignment, activity logging and database notification.
- Resolution, activity logging and database notification.
- Reopening, activity logging and database notification.
- Comments and corresponding database notifications.

If a coordinated operation fails, roll back its related database changes.

Keep SMTP email delivery outside database transactions.

Explain transaction commit, rollback and data consistency using actual project code.

---

## 9. Near-real-time notification system

Use Laravel's built-in database notifications and AJAX polling.

### 9.1 Backend

Use:

- Laravel Notifiable trait.
- Laravel Notification class.
- Standard notifications table.
- NotificationController.
- Authenticated JSON endpoints.

Create one reusable TicketNotification class where practical.

Store:

- Ticket ID.
- Ticket number.
- Notification title.
- Notification message.
- Event type.

Use Laravel's standard notification timestamps and read_at column.

Create an authenticated endpoint that returns:

- Unread notification count.
- Recent notifications.
- Required ticket information.
- Read/unread status.

Only retrieve notifications belonging to the authenticated user.

Prevent unauthorized access to notification records.

### 9.2 Notification matrix

| Event | Recipient | In-app | Queued email |
|---|---|---|---|
| Ticket assigned | Assigned agent | Yes | Yes |
| Ticket reassigned | New agent | Yes | Yes |
| Creator comment | Assigned agent | Yes | No |
| Agent reply | Ticket creator | Yes | No |
| Ticket resolved | Ticket creator | Yes | Yes |
| Ticket reopened | Assigned agent | Yes | Yes |
| Ticket closed | Assigned agent | Yes | No |

For administrator comments, notify relevant participants other than the author.

Avoid duplicate recipients.

Do not notify users about their own actions unnecessarily.

Skip unavailable recipients safely.

Avoid notifications when an operation has not actually changed data.

### 9.3 Automatic AJAX polling

Create:

public/js/notifications.js

Use the native JavaScript Fetch API.

Fetch notification updates every five seconds.

Use recursive setTimeout() to prevent overlapping requests.

Keep the polling interval configurable in one JavaScript constant.

Requirements:

- Update unread count automatically.
- Update notification dropdown automatically.
- Display a Bootstrap toast for genuinely new notifications.
- Include ticket number, title and short message.
- Prevent repeated toast messages using notification IDs.
- Do not display historical notifications as new toasts on initial page load.
- Handle multiple notifications arriving between polling requests.
- Keep complete notification history available.
- Mark individual notifications as read without page reload.
- Mark all notifications as read without page reload.
- Use CSRF protection for data-changing requests.
- Safely render user-generated text using textContent or equivalent safe DOM methods.
- Handle network errors gracefully.
- Handle expired sessions.
- Pause or reduce polling when the browser tab is hidden.
- Fetch immediately when the tab becomes visible again.
- Prevent multiple polling loops from running simultaneously.
- Avoid interfering with existing page functionality.

Do not reload the current page when a notification arrives.

Clicking a notification may navigate to its associated ticket after marking it as read.

Always verify ticket authorization.

If access has been revoked, handle the situation gracefully.

Explain that AJAX polling is near-real-time rather than true server-push communication.

### 9.4 Notification UI

Place a notification bell in the shared authenticated navbar.

Include:

- Unread count badge.
- Latest five notifications.
- Read/unread indicators.
- Relative timestamps.
- Mark all as read.
- View all notifications.

Use Bootstrap toast notifications.

The notification system must work on every authenticated page.

Do not use WebSockets, Laravel Reverb, Pusher or external notification services.

---

## 10. Queue and email notifications

### 10.1 Queue configuration

Use:

```env
QUEUE_CONNECTION=database
```

Use Laravel's standard jobs and failed_jobs tables.

Check existing migrations before creating new ones.

### 10.2 Reusable email job

Create:

app/Jobs/SendTicketEmail.php

Implement one simple reusable Job.

Requirements:

- Implement ShouldQueue.
- Use Laravel's standard Queueable functionality.
- Receive recipient information and necessary ticket event data.
- Send email using a Laravel Mailable.
- Allow a maximum of three execution attempts.
- Use a retry backoff of ten seconds.
- Support failed job tracking.

Prefer simple scalar values or a small data array.

Explain job serialization.

Dispatch the email job only after the related business transaction successfully commits.

Use afterCommit() or an equivalent straightforward implementation.

If a transaction rolls back, its email job must not be dispatched.

If email delivery fails, the successful ticket operation must not be reversed.

Handle queue dispatch failures after commit appropriately.

Log dispatch failures without misleading users into believing a committed ticket operation failed.

Do not implement a transactional outbox.

Explain it as a future improvement only.

### 10.3 Reusable Mailable

Create:

app/Mail/TicketUpdateMail.php

Create:

resources/views/emails/ticket-update.blade.php

Email content:

- ResolveDesk branding.
- Recipient name.
- Ticket number.
- Ticket subject.
- Relevant event message.
- Status at the time of the event.
- Ticket details link.
- Professional email layout.

Use existing authorization when recipients open ticket links.

Avoid exposing sensitive ticket information unnecessarily.

### 10.4 Email configuration

Use the log mail driver by default.

```env
MAIL_MAILER=log
```

The entire application must work without actual SMTP configuration.

Document Gmail SMTP configuration using:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="ResolveDesk"
```

Explain Google App Password requirements where applicable.

Never hardcode or commit credentials.

### 10.5 Queue worker

Document and verify:

```bash
php artisan queue:work
php artisan queue:failed
php artisan queue:retry
php artisan queue:restart
```

Explain:

- Producer.
- Consumer.
- Queue.
- Job.
- Queue worker.
- Payload.
- Serialization.
- Retry.
- Backoff.
- Failed jobs.
- Synchronous execution.
- Asynchronous execution.

When the queue worker is stopped:

- Ticket operations must continue working.
- In-app notifications must continue working.
- Email jobs should remain pending.
- Pending jobs should be processed when the worker starts.

Document that retry mechanisms can occasionally produce duplicate email delivery.

Explain idempotency conceptually without introducing complex delivery tracking.

---

## 11. Caching requirements

Use:

```env
CACHE_STORE=database
```

Use Laravel's standard cache tables.

Check existing migrations first.

Implement the cache-aside pattern.

### 11.1 Administrator dashboard cache

Cache expensive administrator dashboard aggregate statistics.

Use:

Cache::remember()

Cache key:

dashboard:admin:stats

TTL:

60 seconds.

Cache suitable statistics such as:

- Total tickets.
- Open tickets.
- Assigned tickets.
- In-progress tickets.
- Resolved tickets.
- Closed tickets.
- Tickets grouped by priority.
- Tickets grouped by category.

Do not cache dynamically calculated overdue statistics.

Invalidate the dashboard cache after successful operations affecting cached statistics.

Examples:

- Ticket creation.
- Assignment.
- Reassignment.
- Status changes.
- Priority changes.
- Category changes.
- Category updates affecting report labels.

Use Cache::forget().

Perform invalidation after successful transaction commits where applicable.

### 11.2 Active category cache

Cache active ticket categories used in creation and editing forms.

Cache key:

ticket:active-categories

TTL:

3600 seconds.

Invalidate when a category is:

- Created.
- Updated.
- Activated.
- Deactivated.
- Deleted.

Always validate category eligibility against current database state.

Do not rely on cached data for business validation or security decisions.

### 11.3 Do not cache

- Ticket details.
- Ticket comments.
- Current ticket status.
- Current agent workload used for assignment.
- Unread notification counts.
- User permissions.
- Authentication decisions.
- Time-sensitive overdue calculations.

Do not cache customer or agent dashboards in this version.

### 11.4 Explain caching concepts

Explain:

- Cache hit.
- Cache miss.
- Cache key.
- TTL.
- Cache-aside pattern.
- Cache invalidation.
- Stale data.
- Database-backed caching.
- Cache consistency.
- Database cache versus Redis cache.
- Limitations under concurrent requests.

Explain that database-backed caching still performs database access but can reduce expensive aggregate queries.

Do not introduce a separate caching service unless genuinely necessary.

---

## 12. Database design

Use five primary business tables.

### users

- id
- name
- email
- password
- role
- is_active
- remember_token
- created_at
- updated_at

### categories

- id
- name
- description
- is_active
- created_at
- updated_at

### tickets

- id
- ticket_number
- subject
- description
- category_id
- created_by
- assigned_to
- priority
- status
- due_at
- resolved_at
- resolution_note
- created_at
- updated_at

### ticket_comments

- id
- ticket_id
- user_id
- message
- created_at
- updated_at

### ticket_activities

- id
- ticket_id
- user_id
- action
- old_value
- new_value
- created_at
- updated_at

Use appropriate simple JSON or text columns for old_value and new_value.

### Laravel infrastructure tables

Use standard Laravel tables as required:

- notifications
- jobs
- failed_jobs
- cache
- cache_locks
- sessions
- migrations

Keep other default infrastructure tables only where required.

### Database requirements

- Appropriate primary keys.
- Appropriate foreign keys.
- Unique email constraint.
- Unique ticket number constraint.
- Unique category name constraint.
- Nullable assigned_to.
- Nullable resolved_at.
- Nullable resolution_note.
- Appropriate deletion restrictions.
- Appropriate column types.
- Meaningful indexes.

Consider indexes for:

- Ticket status.
- Ticket priority.
- Category.
- Ticket creator.
- Assigned agent.
- Due date.
- Creation date.
- Assigned agent and status combination.

Avoid unnecessary and redundant indexes.

Use simple string columns for roles, statuses and priorities with centralized allowed values.

Avoid database ENUM types if they make interview-time modifications unnecessarily difficult.

Explain every important table, column, relationship, constraint and index.

---

## 13. Eloquent relationships

Implement and explain the following relationships.

### User

- createdTickets(): hasMany
- assignedTickets(): hasMany
- ticketComments(): hasMany
- ticketActivities(): hasMany
- notifications(): provided by Notifiable

### Category

- tickets(): hasMany

### Ticket

- creator(): belongsTo User
- assignedAgent(): belongsTo User
- category(): belongsTo Category
- comments(): hasMany TicketComment
- activities(): hasMany TicketActivity

### TicketComment

- ticket(): belongsTo Ticket
- user(): belongsTo User

### TicketActivity

- ticket(): belongsTo Ticket
- user(): belongsTo User

Explain:

- hasMany().
- belongsTo().
- Foreign keys.
- Relationship naming.
- Nullable relationships.
- Lazy loading.
- Eager loading.
- with().
- withCount().
- N+1 query problem.
- Equivalent SQL queries.

Use eager loading where appropriate.

Use pagination for large result sets.

Do not load all database records into memory unnecessarily.

---

## 14. UI/UX requirements

Build a professional, responsive business application.

The application should look suitable for a modern software company rather than a basic student CRUD project.

Use Bootstrap 5.3 and Blade.

### Visual design

Use:

- Clean light theme.
- Professional blue or indigo accent color.
- White and neutral gray backgrounds.
- Consistent typography.
- Appropriate whitespace.
- Subtle borders and shadows.
- Clear visual hierarchy.
- Responsive layouts.
- Consistent icons.
- Accessible form controls.
- Meaningful status and priority colors.

Avoid excessive animations, gradients and unnecessary decorative elements.

### Shared layout

Create:

- Left sidebar.
- Top navbar.
- ResolveDesk branding.
- Notification bell.
- User profile dropdown.
- Breadcrumbs.
- Main content area.
- Flash messages.
- Validation errors.
- Confirmation dialogs.

Navigation must change according to user role.

Use reusable Blade layouts and partials.

### Required pages

1. Login.
2. Administrator dashboard.
3. Support agent dashboard.
4. Customer dashboard.
5. User list.
6. Create user.
7. Edit user.
8. User details.
9. Category list.
10. Create category.
11. Edit category.
12. Ticket list.
13. Create ticket.
14. Edit ticket.
15. Ticket details.
16. Notification history.

### Ticket list page

Include:

- Page title.
- Create Ticket button.
- Search.
- Status filter.
- Priority filter.
- Category filter.
- Assigned agent filter where appropriate.
- Date-range filter.
- Overdue filter.
- Reset filters.
- Responsive table.
- Pagination.

Table columns:

- Ticket number.
- Subject.
- Category.
- Priority.
- Status.
- Created by.
- Assigned agent.
- Due date.
- Created date.
- Actions.

Use readable status badges.

Priority colors:

- Low: neutral.
- Medium: blue.
- High: orange.
- Critical: red.

Clearly identify overdue tickets.

### Ticket details page

Use a two-column layout on desktop.

Main content:

- Ticket subject.
- Description.
- Conversation.
- Comment form.
- Resolution note.
- Activity timeline.

Side panel:

- Ticket number.
- Category.
- Priority.
- Status.
- Created by.
- Assigned agent.
- SLA deadline.
- Overdue indicator.
- Creation time.
- Resolution time.

Available actions based on role and status:

- Edit Ticket.
- Assign Agent.
- Auto Assign.
- Reassign Agent.
- Start Working.
- Resolve Ticket.
- Reopen Ticket.
- Close Ticket.

Do not display invalid actions.

Enforce the same restrictions on the backend.

### Form requirements

Every form must include:

- Clear labels.
- Required field indicators.
- Helpful placeholders.
- Validation errors.
- Previously submitted values after validation failure.
- Submit button.
- Cancel or back button.

Provide confirmation for important actions.

Every button, form, filter and navigation item must work.

Do not create placeholder functionality.

---

## 15. Recommended project structure

Follow a structure similar to:

```text
resolvedesk/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php
│   │   │   ├── DashboardController.php
│   │   │   ├── UserController.php
│   │   │   ├── CategoryController.php
│   │   │   ├── TicketController.php
│   │   │   ├── TicketCommentController.php
│   │   │   └── NotificationController.php
│   │   └── Middleware/
│   │       ├── RoleMiddleware.php
│   │       └── ActiveUserMiddleware.php
│   ├── Models/
│   │   ├── User.php
│   │   ├── Category.php
│   │   ├── Ticket.php
│   │   ├── TicketComment.php
│   │   └── TicketActivity.php
│   ├── Policies/
│   │   └── TicketPolicy.php
│   ├── Services/
│   │   └── TicketService.php
│   ├── Notifications/
│   │   └── TicketNotification.php
│   ├── Jobs/
│   │   └── SendTicketEmail.php
│   └── Mail/
│       └── TicketUpdateMail.php
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── public/
│   ├── css/
│   └── js/
│       └── notifications.js
├── resources/
│   └── views/
│       ├── layouts/
│       ├── partials/
│       ├── auth/
│       ├── dashboard/
│       ├── users/
│       ├── categories/
│       ├── tickets/
│       ├── notifications/
│       └── emails/
├── routes/
│   └── web.php
├── tests/
│   └── Feature/
├── docs/
├── .vscode/
│   └── settings.json
├── CLAUDE.md
├── MASTER_PROMPT.md
├── README.md
└── DEVELOPMENT_PROGRESS.md
```

Adapt this structure only when genuinely necessary.

Do not create unnecessary files.

Explain why every custom file exists.

---

## 16. Coding style and maintainability

Write code for a developer who understands basic programming but is still learning Laravel.

Use:

- Descriptive variable names.
- Explicit method names.
- Small readable methods.
- Standard PHP syntax.
- Standard Laravel conventions.
- Straightforward conditions.
- Understandable loops.
- Simple Eloquent queries.
- Consistent naming.
- Explicit business rules.
- Meaningful comments for non-obvious logic.

Avoid:

- Excessively nested closures.
- Excessive method chaining.
- Complicated one-line expressions.
- Unnecessary interfaces.
- Unnecessary abstract classes.
- Dynamic programming tricks.
- Premature optimization.
- Excessive helper functions.
- Duplicate business rules.
- Large controllers containing unrelated responsibilities.
- Unnecessary JavaScript abstractions.

Use inline validation for straightforward CRUD forms.

Use Form Request classes only when validation becomes genuinely complex or reusable.

Centralize allowed roles, statuses, priorities and SLA durations in an obvious location.

Use standard resource controllers where appropriate.

Use named routes.

Use route model binding.

Do not use GET requests for data-changing operations.

Keep business logic out of Blade templates.

Use Blade for presentation and simple display conditions.

---

## 17. Security requirements

Implement and explain:

- Authentication.
- Authorization.
- Role-based access control.
- Resource-level authorization.
- Password hashing.
- Session security.
- Login rate limiting.
- CSRF protection.
- Server-side validation.
- Mass-assignment protection.
- SQL injection prevention.
- XSS prevention.
- Safe AJAX requests.
- Safe notification rendering.
- Foreign key constraints.
- Protection against insecure direct object references.

Never trust submitted user IDs, ownership, roles or permission values without backend verification.

Use escaped Blade output for user-generated content.

Use parameterized queries or Eloquent.

Do not expose stack traces or sensitive configuration in production mode.

Never hardcode passwords, SMTP credentials or application secrets.

Ensure .env is excluded from Git.

Document safe development and production configuration differences.

---

## 18. Demo data and seeders

Create realistic and internally consistent demonstration data.

Include:

- One administrator.
- Four support agents.
- Five customers.
- Six ticket categories.
- At least 30 tickets.
- Different priorities.
- Different statuses.
- Assigned and unassigned tickets.
- Overdue and non-overdue tickets.
- Resolved and closed tickets.
- Ticket comments.
- Activity records.
- Read and unread notifications.

Seeded records must obey actual business rules.

Do not send real emails during seeding.

Do not unnecessarily dispatch jobs during seeding.

Provide local demonstration credentials for:

- Administrator.
- Support agent.
- Customer.

Clearly state that these credentials are for local demonstration only.

Ensure dashboards contain realistic data.

---

## 19. Automated testing

Implement simple PHPUnit feature tests.

Prioritize important business rules.

### Authentication and authorization

Test:

- Successful login.
- Invalid login.
- Inactive user access.
- Unauthorized role access.
- Customer cannot access another customer's ticket.
- Agent cannot access unauthorized tickets.

### Ticket management

Test:

- Ticket creation.
- Invalid ticket creation.
- Unique ticket number generation.
- Manual assignment.
- Automatic assignment.
- No available agent.
- Reassignment.
- Duplicate assignment prevention.
- Valid status transitions.
- Invalid status transitions.
- Required resolution note.
- Resolution.
- Reopening.
- Closure.
- Closed ticket modification prevention.
- SLA calculation.

### Notifications

Test:

- Correct notification creation.
- Correct recipient.
- Duplicate prevention.
- Notification fetch endpoint.
- Unread count.
- Mark as read.
- Mark all as read.
- Unauthorized notification access.

### Queue and email

Test:

- Correct queued email dispatch.
- Correct email recipient.
- No email dispatch after rollback.
- Email processing through the job.
- Email failure does not reverse committed ticket operations.
- In-app notifications work without a queue worker.

Use appropriate Laravel fakes.

Ensure transaction tests genuinely exercise commits and rollbacks.

### Cache

Test:

- Cache creation.
- Cache retrieval.
- Category cache invalidation.
- Dashboard cache invalidation.
- Correct statistics after invalidation.

Use an isolated testing database.

Never run destructive tests against the development database.

Explain how to run all tests and individual tests.

Do not claim tests passed unless actually executed.

---

## 20. Documentation and teaching requirements

Documentation is mandatory and as important as the application.

Create a comprehensive docs directory.

Documentation must match the actual generated project.

Do not produce generic Laravel tutorials disconnected from the implementation.

Use actual:

- File paths.
- Class names.
- Method names.
- Route names.
- Database tables.
- Code snippets.
- UI actions.
- Business rules.

Explain important custom code line by line or logical block by logical block.

Explain standard framework internals conceptually without unnecessarily documenting every vendor file.

For important code, explain:

- What the syntax means.
- What the code does.
- Why it is required.
- What happens internally.
- What happens if the code is removed.
- What can go wrong.
- How to debug it.
- How to modify it.

Use English for source code and simple English for documentation.

Provide additional Bangla explanations for difficult PHP, Laravel, database and system design concepts.

### README.md

Include:

- Project overview.
- Business problem.
- Features.
- Technology stack.
- Architecture.
- Installation instructions.
- Project-specific PHP configuration.
- Database configuration.
- Startup commands.
- Queue worker commands.
- Demo credentials.
- Testing instructions.
- Documentation index.
- Known limitations.

### docs/01-PROJECT-OVERVIEW.md

Explain:

- Business problem.
- Proposed solution.
- User roles.
- Functional requirements.
- Business workflows.
- Technology choices.
- Architecture.
- Implemented features.
- Limitations.
- Future improvements.

Include Mermaid architecture and ticket lifecycle diagrams.

### docs/02-SETUP-GUIDE.md

Explain:

- Required software.
- PHP 8.4 configuration.
- Multiple PHP versions.
- Temporary PATH.
- VS Code workspace configuration.
- Composer PHP version.
- MySQL configuration.
- Environment variables.
- APP_KEY.
- Composer commands.
- Artisan commands.
- Migrations.
- Seeders.
- Application startup.
- Queue worker startup.
- SMTP configuration.
- Cache configuration.
- Testing commands.
- Common setup problems.

Explain what each important command actually does.

### docs/03-DATABASE-GUIDE.md

Explain:

- Every custom database table.
- Important Laravel infrastructure tables.
- Every important column.
- Primary keys.
- Foreign keys.
- Unique constraints.
- Indexes.
- Nullable columns.
- Database normalization.
- Migrations.
- Eloquent models.
- Relationships.
- Sample records.
- Equivalent SQL queries.

Include a complete Mermaid ER diagram.

Explain why created_by and assigned_to both reference users.

Explain nullable relationships.

Explain Laravel's notification polymorphic relationship.

### docs/04-LARAVEL-MVC-GUIDE.md

Explain Laravel MVC using the actual project.

Cover:

- public/index.php.
- bootstrap/app.php.
- Laravel application initialization.
- HTTP request lifecycle.
- routes/web.php.
- Middleware.
- Controllers.
- Request objects.
- Validation.
- Models.
- Eloquent ORM.
- Blade.
- Layout inheritance.
- HTTP responses.
- JSON responses.
- Redirects.
- Flash messages.
- Dependency injection.
- Service container.
- Facades.
- Route model binding.
- Sessions.
- Authentication.
- Authorization.
- CSRF.

Explain the complete lifecycle from browser request to database operation and returned HTML or JSON.

Include diagrams and actual project examples.

### docs/05-MODULE-WISE-CODE-EXPLANATION.md

Explain every implemented module individually.

For every important operation, follow this structure:

1. Business purpose.
2. User roles.
3. UI page.
4. Available UI actions.
5. Relevant Blade file.
6. What happens when the user clicks a button.
7. Submitted form data.
8. HTTP method and URL.
9. Laravel route.
10. Middleware.
11. Controller method.
12. Validation.
13. Authorization.
14. Business logic.
15. Eloquent operations.
16. Equivalent SQL.
17. Database changes.
18. Notification operations.
19. Queue operations.
20. Cache operations.
21. Response or redirect.
22. Blade rendering or JavaScript update.
23. Error handling.
24. Relevant source files.
25. Common modifications.

Explain important custom code line by line.

Explain relevant PHP and Laravel syntax.

### docs/06-BUSINESS-LOGIC-GUIDE.md

Explain all business rules.

Include:

- User activation and deactivation.
- Category management.
- Ticket creation.
- Ticket number generation.
- Manual assignment.
- Automatic assignment.
- Reassignment.
- Workload calculation.
- Status transitions.
- Resolution.
- Reopening.
- Closure.
- SLA calculation.
- Overdue calculation.
- Comments.
- Activity logging.
- Dashboard statistics.
- Database transactions.
- Notifications.
- Email job dispatch.
- Cache invalidation.
- Permission checks.

For every important business rule, provide:

- Business requirement.
- Realistic scenario.
- Input data.
- Expected result.
- Actual implementation.
- Code explanation.
- Database state before execution.
- Database state after execution.
- Equivalent SQL.
- Edge cases.
- Error handling.
- Interview questions.
- How to modify the rule.

### docs/07-UI-TO-BACKEND-MAPPING.md

Create a comprehensive UI-to-code reference.

For every important page, button, form, filter and AJAX action, identify:

- Page name.
- UI action.
- Blade file.
- JavaScript file.
- HTTP method.
- URL.
- Route name.
- Middleware.
- Controller.
- Controller method.
- Service method.
- Model.
- Database table.
- Validation.
- Authorization.
- Business logic.
- Notification behavior.
- Queue behavior.
- Cache behavior.
- Response.

Example:

Assign Agent button → Blade form → POST route → TicketController → TicketService → validation → authorization → ticket update → activity record → database notification → transaction commit → cache invalidation → queued email → redirect.

Also explain:

Agent browser → notification polling JavaScript → authenticated GET endpoint → NotificationController → database notifications → JSON response → DOM update → Bootstrap toast.

This document must allow me to locate relevant code immediately during a live interview.

### docs/08-LIVE-CODING-PRACTICE.md

Prepare at least 35 practical interview modification exercises.

Include:

1. Add a phone number to users.
2. Add a reference number to tickets.
3. Add a new ticket priority.
4. Add a new ticket status.
5. Change SLA duration.
6. Add a category field.
7. Add a ticket search condition.
8. Modify date-range filtering.
9. Change pagination size.
10. Add a dashboard statistic.
11. Add a validation rule.
12. Add or modify a user role.
13. Modify ticket permissions.
14. Modify automatic assignment logic.
15. Add a simple CRUD module.
16. Add a ticket report.
17. Add a database index.
18. Modify an Eloquent relationship.
19. Fix an N+1 query.
20. Add a new business rule.
21. Change notification polling interval.
22. Change notification message.
23. Add a notification trigger.
24. Add notification filtering.
25. Change an email subject.
26. Change an email recipient.
27. Add a queued email event.
28. Change queue retry attempts.
29. Change cache TTL.
30. Invalidate a cache key.
31. Debug a pending queue job.
32. Retry a failed job.
33. Add a feature test.
34. Debug HTTP 403.
35. Debug HTTP 419.

For every exercise, provide:

- Interviewer's requirement.
- Difficulty.
- Requirement analysis.
- Exact files to modify.
- Required Artisan commands.
- Existing code.
- Updated code.
- Explanation of each change.
- Database impact.
- How to test the change.
- Common mistakes.

Organize exercises from beginner to intermediate.

Separate questions from solutions so I can attempt them independently.

Do not implement these exercises in the main application.

### docs/09-INTERVIEW-QUESTIONS-AND-ANSWERS.md

Prepare practical interview questions and answers.

PHP fundamentals:

- Variables.
- Arrays.
- Functions.
- Classes and objects.
- Constructors.
- Inheritance.
- Interfaces.
- Traits.
- Namespaces.
- Access modifiers.
- Static methods.
- Closures.
- Arrow functions.
- Null coalescing.
- Exceptions.
- Type declarations.

Laravel fundamentals:

- MVC.
- Routing.
- Controllers.
- Middleware.
- Blade.
- Dependency injection.
- Service container.
- Facades.
- Authentication.
- Authorization.
- Policies.
- Validation.
- Sessions.
- CSRF.
- Route model binding.

Database and Eloquent:

- Migrations.
- Seeders.
- Factories.
- Models.
- Mass assignment.
- Relationships.
- Query Builder.
- Eloquent.
- Eager loading.
- Lazy loading.
- N+1 queries.
- Pagination.
- Transactions.
- Indexes.
- Foreign keys.

System design:

- Monolithic architecture.
- Cache.
- Queue.
- Asynchronous processing.
- Producer-consumer pattern.
- Polling.
- WebSockets.
- Load balancing.
- Horizontal scaling.
- Database optimization.
- Retry.
- Idempotency.
- Data consistency.

Relate answers to actual project implementation.

### docs/10-SYSTEM-DESIGN-GUIDE.md

Explain the actual architecture and reasons behind each decision.

Implemented concepts:

- Monolithic architecture.
- MVC.
- Separation of concerns.
- Database normalization.
- Role-based access control.
- Resource authorization.
- State transitions.
- Workload-based assignment.
- Audit trail.
- ACID transactions.
- Database indexing.
- Eager loading.
- Pagination.
- Cache-aside pattern.
- Cache invalidation.
- Asynchronous email processing.
- Producer-consumer pattern.
- Queue retry.
- Failed job handling.
- AJAX polling.
- JSON API communication.

Include Mermaid diagrams for:

- Overall architecture.
- Ticket creation.
- Ticket assignment.
- Notification polling.
- Queue and email.
- Cache hit and miss.
- Ticket lifecycle.

Explain future improvements conceptually:

- Redis cache.
- Redis queue.
- Multiple queue workers.
- Laravel Reverb.
- WebSockets.
- Server-Sent Events.
- Load balancers.
- Multiple application servers.
- Shared sessions.
- Database read replicas.
- Object storage.
- Scheduled SLA escalation.
- Idempotent processing.
- Transactional outbox.

Do not implement these future improvements.

Clearly distinguish implemented functionality from future proposals.

### docs/11-DEBUGGING-GUIDE.md

Explain debugging using:

- Laravel logs.
- dd().
- dump().
- Log::info().
- Laravel Tinker.
- Browser developer tools.
- Network tab.
- Console tab.
- Artisan route:list.
- Database inspection.
- VS Code and Xdebug where available.
- Queue worker output.
- Failed job records.
- Cache inspection.

Explain common errors:

- Route not found.
- View not found.
- Undefined variable.
- MassAssignmentException.
- ModelNotFoundException.
- SQL errors.
- Foreign key violations.
- Validation errors.
- HTTP 401.
- HTTP 403.
- HTTP 404.
- HTTP 419.
- HTTP 422.
- HTTP 500.
- AJAX request failure.
- Queue job not processing.
- Email delivery failure.
- Stale cache.

Provide practical project examples.

### docs/12-QUICK-REFERENCE.md

Create a concise interview cheat sheet.

Include:

- Directory map.
- Important Artisan commands.
- Important routes.
- Controller methods.
- Service methods.
- Eloquent relationships.
- Common queries.
- Validation syntax.
- Blade syntax.
- Business rules.
- Queue commands.
- Cache commands.
- Notification operations.
- Frequently modified files.
- Debugging steps.

Make it suitable for revision immediately before the interview.

### docs/13-NOTIFICATION-SYSTEM-GUIDE.md

Explain:

- Laravel Notification class.
- Notifiable trait.
- notify().
- via().
- toArray().
- Database notification storage.
- Polymorphic relationships.
- Recipient selection.
- Unread notifications.
- JSON endpoints.
- AJAX.
- Fetch API.
- async and await.
- JSON response.
- Recursive setTimeout().
- DOM manipulation.
- Bootstrap toast.
- Duplicate prevention.
- CSRF.
- Authorization.
- Mark-as-read workflow.
- Polling performance.
- Polling versus SSE versus WebSockets.

Explain actual PHP and JavaScript code line by line.

Show the complete workflow from assignment to automatic notification without page refresh.

### docs/14-QUEUE-AND-EMAIL-GUIDE.md

Explain:

- Why email processing should be asynchronous.
- Synchronous versus asynchronous execution.
- Producer and consumer.
- Database queue.
- Job dispatch.
- ShouldQueue.
- Queueable.
- Job constructor.
- handle().
- afterCommit().
- Serialization.
- jobs table.
- failed_jobs table.
- Queue worker.
- Retry.
- Backoff.
- Mailable.
- Email Blade template.
- SMTP.
- Log mail driver.
- Failed job debugging.
- Worker restart.
- Duplicate delivery limitations.

Show actual generated source code.

Explain what happens when the worker stops and restarts.

### docs/15-CACHING-GUIDE.md

Explain:

- Why caching is used.
- Cache hit.
- Cache miss.
- Cache key.
- TTL.
- Cache-aside pattern.
- Cache::remember().
- Cache::forget().
- Cache invalidation.
- Dashboard caching.
- Category caching.
- Database cache storage.
- Stale data.
- Data consistency.
- Cache limitations.
- Why certain data should not be cached.
- Future Redis integration.

Use actual generated source code.

Show database and cache state before and after relevant operations.

### docs/16-CODEBASE-LEARNING-MAP.md

Create a recommended source-code reading order.

Start with simple functionality.

Recommended sequence:

1. Application structure.
2. Routes.
3. Blade layouts.
4. Category CRUD.
5. Migrations.
6. Eloquent models.
7. Authentication.
8. Middleware.
9. Policies.
10. User management.
11. Ticket creation.
12. Relationships.
13. Assignment.
14. Status transitions.
15. SLA.
16. Activity logging.
17. Comments.
18. Notifications.
19. AJAX polling.
20. Email.
21. Queue.
22. Caching.
23. Dashboard queries.
24. Testing.
25. Debugging.

For each step, explain:

- Files to read.
- Why they exist.
- Concepts demonstrated.
- Related files.
- Required understanding before proceeding.
- A small practical exercise.

### docs/17-DEMO-AND-INTERVIEW-GUIDE.md

Provide:

- Five-minute application demonstration.
- Ten-minute technical explanation.
- Business problem explanation.
- Architecture explanation.
- Database design explanation.
- System design explanation.
- Important trade-offs.
- Common follow-up questions.
- Recommended answers.
- Two-hour emergency preparation plan.
- Four-hour detailed preparation plan.

The explanation must honestly describe the project as AI-assisted development and focus on my understanding of the implementation.

---

## 21. Development phases

Work incrementally.

### Phase 1: Environment inspection

- Inspect project files.
- Verify PHP 8.4.28.
- Verify Composer.
- Verify Laravel.
- Verify extensions.
- Verify database connectivity.
- Identify blockers.
- Create implementation plan.

### Phase 2: Database and models

- Configure MySQL.
- Create migrations.
- Create models.
- Define relationships.
- Configure environment variables.

### Phase 3: Authentication and shared UI

- Implement login and logout.
- Implement role restrictions.
- Implement active user verification.
- Create shared Blade layout.
- Create sidebar and navbar.

### Phase 4: Users and categories

- Implement user management.
- Implement category CRUD.
- Implement validation.
- Implement authorization.
- Verify all related pages.

### Phase 5: Ticket management

- Implement creation.
- Implement listing.
- Implement details.
- Implement editing.
- Implement search and filters.
- Implement pagination.

### Phase 6: Business workflow

- Manual assignment.
- Automatic assignment.
- Reassignment.
- Status transitions.
- SLA.
- Resolution.
- Reopening.
- Closure.
- Comments.
- Activity history.
- Transactions.

### Phase 7: Notifications

- Database notifications.
- Notification endpoints.
- Notification dropdown.
- Notification history.
- AJAX polling.
- Bootstrap toast.
- Authorization verification.

### Phase 8: Queue and email

- Database queue.
- Reusable Job.
- Mailable.
- Email template.
- After-commit dispatch.
- Worker verification.
- Mail log verification.

### Phase 9: Dashboard and caching

- Role-specific dashboards.
- Charts.
- Dashboard caching.
- Category caching.
- Cache invalidation.

### Phase 10: Seeders, tests and UI refinement

- Realistic seed data.
- Feature tests.
- Test execution.
- Bug fixes.
- Responsive layout verification.
- UI consistency.

### Phase 11: Documentation

- Project-specific documentation.
- Business logic explanations.
- UI-to-backend mappings.
- Code explanations.
- Interview exercises.
- System design guide.
- Debugging guide.

### Phase 12: Final review

Review every custom file.

Identify and fix:

- Unnecessary complexity.
- Difficult PHP syntax.
- Unnecessary abstractions.
- Duplicate business logic.
- Complicated queries.
- Large methods.
- Security vulnerabilities.
- Authorization problems.
- Missing validation.
- Incorrect relationships.
- N+1 queries.
- Cache invalidation problems.
- Queue dispatch problems.
- Duplicate notifications.
- Broken UI functionality.
- Documentation mismatches.

Simplify wherever possible without weakening security.

Run relevant tests again.

Update affected documentation.

---

## 22. Execution and progress rules

After every development phase:

1. Explain what was implemented.
2. List files created or modified.
3. Explain important decisions.
4. Run relevant verification commands.
5. Fix detected errors.
6. Update DEVELOPMENT_PROGRESS.md.
7. Record remaining limitations.

Proceed autonomously unless:

- There is a genuine blocker.
- Required software is incompatible.
- Credentials are required.
- An operation would destroy existing data.
- A significant unapproved architecture change is necessary.

Do not stop after generating a plan.

Do not merely provide sample code in the terminal.

Create the actual working project files.

Do not introduce unapproved dependencies.

Do not run migrate:fresh or delete existing databases without explicit confirmation.

If context or execution limits are encountered, maintain accurate progress in DEVELOPMENT_PROGRESS.md.

Ensure another Claude Code session can resume development without losing previous decisions.

### Time and scope priority

This project is being developed for an upcoming interview.

Prioritize:

1. Working authentication and CRUD.
2. Working ticket business workflow.
3. Professional and functional UI.
4. Notifications.
5. Queue and email.
6. Caching.
7. Essential tests.
8. UI-to-backend mapping.
9. Business logic explanations.
10. Live coding exercises.
11. Remaining detailed documentation.

If time is limited, do not sacrifice core functionality, security or code readability for excessive documentation or decorative UI.

Report incomplete deliverables accurately.

---

## 23. Final acceptance criteria

Before declaring completion, verify the following.

### Application

- Application starts.
- Database connection works.
- Migrations work.
- Seeders work.
- All three roles can log in.
- Inactive users are blocked.
- Role-specific navigation works.
- All pages work.
- All forms work.
- Validation works.
- Authorization works.

### Business logic

- Ticket creation.
- Unique ticket numbers.
- Manual assignment.
- Automatic assignment.
- Reassignment.
- Status transitions.
- Resolution.
- Reopening.
- Closure.
- SLA.
- Comments.
- Activity history.

### Notifications

- Correct recipients.
- Unread count.
- Notification dropdown.
- Notification history.
- Mark as read.
- Mark all as read.
- Automatic notifications without page refresh.
- Duplicate toast prevention.
- Unauthorized access prevention.

### Queue and email

- Correct job dispatch.
- After-commit behavior.
- Queue worker processing.
- Mail log output.
- Retry configuration.
- Failed job inspection.
- Ticket operations without a running worker.

### Cache

- Dashboard statistics cached.
- Active categories cached.
- Cache invalidation works.
- Uncached data remains current.
- Authorization does not depend on cache.

### Quality

- No placeholder functionality.
- No unnecessary dependencies.
- No obvious N+1 queries.
- No exposed credentials.
- No unnecessary complexity.
- Tests executed.
- Documentation matches code.

If browser automation is available, verify important workflows using separate administrator, agent and customer sessions.

If browser verification is unavailable, report this clearly and provide manual verification instructions.

Do not claim verification that was not performed.

---

## 24. Final deliverables

Provide:

1. Complete working Laravel application.
2. Professional responsive UI.
3. Database migrations.
4. Eloquent models and relationships.
5. Realistic seeders.
6. Demo credentials.
7. Complete business workflow.
8. Near-real-time in-app notifications.
9. Queued email notifications.
10. Dashboard and category caching.
11. Automated tests.
12. README.md.
13. Complete learning documentation.
14. Architecture diagrams.
15. ER diagram.
16. Ticket lifecycle diagram.
17. UI-to-backend mapping.
18. Business logic explanations.
19. Codebase learning map.
20. Live coding exercises.
21. Interview questions and answers.
22. Debugging guide.
23. System design guide.
24. Application startup commands.
25. Actual test results.
26. Known limitations.
27. Unverified functionality.
28. Recommended learning order.

Provide a concise final development report.

---

## 25. Final priority instruction

This is an interview-oriented learning project.

Do not turn it into a large enterprise helpdesk platform.

The application must demonstrate meaningful business functionality and fundamental system design concepts while remaining simple enough for me to independently modify.

If a feature can be implemented correctly using ten readable lines instead of fifty sophisticated lines, prefer the simpler implementation.

If an architectural abstraction is unnecessary, do not introduce it.

I must be able to understand the complete workflow:

UI → HTTP Request → Route → Middleware → Controller → Validation → Authorization → Business Logic → Eloquent → Database → Notification → Queue → Cache → Response.

Prioritize:

- Correctness.
- Simplicity.
- Security.
- Readability.
- Maintainability.
- Learnability.
- Working functionality.

Begin by inspecting the development environment and project directory.

Read CLAUDE.md and follow its permanent instructions.

Then implement the complete application incrementally.