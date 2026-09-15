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
