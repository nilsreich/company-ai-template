# Paketauswahl für das Template

Stand: 16. September 2026. Maßgeblich sind `composer.json` und `composer.lock`.

| Paket | Umsetzung |
| --- | --- |
| Laravel AI SDK | 0.11.2, Laufzeitabhängigkeit; Live-Extraktion über OpenAI Responses API, eigener fachlicher Ergebnisvertrag |
| Telescope | 5.24.0, ausschließlich `require-dev`; explizit lokal aktivierbar und nur für aktive Administratoren |
| PHPUnit / Pest | PHPUnit 12 bleibt das gemeinsame Testwerkzeug. Keine zweite Syntax und keine Umstellung bestehender Tests ohne fachlichen Nutzen. Pest ist nicht installiert. |
| Pulse | Optionales späteres Betriebsprofil, nicht installiert |
| Spatie Laravel Backup | Optionales späteres Betriebsprofil, nicht installiert; vorhandene Dump-/Dateisicherung bleibt nutzbar |
| Pennant | Nicht installiert. Für feste Rollen und eine Firma reichen Policies und Konfiguration; erst für tatsächliche schrittweise Funktionsfreischaltung ergänzen. |
| Laradock | Nicht installiert. Der vorhandene Compose-Aufbau deckt Entwicklung und Produktion ab. |

## Telescope lokal benutzen

Nach `./bin/dev init` in `.env` `TELESCOPE_ENABLED=true` setzen und `./bin/dev up` ausführen. Als aktiver admin anmelden und `/telescope` öffnen. Bei einer Aktualisierung eines vorhandenen Checkouts zuerst `./bin/dev composer install` und `./bin/dev artisan migrate` ausführen. Zum Abschalten `TELESCOPE_ENABLED=false` setzen und Dienste erneut erstellen lassen.

Die automatische Paketerkennung ist für Telescope in Composer deaktiviert. `AppServiceProvider` registriert die Paket- und Anwendungskonfiguration ausschließlich bei `APP_ENV=local` und installiertem Paket. Keine Registrierung in `testing`, `staging` oder `production`; Tests aktivieren die lokale Konfiguration gezielt. Die Migration liegt unter `database/migrations/local` und wird nur lokal hinzugeladen. Produktionsimages installieren mit `--no-dev`. Ein versehentliches `TELESCOPE_ENABLED=true` schaltet dort keine Routen oder Aufzeichnung frei.

Der Dashboard-Zugriff nutzt dieselbe Laravel-Session und dieselbe Admin-Policy wie die Benutzerverwaltung. Das gilt auch für die internen Telescope-Endpunkte. Deaktivierung und Rollenentzug wirken beim nächsten Request. Der standardmäßige allgemeine lokale Zugang von Telescope wird ausdrücklich ersetzt.

Erfasst werden ausschließlich Request-Methode, Routenschablone, Status, Dauer, Speicherbedarf und Query-Laufzeiten mit Aufrufstelle. Vollständige URLs, Query-Parameter, Header, Cookies, Sitzungen, Benutzerprofile, Request-/Response-Inhalte sowie SQL und Bindings werden vor dem Speichern entfernt. HTTP-Client-, Event-, Exception-, Job-, Log- und Model-Watcher sind nicht aktiv; insbesondere werden SDK-Prompts und Providerantworten nicht aufgezeichnet. Die vorhandene Filament-Laufübersicht bleibt die fachliche Fehleransicht. Diese begrenzte Aufzeichnung ist bewusst weniger umfangreich als eine Standardinstallation.

Alte Einträge manuell entfernen:

```sh
./bin/dev artisan telescope:prune --hours=24
```

Der Befehl ist außerdem im lokalen Laravel-Scheduler täglich registriert. Automatische Bereinigung setzt dort einen laufenden `schedule:work`-Prozess oder einen minütlichen `schedule:run`-Cron voraus; Compose startet keinen Scheduler automatisch. Ohne Scheduler regelmäßig manuell bereinigen. Niemals `telescope:install` oder `telescope:publish --force` ungeprüft wiederholen: dies kann die eingeschränkte Konfiguration überschreiben.

Quelle: [offizielle Telescope-Dokumentation](https://laravel.com/docs/13.x/telescope).

### Separate Browserprüfung

Bei laufender lokaler Datenbank und migriertem Template kann ein temporärer Server gestartet werden, ohne die Demo-Konfiguration zu ändern:

```sh
docker compose -f compose.yaml -f compose.dev.yaml run --rm --no-deps -p 127.0.0.1:8082:8000 -e TELESCOPE_ENABLED=true -e APP_URL=http://127.0.0.1:8082 app php artisan serve --host=0.0.0.0 --port=8000 --no-reload
```

In einem zweiten Terminal mit installiertem Playwright/Chromium:

```sh
npx playwright test telescope
```

Auf Alpine `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium-browser` voranstellen. `TELESCOPE_BASE_URL` überschreibt die Testadresse. Die Prüfung meldet sich als Demo-admin und Demo-editor an, prüft Dashboard, JavaScript, interne API und Ablehnung des Editors. Anschließend den temporären Server mit Strg+C stoppen. `--no-reload` ist erforderlich, damit `artisan serve` die übergebenen Umgebungsvariablen an seinen PHP-Unterprozess weiterreicht.

## Optionales Betriebsprofil: Pulse

Pulse lohnt sich, wenn mehrere Kundeninstallationen regelmäßig hinsichtlich langsamer Requests, Queue-Verzögerungen und Auslastung beobachtet werden sollen. Für eine Ergänzung zunächst die aktuelle Paketkompatibilität lösen, Migrationen prüfen und den Datenbank-Ingest verwenden. Redis ist dafür keine notwendige neue Grundvoraussetzung. Dashboardzugriff an die aktive lokale Admin-Policy binden, Aufbewahrung/Sampling festlegen und Tags sowie Recorder auf vertrauliche Werte prüfen. Servermetriken benötigen den dokumentierten dauerhaft überwachten `pulse:check`-Prozess. Eine Überwachung von außen bleibt erforderlich, wenn die Anwendung selbst ausfällt.

Das ist ein Erweiterungsplan; Pulse, seine Migrationen und Recorder sind hier weder installiert noch getestet. Quelle: [offizielle Pulse-Dokumentation](https://laravel.com/docs/13.x/pulse).

## Optionales Betriebsprofil: Spatie Laravel Backup

Sinnvoll, wenn Sicherungen, Aufbewahrung und Benachrichtigungen zentral aus Laravel gesteuert werden sollen. Vor Integration Backup-Ziel, Verschlüsselung, Zugangsdaten, Löschfristen und Restore-Verantwortung festlegen. Der ausführende Container benötigt einen zu PostgreSQL 18 passenden `pg_dump`-Client sowie Zugriff auf das private Upload-Volume. Standarddateilisten nicht übernehmen: `.env`, Logs und Entwicklungsartefakte ausschließen; APP_KEY separat sichern. DB-Dump und Dateien müssen einen zusammenpassenden Stand bilden; dafür weiterhin ein kontrolliertes Schreib-Wartungsfenster vorsehen. Scheduler und Fehlerbenachrichtigung tatsächlich betreiben und vollständige Wiederherstellung isoliert testen.

Spaties Paket erstellt Archive, unterstützt mehrere Dateisystemziele, Bereinigung und Backup-Monitoring. Dies ersetzt weder ein externes Sicherungsziel noch einen Restore-Test. Das Template verwendet aktuell den geprüften Ablauf in [Deployment und Backup](deployment.md); kein zweites Backup-System ist parallel aktiv. Quelle: [offizielles Paketrepository](https://github.com/spatie/laravel-backup).
