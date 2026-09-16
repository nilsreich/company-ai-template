# Projektregeln für Coding-Agenten

Ausführlicher Einstieg: `docs/index.md`, `docs/template-handbuch.md` und `docs/template-analyse.md`. Das Handbuch beschreibt den aktuellen Code, die Analyse unterscheidet vorhandene Funktionen von vorgeschlagenen Erweiterungen. Ein dort genannter Verbesserungsvorschlag ist kein bereits implementiertes Verhalten.

## Architektur

- Eine Firma pro Installation; keine Multitenancy, SPA/API-Trennung oder zusätzlichen Laufzeitdienste ohne konkreten Bedarf.
- Laravel 13 / PHP 8.5, Filament 5 / Livewire 4, PostgreSQL 18. Konkrete PHP-/JS-Abhängigkeiten stehen in composer.lock und package-lock.json. Keine unbegründeten Versionsabweichungen oder ignorierten Plattformanforderungen.
- Filament beschreibt Darstellung und delegiert an `app/Actions`. Menschlich ausgelöste Anwendungsklassen autorisieren mittels Policies. Policies laden lokale Berechtigungen frisch. UI-Sichtbarkeit ersetzt keine Autorisierung.
- `DocumentExtractor` ist die Anbietergrenze. Keine Anbieterlogik in Jobs oder Filament; keine Tools für Dokumentinhalte. Ergebnisse strikt serverseitig validieren; Geld niemals als Float.
- Live-Extraktion verwendet Laravel AI SDK mit genau einem Provider und Schritt. SDK-Upgrades müssen HTTP-Anzahl, Redirect-Sperre, Timeouts, Fehlerkategorien und Servervalidierung erhalten; keine SDK-Queue zusätzlich zum bestehenden Job. Der deterministische Fake bleibt unabhängig.
- Telescope ist `require-dev`, ohne Auto-Discovery, nur lokal und explizit aktiviert. Nur aktive Admins erhalten Zugriff. Die Watcher-Allowlist und Bereinigung vertraulicher Daten nicht durch Paketdefaults ersetzen; lokale Migrationen niemals in Produktion hinzuladen.
- Dokumentstatus und Laufstatus bleiben getrennt. Ein Erfolg ist keine Freigabe. Freigegebene Dokumente nicht verändern.
- Externe Aufrufe außerhalb von DB-Transaktionen. Lauf-Lease und Besitzerkennung, begrenztes Versuchsbudget sowie Eingabe-/Bearbeitungsrevisionen beim Abschluss prüfen. Job-Payload enthält nur die Lauf-ID.
- Änderungen an Jobs erfordern Worker-Neustart. HTTP 30 s < Job 60 s < Lease 90 s < retry_after 120 s. Keine HTTP-Retries zusätzlich zu Job-Retries.
- Private Originale und Audit-Daten nicht in gewöhnliche Logs schreiben. Keine Tokens, Schlüssel oder Provider-Rohantworten in Exceptions übernehmen.
- Entra-Identität ist `(tid, oid)`, nie E-Mail. Externe Rollen nicht in lokale Rollen übernehmen. Entwicklungslogin zusätzlich zur Umgebungsvariable mit local/testing absichern.
- Vorhandene Arbeit erhalten. Offizielle Generatoren bevorzugen. Neue Features mit passenden Verhaltens-/Berechtigungstests prüfen; keine pauschalen PHPStan-Unterdrückungen.
- PHPStan hier über `sh bin/analyse` ausführen: nach nativen Speicherfehlern werden nur für diesen Analyseprozess CLI-OPcache, automatischer Prozessneustart und parallele Analyse vermieden. Sämtliche Projektregeln bleiben aktiv. Details in `docs/verification.md`.

## Tatsächlich verwendete Befehle

```sh
./bin/dev init
./bin/dev check
./bin/dev artisan migrate
./bin/dev artisan ai:recover
./bin/dev artisan telescope:prune --hours=24
docker compose -f compose.yaml -f compose.dev.yaml exec -T app vendor/bin/pint
docker compose -f compose.yaml -f compose.dev.yaml exec -T app vendor/bin/phpunit --filter=DocumentFlowTest
npm run test:e2e
PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium-browser node tests/operations/telescope.cjs
docker compose build app web
python3 tests/operations/lifecycle.py
```

Die Browserbefehle setzen Node 24 und einen installierten Playwright-Browser voraus. Auf Alpine `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium-browser` setzen. Betriebsprüfungen unterbrechen den lokalen Stack; niemals gegen eine Kundeninstallation ausführen. Testdatenbank ist ausschließlich `company_ai_test`.

Der optionale Telescope-Browsertest benötigt den temporären lokalen Server aus `docs/packages.md`. Bei LAN-Bindung für reguläre Browser-/Betriebsprüfungen `E2E_BASE_URL` setzen. Die öffentliche Demo-Adresse nicht für isolierte Produktionsprüfungen übernehmen.

## Boost

Boost ist ausschließlich `require-dev`. Generierte Skills liegen in `.agents/skills`; MCP-Konfigurationen wurden durch `php artisan boost:install` erzeugt. Für containerbasiertes Codex lautet der MCP-Aufruf `docker compose -f compose.yaml -f compose.dev.yaml exec -T app php artisan boost:mcp`. Produktion hängt nicht von Boost ab. Keine Boost-/Debug-Routen in Produktion ergänzen.

## Primärquellen

- https://laravel.com/docs/13.x/releases
- https://laravel.com/docs/13.x/ai-sdk
- https://laravel.com/docs/13.x/telescope
- https://laravel.com/docs/13.x/queues
- https://laravel.com/docs/13.x/authorization
- https://laravel.com/docs/13.x/socialite
- https://filamentphp.com/docs/5.x/introduction/installation
- https://filamentphp.com/docs/5.x/testing/overview
- https://livewire.laravel.com/docs/4.x/security
- https://socialiteproviders.com/Microsoft/
- https://github.com/SocialiteProviders/Microsoft/blob/4.10.0/Provider.php
- https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow
- https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference
- https://developers.openai.com/api/docs/guides/structured-outputs
- https://www.php.net/ChangeLog-8.php
- https://www.postgresql.org/support/versioning/

Vor Paketänderungen aktuelle Dokumentation und Sicherheitsmeldungen prüfen, Lockfiles aktualisieren, Composer/NPM-Audit ausführen. Ausgeführte und nicht ausgeführte Prüfungen sauber unterscheiden.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== spatie/laravel-activitylog/core rules ===

# spatie/laravel-activitylog

Activity logging package for Laravel. Logs model events and manual activities to a database table.

## Key Concepts

- **Activity**: An Eloquent model (`Spatie\Activitylog\Models\Activity`) storing log entries with subject, causer, event, attribute_changes, and properties.
- **Subject**: The model being acted upon (polymorphic `subject_type`/`subject_id`).
- **Causer**: The model that caused the action, typically the authenticated user (polymorphic `causer_type`/`causer_id`).
- **LogOptions**: Fluent configuration object returned by `getActivitylogOptions()` on models using the `LogsActivity` trait.
- **ActivityEvent**: Enum with cases `Created`, `Updated`, `Deleted`, `Restored`.
- **`attribute_changes`** column: stores `{"attributes": {...}, "old": {...}}` for tracked model changes.
- **`properties`** column: stores custom user data set via `withProperties()`.

## Traits

### `LogsActivity`

Add to models to automatically log create/update/delete events. Optionally implement `getActivitylogOptions()` to configure which attributes to track (defaults to logging events without attribute changes).

```php
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Article extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
```

### `CausesActivity`

Add to user/causer models. Provides `activitiesAsCauser()` relationship.

### `HasActivity`

Combines `LogsActivity` and `CausesActivity`. Provides `activities()`, `activitiesAsSubject()`, and `activitiesAsCauser()`.

## Manual Logging

```php
activity()
    ->performedOn($article)
    ->causedBy($user)
    ->event(ActivityEvent::Updated)
    ->withProperties(['key' => 'value'])
    ->log('Article was updated');
```

## LogOptions Methods

| Method | Description |
|--------|-------------|
| `logFillable()` | Log all fillable attributes |
| `logAll()` | Log all attributes |
| `logOnly(array)` | Log specific attributes |
| `logExcept(array)` | Exclude attributes |
| `logOnlyDirty()` | Only log changed attributes |
| `dontLogEmptyChanges()` | Skip logging when no tracked attributes changed |
| `dontLogIfAttributesChangedOnly(array)` | Ignore updates that only change these attributes |
| `useLogName(string)` | Set custom log name |
| `setDescriptionForEvent(Closure)` | Custom description per event |
| `useAttributeRawValues(array)` | Store raw (uncast) values |

## Querying Activities

```php
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Enums\ActivityEvent;

Activity::forEvent(ActivityEvent::Created)->get();
Activity::causedBy($user)->get();
Activity::forSubject($article)->get();
Activity::inLog('orders')->get();
```

## Setting the causer

Override the causer for a block of code:

```php
use Spatie\Activitylog\Facades\Activity;

Activity::defaultCauser($admin, function () {
    // all activities here are caused by $admin
});

// or set globally for the rest of the request
Activity::defaultCauser($admin);
```

## Disabling Logging

```php
activity()->withoutLogging(function () {
    // no activities logged here
});
```

## Accessing Changes and Properties

```php
$activity = Activity::latest()->first();

// Tracked model changes (set automatically by LogsActivity)
$activity->attribute_changes; // Collection: {"attributes": {...}, "old": {...}}

// Custom user data (set via withProperties)
$activity->properties; // Collection
$activity->getProperty('key'); // single value
```

## Custom Activity Model

Set `activity_model` in `config/activitylog.php` to a class that extends `Model` and implements `Spatie\Activitylog\Contracts\Activity`. Use a custom model for custom table names or database connections.

## Customizing Actions

The package uses action classes (`LogActivityAction`, `CleanActivityLogAction`) that can be extended and swapped via config:

```php
// config/activitylog.php
'actions' => [
    'log_activity' => \App\Actions\CustomLogActivityAction::class,
    'clean_log' => \App\Actions\CustomCleanAction::class,
],
```

Custom action classes must extend the originals. Override protected methods (`save()`, `beforeActivityLogged()`, `resolveDescription()`, etc.) to customize behavior.

## Configuration

Key config options in `config/activitylog.php`:
- `enabled`: Master on/off switch (env: `ACTIVITYLOG_ENABLED`)
- `clean_after_days`: Days to keep records for `activitylog:clean` command
- `default_log_name`: Default log name (string)
- `default_auth_driver`: Auth driver for causer resolution
- `include_soft_deleted_subjects`: Include soft-deleted subjects
- `activity_model`: Custom Activity model class
- `default_except_attributes`: Globally excluded attributes
- `actions.log_activity`: Action class for logging activities
- `actions.clean_log`: Action class for cleaning old activities

</laravel-boost-guidelines>
