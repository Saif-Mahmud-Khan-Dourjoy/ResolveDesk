# ResolveDesk V2 — Production-Ready Multi-Project Customer Support System

## 1. Project context and primary objective

ResolveDesk is an existing Laravel-based Customer Support Ticket and Workflow Automation System.

The original application has already been implemented according to MASTER_PROMPT.md.

Your task is to incrementally upgrade the existing application into a production-ready Multi-Project Customer Support and Workflow Automation System suitable for a software company managing multiple software products, client organizations, customer users and support engineers.

This is not merely a CRUD demonstration project. It should solve a realistic business problem and implement essential production engineering practices.

However, code simplicity, readability, maintainability and learnability are mandatory.

I must be able to independently understand, explain, debug and modify every important business operation and technical implementation during a Laravel technical interview and future development.

Avoid unnecessary architectural complexity.

## 2. Mandatory instructions before implementation

Read and understand:

- CLAUDE.md
- MASTER_PROMPT.md
- V2_MASTER_PROMPT.md
- DEVELOPMENT_PROGRESS.md
- README.md
- Existing application source code
- Existing database migrations and seeders
- Existing configuration
- Existing tests
- Existing learning documentation

Inspect the actual implementation.

Do not assume that every feature mentioned in the original specification has already been implemented.

This V2 specification supersedes conflicting V1 requirements. All non-conflicting V1 requirements remain applicable.

Before modifying the application:

1. Inspect the current Git status.
2. Identify existing working features.
3. Identify incomplete features and technical issues.
4. Inspect existing database structure and relationships.
5. Verify the PHP executable and version.
6. Verify Composer's PHP runtime.
7. Verify Laravel version and package compatibility.
8. Verify MySQL connectivity.
9. Run existing tests and record baseline results.
10. Create an incremental implementation plan.
11. Identify database migration and backward compatibility risks.

Use the existing project-specific PHP 8.4 environment.

Do not change the permanent Windows PATH or global PHP configuration.

Do not overwrite unrelated user changes.

Do not rebuild the application from scratch.

Do not unnecessarily replace existing working implementations.

Do not delete existing users, tickets, notifications or other business records.

Never execute migrate:fresh, db:wipe or destructive database commands against existing application data.

Do not expose secrets in logs, documentation or terminal output.

Request approval before performing destructive operations or irreversible data changes.

## 3. Technology stack

Preserve the following stack:

- PHP 8.4
- Laravel 13
- MySQL 8.x
- Laravel Blade
- Bootstrap 5.3
- Vanilla JavaScript
- Native Fetch API
- Eloquent ORM
- Laravel Session Authentication
- Laravel Middleware
- Laravel Policies
- Laravel Notifications
- Laravel Mail
- Laravel Reverb
- Laravel Echo
- Laravel Database Queue
- Laravel Database Cache
- Laravel Scheduler
- Laravel Filesystem
- Chart.js
- PHPUnit
- Git

Use Vite where required for frontend asset management.

Do not introduce React, Vue, Livewire or Inertia.

Do not require Redis for the initial deployment.

Keep queue and cache configuration compatible with a future Redis migration.

Use supported and compatible package versions.

Avoid unnecessary third-party packages when Laravel already provides the required functionality.

## 4. Architecture and coding standards

Use a simple modular monolith based on standard Laravel MVC conventions.

Recommended request flow:

Browser → Route → Middleware → Controller → Validation → Policy → Service when necessary → Eloquent → Database → Response.

Use:

- Resource controllers where appropriate.
- Named routes.
- Route model binding.
- Form Request classes for reusable or complex validation.
- Simple inline validation for small operations where appropriate.
- Eloquent relationships.
- Laravel policies for resource-level authorization.
- Middleware for authentication and broad role restrictions.
- Small service classes for transactional and reusable business operations.
- Laravel Jobs for asynchronous processing.
- Laravel Events where broadcasting or genuine event-driven behavior is needed.
- Laravel Notifications for notification delivery.
- Artisan commands for scheduled operations.

Do not introduce:

- Repository pattern without a demonstrated need.
- Unnecessary interfaces and abstract classes.
- CQRS.
- Event sourcing.
- Domain-driven design frameworks.
- Microservices.
- Custom dependency injection containers.
- Generic workflow engines.
- Complex design patterns merely for demonstration.

Keep controllers understandable.

Do not create excessively large controllers or services.

Avoid duplicating business rules across controllers, JavaScript and services.

Use meaningful variable, method and class names.

Use PHP type declarations where appropriate.

Follow PSR-12 and Laravel conventions.

Use Laravel Pint for formatting.

Add comments explaining important business rules and non-obvious technical decisions.

Do not add meaningless comments to every obvious line.

Provide detailed code explanations in documentation instead.

## 5. Business domain

The software company manages multiple software products or projects.

Examples:

- HRMS
- ERP
- Payroll Management System
- Accounting System
- Inventory Management System

Each project may serve multiple client organizations.

Each client organization may use multiple projects.

Each client organization may have multiple customer users.

Each support engineer may support multiple projects.

A support ticket may belong to:

1. A specific project and client.
2. A specific project without a client, representing an internal issue.
3. A client without a specific project, representing general client support.
4. Neither a project nor a client, representing global internal support.

A global ticket means that the ticket is not associated with a particular project.

A global ticket does not mean publicly accessible.

Maintain logical client data isolation without introducing separate tenant databases or a complex multi-tenant framework.

## 6. User roles and access control

Preserve the existing roles:

- Admin
- Agent
- Customer

### Administrator

Administrators can:

- Manage projects.
- Manage client organizations.
- Manage users.
- Manage categories.
- Manage project-client associations.
- Manage project-agent associations.
- View all tickets.
- Create internal and client-related tickets.
- Assign and reassign tickets.
- Automatically assign tickets.
- Manage priorities and permitted status transitions.
- View organization-wide reports.
- View operational and SLA statistics.

### Support agent

Support agents can:

- View tickets assigned to them.
- View tickets they created, subject to existing authorization rules.
- Create authorized internal tickets.
- Update permitted ticket statuses.
- Add public replies.
- Add internal notes.
- Upload authorized attachments.
- Resolve assigned tickets.
- View their own workload and performance statistics.

Project membership determines assignment eligibility.

Project membership alone must not grant access to all tickets belonging to that project.

### Customer

Customers can:

- Create global support tickets.
- Create tickets for projects associated with their client organization.
- View their own tickets.
- Add public replies.
- Upload authorized attachments.
- Confirm ticket resolution.
- Reopen resolved tickets where permitted.
- View their own notifications.

Customers must not:

- Access other customers' tickets.
- Access another client organization's data.
- View internal notes.
- Assign support agents.
- Manipulate ticket ownership.
- Modify protected system fields.
- Subscribe to another user's private notification channel.

Do not introduce a customer manager role in this version.

Document how client-wide customer management could be added later.

## 7. User management

Preserve and improve existing user management.

Support:

- User creation.
- User editing.
- Search and pagination.
- Role filtering.
- Client organization association.
- Active and inactive status.
- Password reset or secure administrator-managed password replacement.
- Project associations for support agents.

Only customer users may belong to a client organization.

Agents and administrators belong to the software company.

Preserve existing standalone customers without a client organization.

Standalone customers may create global tickets only.

Prevent accidental deactivation or demotion of the last active administrator.

Prevent administrators from accidentally disabling their own account.

Prevent agent deactivation while unresolved or reopenable tickets remain assigned unless those tickets are appropriately reassigned.

Do not permanently delete users referenced by historical business records.

Ensure inactive users cannot continue accessing protected resources through an existing authenticated session.

Restrict changing a customer's organization when existing tickets or access rules would become inconsistent.

Do not allow a user update to silently transfer historical ticket ownership.

## 8. Project management

Create a Project model and projects table.

Suggested fields:

- id
- name
- code
- description
- is_active
- created_at
- updated_at

Project code must be unique.

Implement:

- Project list.
- Create project.
- Edit project.
- Project details.
- Activate and deactivate.
- Search.
- Pagination.
- Associated client organizations.
- Associated support agents.
- Project ticket list.
- Project ticket statistics.

Project details should provide:

- Overview.
- Clients.
- Support agents.
- Tickets.
- SLA statistics.

Inactive projects must not be selectable for new tickets.

Existing tickets must remain accessible to authorized users.

Existing tickets should remain resolvable even if their project becomes inactive.

Do not permanently delete projects referenced by historical records.

## 9. Client organization management

Create a Client model and clients table.

Suggested fields:

- id
- name
- code
- email
- phone
- address
- description
- is_active
- created_at
- updated_at

Client code must be unique.

Implement:

- Client list.
- Create client.
- Edit client.
- Client details.
- Activate and deactivate.
- Search.
- Pagination.
- Associated projects.
- Associated customer users.
- Client ticket list.
- Client ticket statistics.

Add a nullable client_id foreign key to users.

Inactive clients must not be selectable for new client-related tickets.

Customers belonging to inactive clients must not create new tickets.

Authorized historical access and existing ticket resolution should remain available according to documented business rules.

Do not permanently delete clients referenced by business records.

## 10. Project and client relationships

Create the following pivot tables:

### client_project

- client_id
- project_id
- timestamps if useful

### project_user

- project_id
- user_id
- timestamps if useful

Add:

- Foreign key constraints.
- Composite unique constraints.
- Appropriate indexes for queries in both relationship directions.

Implement Eloquent relationships using:

- belongsTo()
- hasMany()
- belongsToMany()

Support assigning and removing project-client and project-agent associations through simple administrator UI controls.

Use understandable checkbox lists or searchable selection controls.

Only active support agents may be newly associated with projects.

Prevent removing project-agent associations when unresolved or reopenable tickets depend on them unless the tickets are reassigned appropriately.

Prevent removing client-project associations while unresolved or reopenable tickets depend on them.

Historical closed tickets must remain intact.

Use database transactions for association changes where required.

Document the concurrency limitations of simultaneous association changes and ticket creation or assignment, and protect relevant invariants using consistent locking where necessary.

Do not create a separate support team management module.

## 11. Ticket database design

Extend the existing tickets table incrementally.

Suggested additional fields:

- project_id: nullable foreign key
- client_id: nullable foreign key
- version: unsigned integer, default 1
- sla_notified_at: nullable timestamp
- request_token: nullable or otherwise suitable idempotency identifier

Preserve existing fields and business records.

Expected ticket information includes:

- Unique ticket number.
- Subject.
- Description.
- Category.
- Project.
- Client.
- Created by.
- Assigned agent.
- Priority.
- Status.
- SLA deadline.
- Resolution note.
- Resolved timestamp.
- Version.
- Creation and update timestamps.

Use database-generated identifiers and unique constraints.

Do not generate ticket numbers using MAX(id) + 1.

Do not add a redundant is_global column.

A null project_id represents a global ticket.

Use appropriate foreign key deletion behavior to preserve historical records.

Do not use cascading deletes that silently remove important ticket history.

Preserve existing tickets as global tickets unless reliable existing data supports a specific project or client association.

If an existing customer already has a reliable organization association, preserve it appropriately.

Do not invent historical client or project mappings.

Document migration and rollback implications.

## 12. Ticket creation and grouping

Extend the ticket creation UI with:

- Ticket scope.
- Client organization where applicable.
- Project where applicable.
- Category.
- Subject.
- Description.
- Priority.
- Optional attachments.

### Customer ticket creation

Automatically derive client_id from the authenticated customer.

Never trust client_id submitted by a customer.

Show only active projects associated with that customer’s client organization.

Allow global tickets.

Standalone customers may create global tickets only.

### Administrator ticket creation

Allow:

- Global internal tickets.
- Global client-related tickets.
- Project-specific internal tickets.
- Project-specific client-related tickets.

When a client is selected, show only projects associated with that client.

Record the authenticated administrator as the ticket creator.

Do not impersonate a customer user.

### Agent ticket creation

Allow internal global tickets.

Allow internal project-specific tickets only for projects the agent supports.

Do not allow agents to create tickets on behalf of arbitrary client users.

### Backend validation

Validate:

- Client existence and active status.
- Project existence and active status.
- Client-project relationship.
- Customer ownership.
- Agent project membership.
- Category validity.
- Priority.
- Required fields.
- Attachment restrictions.

Do not rely on frontend dropdown filtering for security.

Use authenticated and authorized Fetch API endpoints for dependent dropdowns.

Keep ticket project and client scope immutable after creation in this version.

Do not introduce complex cross-project ticket transfers.

## 13. Ticket lifecycle

Preserve the existing ticket lifecycle unless an actual inconsistency requires correction.

Expected lifecycle:

Open → Assigned → In Progress → Resolved → Closed.

Resolved → In Progress is allowed when an authorized user reopens a ticket.

Maintain a centralized and understandable set of allowed transitions.

Do not allow arbitrary status changes through mass assignment.

Business rules:

- New tickets start as Open.
- Assignment changes Open to Assigned.
- Reassignment of Assigned or In Progress tickets preserves the appropriate status.
- An assigned agent can start work.
- Resolution requires a resolution note.
- Resolution records resolved_at.
- Customers can confirm resolution for their own tickets.
- Reopening clears the current resolved_at while preserving historical resolution activity.
- Reopening must validate the assigned agent's current eligibility.
- If the previous agent is no longer eligible, require a valid reassignment or use a clearly documented safe fallback.
- Closed tickets are read-only.
- Invalid and duplicate transitions must be rejected or treated as documented idempotent no-ops.
- Do not generate duplicate notifications or activities for unchanged values.

Keep existing business rules consistent across UI, backend and documentation.

## 14. Ticket assignment and workload distribution

Implement both manual and automatic assignment.

### Manual assignment

For project-specific tickets:

- Only active agents associated with that project are eligible.

For global tickets:

- All active support agents are eligible.

Validate eligibility on the server at the time of assignment.

Do not trust an agent ID submitted by the frontend.

### Automatic assignment

Use a simple least-workload algorithm.

Steps:

1. Identify eligible active agents.
2. Count each agent's active tickets.
3. Include Assigned and In Progress tickets.
4. Calculate workload across all projects.
5. Select the agent with the lowest workload.
6. Break ties deterministically using user ID.
7. Assign the ticket.
8. Record activity.
9. Create persistent notifications.
10. Schedule external notification delivery after commit.

Use readable Eloquent queries such as whereHas() and withCount().

Do not introduce machine learning or complex assignment scoring.

If no eligible agent exists, leave the ticket unchanged and display a meaningful message.

Explain that this is workload-based task distribution, not infrastructure-level load balancing.

## 15. Comments and internal notes

Extend existing ticket comments.

Support two comment types:

- Public reply.
- Internal note.

Add a simple visibility field or equivalent clear representation.

Public replies are visible to authorized ticket participants.

Internal notes are visible only to authorized administrators and support agents.

Customers must never receive internal notes through:

- Blade templates.
- JSON endpoints.
- Email.
- Notifications.
- Reports.
- Broadcast events.
- Attachment downloads.

Only authorized ticket participants may add comments.

Closed tickets must remain read-only.

Use chronological display.

Show author, role, timestamp and comment type where appropriate.

Escape user-generated content.

Do not render arbitrary customer-provided HTML.

Record relevant activity.

## 16. Ticket attachments

Implement simple and secure attachment management.

Suggested table:

ticket_attachments

Fields:

- id
- ticket_id
- ticket_comment_id: nullable
- uploaded_by
- original_name
- storage_path
- mime_type
- size
- timestamps

Support attachments during ticket creation and authorized comments.

Allow common business file types such as:

- PDF.
- PNG.
- JPEG.
- Plain text.
- Selected office document formats if required.

Set a configurable maximum file size, initially 5 MB per file.

Set a reasonable maximum number of attachments per request.

Validate extension, MIME type and file size on the server.

Use generated storage filenames.

Store attachments on a private Laravel filesystem disk.

Do not store customer attachments in a publicly accessible directory.

Provide an authenticated download controller with ticket-level authorization.

Internal-note attachments must inherit internal-note visibility.

Do not execute uploaded files.

Use safe download headers.

Handle database and filesystem failures appropriately.

Avoid orphaned files when a database operation fails.

Document that filesystem operations are not automatically rolled back by database transactions.

Do not implement antivirus infrastructure in this version, but document it as a production hardening consideration.

Keep storage compatible with a future S3 migration.

## 17. Activity history and audit trail

Preserve and extend ticket_activities.

Record important operations:

- Ticket creation.
- Assignment.
- Reassignment.
- Status change.
- Priority change.
- Resolution.
- Reopening.
- Closure.
- Relevant comment and attachment operations.
- SLA breach notification.

Record:

- Ticket ID.
- Acting user where applicable.
- Action.
- Previous value.
- New value.
- Timestamp.

Use JSON for structured old and new values where appropriate.

System-generated activities must be distinguishable from user actions.

Do not expose internal-only activity details to customers.

Activity records should not be editable through normal application operations.

Store activity history transactionally with corresponding ticket mutations.

Do not log passwords, tokens, SMTP credentials or unnecessary sensitive information.

Keep ticket activity history separate from technical application logs.

## 18. Database transactions and consistency

Use DB::transaction() for operations involving multiple dependent database changes.

Examples:

- Ticket creation and activity creation.
- Assignment and activity creation.
- Status transition and notification creation.
- Resolution.
- Reopening.
- SLA notification deduplication.

Persistent database notifications should be created consistently with their corresponding business operations.

If a transaction fails, related database changes must roll back.

Do not perform SMTP calls, external HTTP requests or WebSocket network operations inside retried database transactions.

Dispatch external side effects only after successful commit.

Use Laravel's supported after-commit mechanisms.

Explain ACID properties using actual ResolveDesk operations.

Keep transactions short.

Do not wrap unrelated read-only operations in transactions unnecessarily.

## 19. Concurrency control

Implement practical concurrency protection without introducing distributed infrastructure.

### 19.1 Pessimistic locking

Use lockForUpdate() within database transactions for operations requiring current-state validation.

Examples:

- Initial assignment.
- Reassignment.
- Automatic assignment.
- Status transition.
- Resolution.
- Reopening.
- Closure.
- SLA breach processing.

Reload the ticket inside the transaction.

Do not rely on stale route-bound model state.

Validate authorization and relevant business conditions against the current state.

Ensure duplicate operations cannot generate duplicate activities or notifications.

Use MySQL InnoDB row-level locking.

Explain:

- Race conditions.
- Lost updates.
- Row locks.
- Transaction isolation.
- Lock waiting.
- Commit and rollback.

### 19.2 Optimistic locking

Add a version field to tickets.

Include the current version in relevant edit and mutation forms.

For general ticket editing, perform an atomic conditional update using the expected version.

Increment version after successful ticket mutations.

Ensure assignment and status changes also increment version.

Reject stale requests with HTTP 409 or an equivalent clearly documented conflict response.

Display a user-friendly conflict message.

Do not silently overwrite another user's changes.

For operations performed under row locking, validate the submitted version where stale user intent could cause an unintended overwrite.

Record activity consistently with the successful update.

Explain optimistic locking versus pessimistic locking using actual code.

### 19.3 Atomic cache locking

Use Laravel Cache::lock() to serialize concurrent automatic assignment operations.

Use a global assignment lock initially because agents may support multiple projects.

Use the existing database cache store and cache_locks table.

Acquire locks in a consistent order.

Acquire the assignment coordination lock before acquiring relevant database row locks.

Calculate workload inside the protected operation.

Use bounded lock acquisition timeouts.

Handle lock timeout with a meaningful response.

Keep the protected operation short.

Do not perform network operations while holding the lock.

Ensure the lock expiry is appropriate for the protected operation.

Document that a time-limited cache lock can expire and is not a substitute for database invariants.

Document which operations participate in workload coordination.

Do not claim perfect workload consistency if manual assignment or other workload-changing operations do not use the same synchronization strategy.

### 19.4 Deadlock handling

Use consistent lock ordering.

Use short transactions.

Use bounded transaction retries where appropriate.

Avoid unnecessary locks.

Do not retry non-transactional external side effects.

Explain deadlock scenarios and prevention.

### 19.5 Duplicate request protection

Prevent accidental repeated form submission in the UI.

Implement server-side idempotency for ticket creation using a simple request token and database unique constraint.

Scope the token appropriately to the authenticated creator.

Ensure concurrent duplicate submissions cannot create two tickets.

If the same token is reused with conflicting input, reject it or handle it according to a clearly documented policy.

Do not create duplicate activities, attachments or notifications when replaying a successful request.

Do not claim that disabling a button provides server-side idempotency.

Use database uniqueness as the final protection against duplicate records.

## 20. Notification system

Preserve the existing notification infrastructure.

Use Laravel's built-in Notifiable trait and database notifications.

Support:

- Notification bell.
- Unread count.
- Recent notification dropdown.
- Dedicated notification history.
- Read and unread filtering.
- Mark individual notification as read.
- Mark all as read.
- Authorized ticket navigation.
- Real-time toast messages.

Notification triggers should include:

- Ticket assignment.
- Ticket reassignment.
- Customer reply.
- Agent public reply.
- Ticket resolution.
- Ticket reopening.
- Ticket closure.
- SLA breach.

Notify the appropriate recipient only.

Avoid unnecessary self-notifications.

Avoid duplicate notifications when no meaningful state change occurred.

Notify the previous agent when a ticket is reassigned away from them, without exposing unauthorized ticket details.

Internal notes must never generate customer-facing notifications.

Notifications must remain stored even if real-time delivery or email delivery fails.

Only the authenticated recipient may view or modify their notifications.

Validate ticket authorization again when opening a notification link.

## 21. True real-time notifications

Implement Laravel Reverb and Laravel Echo.

Use:

- Laravel Broadcasting.
- Laravel Reverb.
- Laravel Echo.
- Pusher-compatible JavaScript client.
- Private user-specific channels.
- Existing Bootstrap UI.

Do not require a paid external broadcasting provider.

Do not require Redis for the initial single-server setup.

Inspect existing broadcasting and frontend configuration before making changes.

Use Laravel's supported installation and configuration process.

Use one simple reusable broadcast event where appropriate.

Broadcast only after successful database commit.

Use a private channel scoped to the authenticated notification recipient.

Example concept:

users.{userId}

Only the matching authenticated user may subscribe.

Do not broadcast sensitive ticket descriptions or internal notes.

Broadcast a notification ID or minimal update signal.

When a real-time event arrives:

1. Fetch current authorized notification data.
2. Update unread count.
3. Update the dropdown.
4. Show a toast for genuinely new notifications.
5. Do not reload or refresh the page.

Reuse existing notification rendering code.

Deduplicate repeated broadcast events and toasts.

Handle:

- Initial connection.
- Connection failure.
- Disconnection.
- Reconnection.
- Missed events.
- Multiple browser tabs.

Preserve AJAX polling as a fallback when the WebSocket connection is unavailable.

Use a reasonable fallback polling interval.

Stop continuous polling when the WebSocket connection is healthy.

Synchronize notification data after reconnection.

Do not rely on WebSockets as the durable source of truth.

The database remains the durable notification source.

Configure production allowed origins, HTTPS/WSS and private channel authorization appropriately.

Explain the difference between polling, long polling, WebSockets and server-sent events.

Explain why WebSockets were selected for this project.

## 22. Email notifications and queue processing

Implement email notifications using Laravel Mail or Laravel Notifications.

Use the database queue initially.

Do not require Redis or RabbitMQ.

Email notifications should be asynchronous.

The main HTTP request must not wait for SMTP delivery.

Use queued jobs or queued notifications.

Dispatch jobs after successful database commit.

Support:

- Assignment email.
- Reassignment email where appropriate.
- Customer reply email.
- Resolution email.
- SLA breach email.

Avoid unnecessary email for every minor action.

Use simple, professional email templates.

Include:

- Ticket number.
- Subject.
- Relevant status.
- Project name where authorized.
- Safe link to the application.

Do not include confidential internal notes.

Configure:

- Queue connection.
- Worker timeout.
- Retry attempts.
- Retry backoff.
- Failed jobs.
- Appropriate retry_after configuration.

Ensure worker timeout is shorter than retry_after.

Document how to inspect and retry failed jobs.

Handle email delivery failures without rolling back successful ticket operations.

Implement practical duplicate-delivery protection where possible.

Explain that queue processing is generally at-least-once and that SMTP cannot guarantee exactly-once delivery.

Do not claim that retries eliminate every possible duplicate email.

Use MAIL_MAILER=log for local development when SMTP is unavailable.

Use environment variables for production SMTP credentials.

Document the remaining reliability gap between database commit and external job dispatch.

Do not introduce a complex transactional outbox unless explicitly approved later.

## 23. SLA management and scheduling

Preserve existing SLA rules unless a documented business requirement requires a change.

Default SLA targets:

- Critical: 2 hours.
- High: 8 hours.
- Medium: 24 hours.
- Low: 48 hours.

Use calendar hours initially.

Calculate the deadline from ticket creation according to the existing priority rules.

If priority changes, recalculate the deadline consistently using the original creation timestamp.

Document the effect of reopening on the SLA deadline.

Do not silently reset an overdue ticket's original SLA.

Display:

- SLA deadline.
- Remaining time.
- Overdue status.
- Resolved within SLA.
- Resolved after SLA.

Create an Artisan command to check overdue active tickets.

Schedule it every minute for demonstration and initial deployment.

Use withoutOverlapping().

Use onOneServer() only if a supported shared cache configuration is needed for multiple scheduler instances.

Process records in manageable chunks.

Notify the assigned agent for overdue assigned tickets.

Notify relevant administrators for unassigned overdue tickets.

Use sla_notified_at or an equivalent mechanism to prevent repeated notifications for the same breach.

Protect the check-and-mark operation with transactions and row locking.

Define reminder reset behavior when priority changes or a ticket is reopened.

Reuse the notification and queue infrastructure.

Do not implement complicated business-hour calendars or escalation hierarchies.

Store timestamps consistently, preferably in UTC, and display them in the configured application timezone.

## 24. Caching

Use Laravel's cache abstraction.

Use database cache initially.

Cache only data where there is a reasonable benefit.

Suitable examples:

- Dashboard summary statistics.
- Frequently accessed active category lists.
- Stable project and client lookup data where appropriate.

Do not cache:

- Authorization decisions.
- Current ticket status used for mutations.
- Agent eligibility used for assignment.
- Current workload used for concurrency-sensitive assignment.
- Sensitive data under shared or insufficiently scoped cache keys.

Use cache-aside behavior through Cache::remember() where appropriate.

Use reasonable TTL values.

Use user-, role- or filter-specific cache keys where required.

Prevent cross-client cache data leakage.

Invalidate relevant cache entries after successful database commit.

Ensure cache invalidation occurs when underlying project, client, category or ticket data changes.

Do not use cache tags if the selected cache driver does not support them.

Do not indiscriminately clear the entire application cache.

Keep caching logic simple and documented.

Explain:

- Cache hit.
- Cache miss.
- TTL.
- Cache invalidation.
- Stale data.
- Cache-aside.
- Atomic locks.
- Database cache versus Redis.

Document cache consistency limitations and future Redis migration.

## 25. Dashboard and reporting

Extend the existing dashboard.

### Administrator dashboard

Display:

- Total tickets.
- Open tickets.
- Assigned tickets.
- In Progress tickets.
- Resolved tickets.
- Closed tickets.
- Overdue tickets.
- Active projects.
- Active clients.
- Active agents.
- Global ticket count.
- Project-specific ticket count.
- Tickets by project.
- Tickets by client.
- Tickets by priority.
- Agent workload.
- Average resolution time.
- SLA compliance percentage.
- Recent activity.

### Agent dashboard

Display:

- My assigned tickets.
- My active tickets.
- My overdue tickets.
- My resolved tickets.
- My workload.
- Recent authorized activity.

### Customer dashboard

Display:

- My tickets.
- My open tickets.
- My resolved tickets.
- My overdue tickets.
- My recent notifications.

All statistics must be based on actual authorized database records.

Do not use hardcoded demonstration statistics.

Define reporting formulas clearly.

Handle zero denominators safely.

Use consistent date-range semantics.

Use Chart.js for useful charts.

Support filtering by:

- Project.
- Client.
- Global scope.
- Agent.
- Status.
- Priority.
- Category.
- Date range.

Use eager loading and pagination.

Implement a simple CSV export for authorized ticket reports.

Apply the same authorization and filters to exports.

Prevent CSV formula injection from user-generated values.

Stream or chunk large exports where appropriate.

Do not implement a generic report builder.

## 26. Database performance and optimization

Inspect existing queries.

Prevent N+1 queries using:

- with().
- load().
- withCount().
- Appropriate relationship queries.

Use pagination for large listings.

Use database aggregation instead of loading all records into PHP.

Select necessary columns where appropriate.

Add indexes based on actual query patterns.

Consider indexes for:

- Ticket number.
- Project and status.
- Client and status.
- Assigned agent and status.
- Created by and creation date.
- Status and SLA deadline.
- Ticket comments by ticket and creation date.
- Pivot-table lookup directions.
- Notification lookup patterns.

Inspect existing indexes before creating additional indexes.

Avoid redundant or unnecessary indexes.

Use EXPLAIN on important MySQL queries where practical.

Document why each important index exists.

Explain the trade-off between faster reads and additional write/storage overhead.

Use chunkById() or similar appropriate methods for scheduled batch processing.

Do not load entire large tables into memory.

## 27. Security requirements

Apply essential production security practices.

### Authentication

- Secure password hashing.
- Session regeneration after login.
- Session invalidation on logout.
- Login rate limiting.
- Active account verification.
- Secure password reset where supported.

### Authorization

- Middleware for broad access restrictions.
- Policies for resource-level authorization.
- Server-side authorization for every protected operation.
- Consistent authorization for AJAX endpoints.
- Private broadcast channel authorization.
- Attachment download authorization.
- Report export authorization.

### Input and output security

- CSRF protection.
- Server-side validation.
- Mass assignment protection.
- Explicitly allowed mutable fields.
- Parameterized queries.
- Escaped Blade output.
- Safe DOM rendering for notification content.
- Secure file upload validation.
- Prevention of IDOR.
- Prevention of cross-client data leakage.
- Prevention of unauthorized field modification.

Do not use untrusted input directly in raw SQL, dynamic sorting expressions or HTML.

Whitelist permitted sorting columns.

### Production configuration

- APP_DEBUG=false.
- HTTPS.
- Secure session cookies.
- Appropriate SameSite and HttpOnly cookie settings.
- Correct trusted proxy configuration where applicable.
- Restricted filesystem permissions.
- Protected environment files.
- Non-root application processes.
- Least-privilege database accounts.
- Production-safe CORS and WebSocket origin configuration.

Do not expose:

- .env files.
- Application logs.
- Private attachments.
- Database backups.
- Debug endpoints.
- Stack traces.
- Internal infrastructure credentials.

Use Laravel's supported security mechanisms.

Review Composer and npm dependency security advisories.

Do not automatically perform breaking dependency upgrades.

Document unresolved security findings.

## 28. Rate limiting

Implement practical rate limiting for sensitive or frequently accessed endpoints.

Examples:

- Login.
- Password reset.
- Ticket creation.
- Comment creation.
- Attachment upload.
- Notification polling.
- Expensive report export.

Use Laravel's built-in rate limiter.

Choose reasonable limits and document them.

Prefer authenticated user-based limits for authenticated operations.

Use IP-based limits where appropriate for unauthenticated operations.

Return meaningful HTTP 429 responses.

Do not introduce excessive restrictions that make normal support operations unusable.

## 29. Error handling and logging

Use Laravel's standard exception handling.

Handle expected failures gracefully.

Examples:

- Invalid project-client association.
- No eligible support agent.
- Unauthorized ticket access.
- Stale version conflict.
- Invalid status transition.
- Duplicate request.
- Lock acquisition timeout.
- File upload failure.
- Queue failure.
- WebSocket disconnection.

Use appropriate HTTP status codes.

Provide user-friendly validation and error messages.

Do not expose raw database errors to production users.

Use Laravel logging for technical failures.

Log useful contextual information:

- Request identifier.
- Authenticated user ID where appropriate.
- Ticket ID.
- Operation.
- Exception category.
- Relevant non-sensitive diagnostic information.

Use daily or another suitable rotating log configuration.

Avoid logging every successful database query in production.

Do not log passwords, session cookies, authorization tokens or unnecessary customer content.

Explain the difference between business activity history and technical logs.

## 30. UI and UX requirements

Preserve the existing visual design.

Do not unnecessarily redesign working pages.

Maintain a professional, responsive Bootstrap-based interface.

Suggested sidebar:

- Dashboard.
- Projects.
- Clients.
- Tickets.
- Users.
- Categories.
- Notifications.
- Reports.

Show menu items according to role.

Implement or extend:

- Project list and details.
- Client list and details.
- Project-client association management.
- Project-agent association management.
- Ticket creation.
- Ticket listing.
- Ticket details.
- Ticket edit.
- Comments.
- Internal notes.
- Attachments.
- Assignment controls.
- Status controls.
- SLA indicators.
- Notification dropdown.
- Notification history.
- Reports.

Use:

- Clear labels.
- Consistent status badges.
- Validation feedback.
- Empty states.
- Loading indicators for asynchronous operations.
- Confirmation dialogs for significant actions.
- Disabled submit buttons during active requests.
- Accessible forms.
- Responsive tables.

Do not create non-functional placeholder buttons.

Do not hide backend authorization failures through frontend-only restrictions.

Ensure every visible business action has a working backend implementation.

## 31. Automated testing

Preserve existing tests.

Add focused feature and unit tests where appropriate.

Use an isolated testing database.

Never execute destructive test migrations against development or production databases.

Test at minimum:

### Authentication and authorization

- Successful login.
- Invalid login.
- Inactive user restrictions.
- Unauthenticated access.
- Unauthorized role access.
- Customer ownership.
- Cross-client access rejection.
- Agent ticket restrictions.
- Private channel authorization.

### Business functionality

- Project creation and update.
- Client creation and update.
- Project-client association.
- Project-agent association.
- Valid global ticket creation.
- Valid project-specific ticket creation.
- Invalid client-project combination.
- Customer project restrictions.
- Manual assignment.
- Automatic assignment.
- No eligible agent.
- Invalid agent assignment.
- Ticket lifecycle.
- Resolution note requirement.
- Reopening.
- Closed ticket restrictions.
- Public comments.
- Internal note visibility.
- Attachment validation.
- Unauthorized attachment download.

### Concurrency and consistency

- Duplicate assignment prevention.
- Stale version conflict.
- Successful version update.
- Version increment across mutations.
- Duplicate ticket creation token.
- Transaction rollback.
- Activity consistency.
- Notification deduplication.
- SLA reminder deduplication.

### Infrastructure behavior

- Queued email dispatch.
- After-commit dispatch behavior.
- Failed delivery handling.
- Persistent database notifications.
- Broadcast authorization.
- Cache invalidation.
- Rate limiting.
- Scheduled SLA processing.

Use MySQL for actual concurrency and row-locking verification.

Do not claim SQLite tests prove MySQL locking behavior.

Do not claim sequential requests prove concurrent execution safety.

Where practical, provide a reproducible concurrency test using independent database connections or concurrent HTTP processes.

Verify real-time notifications using separate authenticated browser sessions.

Test WebSocket disconnection and fallback behavior.

Clearly report which behaviors were actually tested and which remain unverified.

## 32. Production deployment

Prepare the application for a standard single-server Linux deployment.

Recommended deployment architecture:

Browser → Nginx → PHP-FPM → Laravel → MySQL.

Additional processes:

- Laravel queue worker.
- Laravel Reverb.
- Laravel scheduler.

Do not deploy using php artisan serve in production.

Create deployment documentation for a Linux server using Nginx and PHP-FPM.

Document:

- Required PHP version and extensions.
- Composer installation.
- Node.js and frontend build.
- MySQL database setup.
- Least-privilege database user.
- Environment configuration.
- Application key management.
- File and directory permissions.
- Private attachment storage.
- Nginx document root pointing to public/.
- HTTPS configuration.
- WebSocket reverse proxy.
- Queue worker configuration using Supervisor or systemd.
- Reverb process configuration.
- Scheduler cron configuration.
- Log rotation.
- Application health endpoint.
- Deployment verification.
- Rollback considerations.

Provide example configuration files using placeholders rather than actual credentials.

Use production commands where appropriate:

- composer install --no-dev --optimize-autoloader
- npm ci
- npm run build
- php artisan migrate --force
- php artisan config:cache
- php artisan route:cache
- php artisan view:cache
- php artisan queue:restart
- Reverb restart using its supported mechanism

Verify command compatibility with the actual project.

Do not regenerate APP_KEY during routine deployment.

Document safe migration practices.

Do not claim zero-downtime deployment unless it is actually implemented and verified.

Do not introduce Docker or Kubernetes as mandatory deployment requirements.

## 33. Monitoring and operational health

Implement minimum operational visibility.

Use Laravel's existing health route where appropriate.

Document the difference between:

- Application liveness.
- Database readiness.
- Queue worker health.
- Scheduler health.
- Reverb availability.

Provide a simple readiness check if needed.

Do not expose detailed infrastructure diagnostics through public health endpoints.

Document how administrators can inspect:

- Application logs.
- Failed jobs.
- Queue backlog.
- Database connectivity.
- Reverb process status.
- Scheduler execution.
- Disk usage.
- Attachment storage usage.

Use process supervision with automatic restart for long-running production processes.

Do not introduce a complete Prometheus, Grafana or centralized logging stack into the application itself.

Document those tools as future infrastructure integrations.

## 34. Backup and recovery

Create a practical backup and recovery guide.

Include:

- MySQL database backup.
- Private attachment storage backup.
- Secure configuration backup.
- APP_KEY preservation.
- Backup retention.
- Restricted backup access.
- Restore procedures.
- Restore verification.

Explain that database backups alone are insufficient when tickets contain uploaded files.

Recommend a coordinated backup strategy for database records and attachments.

Do not store backups inside the public web directory.

Do not commit backups or secrets to Git.

Do not claim disaster recovery is verified without performing an actual restore test.

## 35. Development and learning documentation

Documentation is a mandatory deliverable.

Preserve and update all existing learning guides.

Do not leave V1 documentation inconsistent with V2 implementation.

Inspect existing documentation filenames before creating new files.

Reuse and update existing documents where appropriate.

Ensure the documentation covers:

1. Project overview.
2. Installation and environment setup.
3. Database design and ER diagram.
4. Laravel MVC and request lifecycle.
5. Authentication and authorization.
6. Project and client management.
7. Eloquent relationships.
8. Ticket creation and grouping.
9. Ticket lifecycle.
10. Manual and automatic assignment.
11. Comments and internal notes.
12. Secure attachments.
13. Database transactions.
14. Concurrency control.
15. Idempotency.
16. Notification system.
17. WebSockets, Reverb and Echo.
18. Queue and email.
19. Caching.
20. SLA and scheduler.
21. Database optimization.
22. Security.
23. Error handling and logging.
24. Automated testing.
25. Production deployment.
26. Backup and recovery.
27. Debugging.
28. System design.
29. Live coding practice.
30. Interview questions and answers.

Use clear English.

Use Bangla explanations for difficult programming, database and architectural concepts where helpful.

Explain actual implemented code, not generic Laravel examples unrelated to the application.

## 36. Mandatory UI-to-code explanation format

For every important business operation, provide the following explanation:

1. What business problem does this feature solve?
2. Which user role can perform the operation?
3. Which UI page contains the feature?
4. Which button or form triggers the operation?
5. Which Blade file renders the UI?
6. Which JavaScript file or function handles the action?
7. What HTTP request is sent?
8. What URL and HTTP method are used?
9. Which route receives the request?
10. Which middleware executes?
11. Which controller method executes?
12. How is input validated?
13. How is authorization checked?
14. Which service method executes, if applicable?
15. What is the business logic?
16. Which Eloquent methods are used?
17. What equivalent SQL is executed?
18. Which database tables are affected?
19. What is the database state before the operation?
20. What is the database state after the operation?
21. Is a transaction used?
22. Is a lock used?
23. Is caching involved?
24. Is a notification created?
25. Is a job dispatched?
26. Is a WebSocket event broadcast?
27. What response is returned?
28. How does the UI update?
29. What can go wrong?
30. How can the operation be debugged or modified?

Use realistic sample data.

Explain important code line by line or logical block by logical block.

Explain why each important implementation decision was made.

Do not merely describe what a method does.

Explain how Laravel internally processes the operation where relevant.

## 37. System design documentation

Create or update a SYSTEM-DESIGN-GUIDE.md.

Include:

- Business requirements.
- Functional requirements.
- Non-functional requirements.
- High-level architecture.
- Database ER diagram.
- Request lifecycle.
- Ticket lifecycle.
- Assignment sequence diagram.
- Notification sequence diagram.
- Queue processing flow.
- Cache flow.
- Concurrency sequence diagrams.
- Security boundaries.
- Deployment architecture.
- Failure scenarios.
- Scaling considerations.

Use Mermaid diagrams where appropriate.

Explain the following concepts using actual project implementation:

- Modular monolith.
- MVC.
- RBAC.
- Resource-level authorization.
- Logical client data isolation.
- ACID.
- Race conditions.
- Pessimistic locking.
- Optimistic locking.
- Atomic locking.
- Deadlocks.
- Idempotency.
- Producer-consumer pattern.
- Asynchronous processing.
- Event-driven communication.
- WebSockets.
- Cache-aside.
- Database indexing.
- N+1 queries.
- Rate limiting.
- Graceful degradation.
- Retry behavior.
- Failure recovery.

Clearly distinguish implemented features from future architectural possibilities.

Explain how the application could scale to:

- Redis cache and queue.
- Multiple Laravel application servers.
- Load balancer.
- Shared object storage.
- Multiple queue workers.
- Reverb horizontal scaling.
- Shared session storage.
- Database read replicas.
- Centralized monitoring.

Do not implement these future infrastructure components unless required and approved.

## 38. Live coding and interview preparation

Create or update LIVE-CODING-PRACTICE.md.

Provide at least 30 meaningful exercises based on the actual application.

Include beginner, intermediate and advanced exercises.

Examples:

1. Add a phone number to users.
2. Add a project reference field.
3. Add a new ticket priority.
4. Add a new ticket filter.
5. Change pagination size.
6. Add project-wise ticket counts.
7. Add client-wise dashboard statistics.
8. Add a category description.
9. Change SLA targets.
10. Modify the assignment algorithm.
11. Add an agent availability flag.
12. Restrict assignment to available agents.
13. Add a new notification trigger.
14. Modify an email template.
15. Add an unread notification filter.
16. Add a new report column.
17. Add a date-range filter.
18. Prevent an unauthorized ticket action.
19. Add a new validation rule.
20. Identify and fix an N+1 query.
21. Add a database index.
22. Modify a cache TTL.
23. Invalidate a specific cache key.
24. Change queue retry behavior.
25. Add a scheduled reminder.
26. Demonstrate stale version rejection.
27. Demonstrate duplicate assignment prevention.
28. Add an attachment file-type restriction.
29. Add an automated feature test.
30. Debug a failing ticket creation request.

For every exercise provide:

- Requirement.
- Expected behavior.
- Difficulty.
- Relevant files.
- Suggested approach.
- Expected database changes.
- Testing instructions.
- Common mistakes.

Keep exercise solutions separate.

Do not implement these optional exercise requirements in the main application unless explicitly requested.

Create an interview cheat sheet covering:

- Core PHP.
- Laravel MVC.
- Routing.
- Middleware.
- Controllers.
- Validation.
- Eloquent.
- Relationships.
- Authentication.
- Authorization.
- Blade.
- Service container.
- Dependency injection.
- Transactions.
- Concurrency.
- Cache.
- Queue.
- Events.
- Notifications.
- WebSockets.
- Scheduler.
- Testing.
- Production deployment.

Include concise interview answers followed by actual project examples.

## 39. Implementation priorities

Implement incrementally in the following order.

### Phase 0: Baseline and inspection

- Inspect the application.
- Verify environment.
- Run existing tests.
- Record known issues.
- Prepare migration strategy.

### Phase 1: Business expansion

- Projects.
- Clients.
- Customer organization mapping.
- Project-client relationships.
- Project-agent relationships.

### Phase 2: Ticket integration

- Global and project-specific tickets.
- Ticket creation UI.
- Relationship validation.
- Project-wise assignment.
- Ticket filtering.
- Data isolation and authorization.

### Phase 3: Data integrity

- Database transactions.
- Pessimistic locking.
- Optimistic locking.
- Atomic assignment lock.
- Duplicate request protection.
- Activity consistency.

### Phase 4: Communication

- Persistent notifications.
- Reverb and Echo.
- Private channels.
- Real-time UI updates.
- Polling fallback.
- Queued email.
- Failure handling.

### Phase 5: Additional business operations

- Internal notes.
- Secure attachments.
- SLA scheduler.
- Dashboard improvements.
- CSV reporting.

### Phase 6: Production hardening

- Security review.
- Rate limiting.
- Query optimization.
- Database indexes.
- Cache consistency.
- Error handling.
- Logging.
- Operational health.

### Phase 7: Verification

- Automated tests.
- Concurrency verification.
- Real-time browser verification.
- Regression testing.
- Bug fixing.

### Phase 8: Documentation

- Update all affected guides.
- Production deployment.
- Backup and recovery.
- System design.
- Live coding exercises.
- Interview preparation.

Update DEVELOPMENT_PROGRESS.md after each phase.

Record:

- Completed tasks.
- Modified files.
- Database migrations.
- Tests executed.
- Actual test results.
- Known issues.
- Remaining tasks.
- Important decisions.
- Verification limitations.

Keep the application runnable throughout development.

Do not sacrifice security or data integrity to complete optional UI features.

## 40. Scope and time management

The application is intended for eventual production use.

All essential production requirements should be addressed.

However, do not pretend the application is production-ready merely because code has been generated.

Prioritize:

1. Correct business logic.
2. Data integrity.
3. Security.
4. Authorization.
5. Reliability.
6. Maintainability.
7. Understandability.
8. Performance.
9. UI improvements.

If available time is insufficient:

- Complete the current phase safely.
- Keep the application working.
- Record unfinished requirements.
- Clearly distinguish completed, partially completed and unverified features.
- Do not create misleading placeholder implementations.
- Do not silently skip security requirements.
- Do not add unnecessary architectural complexity.

Proceed autonomously for normal development operations.

Ask for clarification only when a genuine business ambiguity, destructive operation or significant architectural decision requires user approval.

## 41. Final acceptance criteria

Before declaring the upgrade complete, verify:

- Existing business data remains intact.
- Existing users can log in.
- Existing tickets remain accessible.
- Projects and clients work.
- Many-to-many associations work.
- Global and project-specific tickets work.
- Customer project restrictions work.
- Cross-client access is prevented.
- Assignment eligibility is enforced.
- Ticket lifecycle rules are enforced.
- Concurrent ticket operations are protected.
- Stale updates are rejected.
- Duplicate ticket creation is prevented.
- Activity history remains consistent.
- Internal notes remain private.
- Attachments are securely stored and downloaded.
- Notifications arrive without page reload.
- Private WebSocket channels are authorized.
- WebSocket fallback works.
- Email delivery is asynchronous.
- Failed jobs can be inspected and retried.
- Cache keys are correctly scoped.
- Cache invalidation works.
- SLA reminders are deduplicated.
- Reports contain authorized and accurate data.
- Rate limiting works.
- Production configuration is documented.
- Backup and restore procedures are documented.
- Existing and new tests have been executed.
- Documentation matches actual implementation.

Provide actual verification results.

Do not claim that an unexecuted test passed.

Do not claim that a production deployment was verified unless it was actually deployed and tested.

## 42. Final deliverables

At the end, provide:

1. V1 versus V2 feature comparison.
2. Completed feature list.
3. Pending or partially completed features.
4. Updated project directory structure.
5. Database table summary.
6. Updated ER diagram.
7. Architecture diagram.
8. Exact local startup commands.
9. Project-specific PHP execution instructions.
10. Demo account credentials for local development only.
11. Queue configuration and commands.
12. Reverb configuration and commands.
13. Scheduler configuration and commands.
14. Frontend asset commands.
15. Migration instructions.
16. Automated test results.
17. Concurrency verification results.
18. Real-time verification results.
19. Known limitations.
20. Security and production readiness checklist.
21. Production deployment guide.
22. Documentation reading order.
23. Three-hour source code learning plan.
24. Five-minute interview demonstration plan.
25. Live coding practice guide.

The final application must remain a simple, understandable Laravel modular monolith.

The objective is to build a real business application with essential production engineering practices while ensuring that I can independently explain, debug and extend the implementation.

Begin by inspecting the existing application and establishing the baseline.