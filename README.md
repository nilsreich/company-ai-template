# KI-Aufgaben für eine Firma

Laravel 13, Filament 5 / Livewire 4, PHP 8.5, PostgreSQL 18. Eine Installation gehört genau einer Firma. Enthalten sind Entra-Anmeldung, lokale Rollen, private PDF-/TXT-Uploads, KI-Verarbeitung per Datenbank-Queue, Korrektur, Freigabe, JSON-Export und Audit-Einträge (Rechnungsbeispiel: examples/invoice-extraction).

Die **[vollständige Dokumentation](docs/index.md)** enthält ein ausführliches [Handbuch](docs/template-handbuch.md) mit Nutzung, Architektur, exakter Funktionsweise und Kundenanpassung sowie eine [technische Analyse](docs/template-analyse.md) mit Begründungen, Grenzen und priorisierten nächsten Schritten.

## Lokal starten

Voraussetzungen: Linux/macOS mit Docker Engine/Desktop und Docker Compose v2. Für den Start werden weder PHP noch Composer noch Node auf dem Host benötigt.

```sh
./bin/dev init
```

Dann **http://localhost:8080/login** öffnen. Bei einer neuen `.env` erzeugt `init` echte zufällige lokale Schlüssel und aktiviert ausdrücklich den Entwicklungslogin. Die drei lokalen Demo-Konten heißen `editor`, `reviewer` und `admin`; Passwörter sind nicht erforderlich. Eine bestehende `.env` wird nicht überschrieben. Dort bei Bedarf `DEV_LOGIN_ENABLED=true` setzen und `./bin/dev up` ausführen.

Für Zugriff aus dem eigenen LAN in `.env` `WEB_BIND_ADDRESS=<LAN-IP des Servers>` und `APP_URL=http://<LAN-IP des Servers>:8080` setzen, dann `./bin/dev up`. Unter dieser Adresse `/login` öffnen. Damit werden die Demo-Konten im gewählten Netz erreichbar. Der Standard bleibt `127.0.0.1`; FPM und PostgreSQL erhalten keine Host-Portfreigabe. Browser-/Betriebsprüfungen bei LAN-Bindung mit `E2E_BASE_URL=http://<LAN-IP des Servers>:8080` ausführen.

Der erste Build lädt Images und Abhängigkeiten. `init` installiert aus Lockfiles, migriert, seedet ausschließlich lokal, baut Assets und startet Web, App, Worker und PostgreSQL. Der Fake braucht standardmäßig acht Sekunden. Die Daten bleiben bei `./bin/dev down` erhalten; `down --volumes` würde sie löschen und gehört nicht zum normalen Betrieb.

```sh
./bin/dev up
./bin/dev artisan migrate
./bin/dev artisan ai:recover
./bin/dev down
```

## Feedback im Prototyp

Angemeldete Benutzer sehen bei `APP_ENV=local` rechts unten **Feedback geben**. Titel, Beschreibung, Bereich, Name/E-Mail und die sichtbaren Metadaten `environment`, `build`, `plan` werden erst nach ausdrücklichem Absenden serverseitig als Issue an ein privates GitHub-Repository übertragen. Es werden keine Seiten-URLs, DOM-Inhalte, Konsolen- oder Netzwerkprotokolle gesammelt.

Ein optionaler Screenshot entsteht über die native [Screen Capture API](https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getDisplayMedia): Der Benutzer wählt Tab/Fenster im Browser, die App übernimmt genau ein Bild ohne Ton und beendet alle Aufnahmespuren. Voraussetzung ist HTTPS oder localhost und ein unterstützter Browser. Unter HTTP im LAN bleibt Textfeedback möglich. Die Vorschau lässt sich durch Ziehen mit Maus/Finger schwärzen oder entfernen. Es wird nur das anschließend freigegebene PNG übertragen, maximal 5 MB und 4096 × 4096 Pixel; die Browseraufnahme wird auf maximal 2400 Pixel Kantenlänge verkleinert.

Screenshots bleiben unter `storage/app/private/feedback` im persistenten Upload-Volume. Im GitHub-Issue steht ausschließlich ein interner Link, der eine aktuelle Anmeldung als aktiver Admin verlangt; auch der Ersteller als Editor darf das Bild nicht herunterladen. `APP_URL` muss für diese Links auf die erreichbare interne Basisadresse zeigen. Es gibt keine öffentlichen oder signierten Gast-URLs.

Konfiguration in der nicht versionierten `.env`:

```dotenv
FEEDBACK_GITHUB_REPOSITORY=owner/private-feedback-repository
FEEDBACK_GITHUB_TOKEN=
APP_BUILD=aktueller-git-commit
APP_PLAN=prototype
```

Der Token bleibt im Backend. Für regulären Betrieb einen auf das Feedback-Repository begrenzten Fine-grained Token mit **Issues: Read and write** und **Metadata: Read** verwenden. Der lokale Prototyp verwendet ausdrücklich freigegeben die vorhandene GitHub-CLI-Anmeldung; deren Token hat weitergehende Rechte. Nach Konfigurationsänderungen `./bin/dev up`, nach JavaScript/CSS-Änderungen `npm run build` und nach Erstinstallation `./bin/dev artisan migrate` ausführen. `APP_BUILD` bei neuen Builds auf `git rev-parse --short HEAD` aktualisieren. Die Entwicklungssperre gilt auch serverseitig; kein zusätzlicher Feature-Schalter.

Vor jedem Versand werden Repository-Sichtbarkeit, aktivierte Issues und Archivstatus geprüft. Öffentliche Repositories und Redirects werden abgelehnt. Es gibt maximal fünf Einreichungen pro Minute, CSRF-Schutz und serverseitige Datei-/Berechtigungsprüfungen. Text und Kontext werden als Klartextblöcke ins Issue gesetzt. Jede Einreichung erhält eine UUID; erneutes Senden derselben UUID erzeugt kein weiteres Issue. Feedback wird intern in der Tabelle `feedback` gespeichert. Bei Netzwerkproblemen während der Issue-Erstellung lautet der Status `uncertain`; nach einem Prozessabbruch kann `sending` verbleiben. In beiden Fällen zuerst anhand der Referenz im privaten Repository prüfen, bevor ein Administrator einen weiteren Versand veranlasst. Keine automatischen Wiederholungen eines möglicherweise erfolgreichen POST. Bei `failed` hat GitHub abgelehnt. Es gibt noch keine automatische Löschung interner Feedback-Datensätze oder Screenshots.

Prüfung:

```sh
docker compose -f compose.yaml -f compose.dev.yaml exec -T app vendor/bin/phpunit --filter=FeedbackTest
npx playwright test feedback
# Optional: legt genau ein markiertes Test-Issue mit dem Demo-Konto an:
LIVE_GITHUB_FEEDBACK_TEST=1 npx playwright test feedback
```

Der Browsertest verwendet System-Chromium, eine vorübergehende reine Loopback-Weiterleitung auf `E2E_BASE_URL` (Standard: LAN-Demo) und die echte native Tab-Aufnahme mit automatischer Auswahl ausschließlich im Test. Er verändert keine Browser-Sicherheitseinstellungen der Anwendung. Ohne den expliziten Live-Schalter wird die Versandantwort im Browser simuliert; mit ihm werden zusätzlich der echte Issue-Versand und das identische, geschwärzte PNG beim Admin-Download geprüft.

## Aufgabenablauf

1. Als editor anmelden, unter **Aufgaben → Erstellen** eine PDF-Datei (Standardlimit 8 MiB, Vorschau im Browser) oder eine UTF-8-TXT-Datei (Standardlimit 256 KiB) hochladen.
2. Detailseite zeigt Original und laufenden Status; nach der Verarbeitung endet das Polling.
3. **Werte korrigieren**, Titel und Ergebnis-JSON prüfen und speichern. Auch während des Bearbeitens ist das Original sichtbar.
4. Als reviewer oder admin anmelden und **Freigeben** bestätigen.
5. **JSON exportieren**. Freigegebene Aufgaben sind schreibgeschützt.

Der Fake liefert absichtlich feste Demonstrationswerte (Zusammenfassung, Auszug, Sprache). Er dient der Integration, nicht der fachlichen Erkennungsqualität. `AI_FAKE_SCENARIO=success|timeout|rate_limit|invalid` steuert die Fehlerfälle. Nach Änderungen an `.env` App und Worker über `./bin/dev up` neu erstellen lassen; für reine Codeänderungen den Worker mit `docker compose -f compose.yaml -f compose.dev.yaml restart worker` neu starten.

## Prüfungen

```sh
./bin/dev check
```

Das führt Pint, PHPStan/Larastan Level 8, PHPUnit und Composer Audit im PHP-Container aus. `bin/analyse` führt PHPStan nach beobachteten nativen Speicherfehlern ohne CLI-OPcache, automatischen Prozessneustart und parallele Analyse aus (siehe Prüfbericht). Keine Analyseregel wird unterdrückt. Die Testdatenbank `company_ai_test` ist von den Demodaten getrennt. Sie wird von der Suite zurückgesetzt. Tests verwenden Fake-KI bzw. HTTP-/OAuth-Fixtures; externe Zugangsdaten sind nicht nötig.

Für Browserprüfungen zusätzlich Node 24 auf dem Prüfhost:

```sh
npm ci --ignore-scripts
npx playwright install --with-deps chromium
npm run test:e2e
```

Auf Alpine kann ein System-Chromium verwendet werden: `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium-browser npm run test:e2e`. Browserprüfungen benötigen den laufenden lokalen Stack mit Demo-Konten; sie erzeugen klar benannte Testdokumente. `E2E_BASE_URL` überschreibt die Zieladresse.

```sh
docker compose build app web
php tests/operations/lifecycle.php
```

Die zusätzliche Betriebsprüfung benötigt PHP auf dem Host, freie Loopback-Ports 8080/8081 und den lokalen Fake mit acht Sekunden Verzögerung. Sie unterbricht kurz den **lokalen** Stack, beendet einen Worker mit SIGKILL und wartet auf den echten Queue-Wiederanlauf. Anschließend prüft sie einen separaten, leeren Produktionsstack und stellt darin Datenbank und Dateien wieder her. Nur das eigens erzeugte Compose-Projekt `company-ai-smoke` wird danach inklusive seiner Volumes entfernt.

[Ausgeführte Prüfungen und Grenzen](docs/verification.md) · [Architektur](docs/architecture.md) · [Entra](docs/entra.md) · [KI-Adapter](docs/ai.md) · [Paketauswahl und Telescope](docs/packages.md) · [Deployment/Backup](docs/deployment.md) · [Neues Kundenprojekt](docs/new-customer.md)

## Entwicklungswerkzeuge und KI-SDK

Der Live-Adapter verwendet **Laravel AI SDK 0.11.2** für strukturierte Ausgaben über umschaltbare Driver (OpenAI, Azure, Ollama). Der lokale Fake bleibt ohne Schlüssel nutzbar. **Telescope 5.24.0** ist eine Entwicklungsabhängigkeit: mit `TELESCOPE_ENABLED=true` und `./bin/dev up` lokal aktivieren, als aktiver admin anmelden und `/telescope` öffnen. Es zeigt bereinigte Request-/Query-Laufzeiten; Aufgabentexte, SQL-Bindings, Sessions und ausgehende KI-Aufrufe werden nicht gespeichert. In Produktion wird Telescope auch bei gesetztem Schalter nicht registriert. [Details und Aufbewahrung](docs/packages.md).

## Produktion

`compose.yaml` ist die Produktionskonfiguration. `compose.dev.yaml` wird ausschließlich explizit für Entwicklung ergänzt. Produktionsanmeldung benötigt einen Entra-Mandanten, eine App-Registrierung und lokale Aktivierung. Ein echtes LLM benötigt einen OpenAI-API-Schlüssel. Ohne diese Daten bleibt die lokale Demonstration vollständig nutzbar.

Dies ist ein geprüftes Ausgangstemplate, keine Behauptung kundenspezifischer Produktionsreife. Entra-Testmandant, echter Modellaufruf, HTTPS, Betriebsüberwachung und kundenspezifische Daten-/Berechtigungsregeln sind vor Kundenbetrieb einzurichten und abzunehmen. Ein externes Deployment wird nicht durchgeführt.
