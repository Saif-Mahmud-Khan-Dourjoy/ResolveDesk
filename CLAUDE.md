# ResolveDesk — Permanent Project Instructions

## Project objective

ResolveDesk is an interview-oriented Customer Support and Workflow Automation System.

The primary objective is to build a professional Laravel application that I can independently understand, explain, debug and modify during a live technical interview.

## Mandatory development environment

- Operating system: Windows.
- PHP version: 8.4.28.
- PHP executable: C:\php-8.4.28\php.exe
- Laravel version: 13.x.
- Database: MySQL 8.x.
- Frontend: Blade, Bootstrap 5.3 and Vanilla JavaScript.

The global Windows PATH contains an older PHP version used by other projects.

Never modify the permanent Windows PATH or other PHP installations.

Before executing Composer, Artisan, migrations, tests or queue commands, verify that PHP and Composer are using PHP 8.4.28.

For PowerShell, temporarily configure:

```powershell
$env:Path = "C:\php-8.4.28;$env:Path"
php -v
composer -V
```

For Git Bash:

```bash
export PATH="/c/php-8.4.28:$PATH"
php -v
composer -V
```

Do not assume temporary environment changes persist between separate command executions.

Use the absolute PHP executable path when necessary.

Never bypass Composer platform requirements.

## Project requirements

Read MASTER_PROMPT.md completely before implementing or modifying major functionality.

Treat MASTER_PROMPT.md as the approved project specification.

Read DEVELOPMENT_PROGRESS.md before resuming incomplete development.

## Coding principles

- Follow traditional Laravel MVC.
- Prefer simple, readable and maintainable code.
- Avoid unnecessary abstractions and design patterns.
- Use standard Laravel functionality.
- Do not introduce unapproved dependencies.
- Do not introduce unrelated features.
- Use descriptive variable and method names.
- Explain important business logic.
- Maintain project-specific learning documentation.
- Use English for source code and simple English with Bangla explanations for difficult concepts.

## Safety and verification

- Never expose or commit secrets.
- Never delete existing databases or perform destructive migrations without permission.
- Verify authorization and validation on the backend.
- Run relevant tests after changes.
- Do not claim successful verification unless it was actually performed.
- Keep DEVELOPMENT_PROGRESS.md updated.
- Document important implementation decisions and known limitations.

The application must remain simple enough for me to independently modify during a technical interview.


## ResolveDesk V2 Development Rules

### Specification precedence

- V2_MASTER_PROMPT.md is the authoritative specification for the production upgrade.
- MASTER_PROMPT.md contains the original application requirements.
- Preserve all original requirements unless explicitly superseded by V2_MASTER_PROMPT.md.
- Inspect actual source code before assuming any feature exists.
- Maintain DEVELOPMENT_PROGRESS.md as the implementation status record.

### Development principles

- Keep the application a simple Laravel modular monolith.
- Prioritize correctness, security, readability and maintainability.
- Use standard Laravel conventions.
- Avoid unnecessary abstraction and architectural complexity.
- Preserve existing working functionality.
- Do not overwrite unrelated user changes.
- Do not introduce unrequested features or dependencies.

### Environment

- Use the existing project-specific PHP 8.4 executable.
- Verify Composer's PHP runtime.
- Do not modify the permanent Windows PATH.
- Do not change global PHP configuration.
- Preserve existing database configuration.
- Never expose environment secrets.

### Database safety

- Use incremental migrations.
- Never run migrate:fresh or db:wipe against existing business data.
- Use isolated databases for destructive tests.
- Preserve historical records.
- Request approval before destructive or irreversible operations.

### Production engineering

- Enforce server-side validation and authorization.
- Protect client data isolation.
- Use transactions for dependent database operations.
- Handle relevant concurrency and duplicate request scenarios.
- Dispatch external side effects after successful database commit.
- Preserve persistent notifications independently of real-time delivery.
- Keep queue, cache and WebSocket implementations understandable.
- Apply secure file storage and download authorization.
- Avoid N+1 queries and unnecessary database operations.

### Verification

- Run relevant tests after implementing changes.
- Verify actual behavior instead of assuming generated code works.
- Do not claim unexecuted tests passed.
- Do not claim production readiness without appropriate verification.
- Document known limitations and unfinished requirements.

### Learning requirements

For every important implementation:

- Explain the business purpose.
- Explain the UI-to-backend request flow.
- Identify relevant files.
- Explain important code blocks.
- Explain database operations and equivalent SQL.
- Explain validation, authorization, transactions and locking.
- Explain relevant notification, queue and cache behavior.
- Provide debugging and modification guidance.

Use clear English and Bangla explanations for difficult concepts where helpful.

Update learning documentation whenever the implementation changes.

Keep DEVELOPMENT_PROGRESS.md updated after every development phase.