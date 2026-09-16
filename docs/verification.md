# Prüfbericht

## Ergänzung: Doku-Review und UI-Politur am 16. September 2026

- Dokumentation an den implementierten Stand angeglichen: PDF-Upload (8 MiB, Vorschau, Anhang an den Live-Adapter), acht Extraktionsfelder mit Konfidenz, achtspaltiger CSV-Export, `DOCUMENT_PDF_MAX_KIB`, interner Golden-Datensatz (`SubmitGoldenDataset`, `ai:eval`) sowie Golden-Datensatz-Archiv in `bin/backup` und `deployment.md`.
- Filament-Politur: Statusfarben und Empty-States in Dokumenten- und KI-Lauf-Tabellen, Icons/Farben/Bestätigungsdialoge und Erfolgsmeldungen bei den Dokumentaktionen, deutsche Bezeichnung „Golden-Datensatz speichern“, Upload-Hinweis zu den Größenlimits, Platzhalter und kopierbare JSON-Anzeige in den Infolists, Dark-Mode-taugliche Historie mit Leerzustand.
- `./bin/dev check` erneut bestanden: Pint 122 Dateien; PHPStan/Larastan Level 8 ohne Fehler; PHPUnit **94 Tests, 418 Assertions**; Composer Audit ohne gemeldete Advisories.

## Ergänzung: eigenes Feedback am 16. September 2026

- Das externe Feedback-SDK wurde aus Paketdateien, App-Code und ausgelieferten Assets entfernt. Der eigene Dialog erscheint nur für angemeldete Benutzer in `local`; die Einreichung ist auch serverseitig auf Entwicklung beschränkt.
- Docker-Migration und Asset-Build erfolgreich, App/Web/DB/Worker gesund. NPM- und Composer-Audit ohne gemeldete Sicherheitslücken. Pint (110 Dateien) und PHPStan/Larastan erfolgreich. Gesamte PHPUnit-Suite: **91 Tests, 392 Assertions**, davon elf Feedback-Tests.
- Feedback-Tests prüfen unter anderem Produktionssperre, aktuelle Benutzerberechtigungen, Pflichtfreigabe, PNG-Validierung, ausschließlich private GitHub-Repositories, gesperrte Redirects, serverseitige Metadaten, unveröffentlichte Providerfehler und keine erneute Issue-Erstellung bei derselben UUID oder unklarem Timeout-Ergebnis.
- Browser auf der HTTP-LAN-Adresse: eigener Button und Formular sichtbar, native Aufnahme deaktiviert mit HTTPS-/localhost-Hinweis, keine JavaScript-Fehler. Marker.io wird nicht mehr geladen.
- `tests/operations/feedback.cjs`: echte native Tab-Aufnahme mit Chromium über temporäre Loopback-Weiterleitung; alle Tracks nach einem Bild beendet. Schwärzung und mobile Darstellung geprüft. Kein Feedback-Upload vor dem ausdrücklichen Absenden. Die automatische Auswahl des Tabs gilt ausschließlich im Testbrowser.
- Live-Test mit `LIVE_GITHUB_FEEDBACK_TEST=1`: [markiertes Test-Issue #1](https://github.com/nilsreich/company-ai-feedback/issues/1) im neu angelegten privaten Repository erstellt. Screenshot nur im privaten Upload-Volume; GitHub erhält lediglich einen geschützten Link. Admin-Download entspricht bytegenau dem geschwärzten PNG. Editor erhält 403, Gast wird zur Anmeldung umgeleitet. Kein Kundeninhalt verwendet.
- Kein HTTPS für die LAN-Demo eingerichtet. Die normale Browser-Berechtigungsauswahl durch einen Menschen wurde nicht automatisiert getestet; der Testbrowser verwendet die automatische Tab-Auswahl. Screenshots/Feedback haben noch keine automatische Aufbewahrungsbegrenzung. Konfiguration und Fehlerbehandlung siehe [README](../README.md#feedback-im-prototyp).

Stand: 15. September 2026. Alle Ausführungen waren lokal; es gab kein externes Deployment und keine echten Entra-/LLM-Aufrufe.

## Erfolgreich ausgeführt

| Prüfung | Ergebnis |
| --- | --- |
| `./bin/dev init` | Images, Installation aus Lockfiles, Migrationen, lokale Seeds, Asset-Build und vier Dienste gestartet; bestehende `.env` erhalten |
| `./bin/dev check` | Nach SDK-/Telescope-Integration erneut bestanden: Pint 103 PHP-Dateien; PHPStan/Larastan Level 8 ohne Fehler; PHPUnit 80 Tests, 324 Assertions; Composer Audit ohne gemeldete Advisories |
| Composer Validate / NPM Audit | Manifest und Lockfile gültig; keine gemeldeten NPM-Sicherheitslücken |
| Playwright mit System-Chromium | Zwei Browsertests bestanden: kompletter Ablauf mit echtem Datenbank-Worker, Korrektur, Freigabe, CSV sowie negative Zugriffsprüfungen |
| Produktionsimage-Build | App und Web erfolgreich gebaut; PHP 8.5.10 und PostgreSQL 18.6 verwendet |
| Containerkonfiguration | Compose- und Nginx-Konfiguration gültig; App, DB, Web und Worker mit Healthchecks |
| `python3 tests/operations/lifecycle.py` | Worker während Fake-Aufruf mit SIGKILL beendet; nach echter 120-Sekunden-Reservierung erfolgreich, zwei Versuche und genau eine Ergebnisübernahme |
| Persistenz und frischer Produktionsstart | Daten und Upload nach Neustart erhalten; Migrationen in leerer separater PostgreSQL-Installation; keine Demo-Seeds und keine Devlogin-Route trotz aktivierter Variable |
| Backup und Restore | Dump und private Dateien in isolierten Produktionscontainern wiederhergestellt; SHA-256 und Dokumentstatus stimmen überein; `bin/backup` separat erfolgreich und Archiv-Prüfsummen gültig |
| Laravel Boost | MCP-`initialize` über Docker erfolgreich; Produktion enthält weder Boost noch PHPUnit noch Node oder Analyse-Caches; Prozessbenutzer `www-data` |

## Ergänzende Paket- und LAN-Prüfung

- Laravel AI SDK 0.11.2 und Telescope 5.24.0 mit Lockfile installiert; Composer Validate sowie Composer-/NPM-Audit erneut erfolgreich.
- HTTP-Fixtures durchlaufen den echten SDK-Provider und das strikte Schema. Timeout, Transportoptionen, 429/5xx, Verweigerung, ungültige Daten, Fehlerbereinigung und genau ein HTTP-Aufruf pro Versuch geprüft. Datenbank-Lauf mit SDK: Rate Limit, verzögerte Wiederholung, ein Ergebnis und Tokenverbrauch erfolgreich geprüft. Kein echter Anbieteraufruf.
- Telescope: lokale Migration ausgeführt, Adminzugriff und interne API geprüft, Editor/Reviewer abgelehnt; Rollenentzug und Deaktivierung bestehender Sitzung wirksam. Gespeicherte Einträge enthalten keine vertraulichen Testinhalte. Produktionsregistrierung trotz gesetztem Schalter ausgeschlossen. `telescope:prune --hours=24` und lokaler Schedule-Eintrag geprüft.
- Beide regulären Playwright-Tests über `http://192.168.178.200:8080` erneut bestanden. Die Loginseite und `/up` liefern über diese LAN-Adresse HTTP 200. Zugriff von einem anderen physischen Gerät wurde nicht ausgeführt. Die Adresse ist nur die lokale Demo-Konfiguration; das Template bleibt standardmäßig an Loopback gebunden.
- Separater Telescope-Browsertest: Admin-Dashboard, JavaScript-Initialisierung und Requests-API erfolgreich, Editor erhält 403. Wiederholbar mit `tests/operations/telescope.cjs` und den Befehlen in `packages.md`; der temporäre Server wird danach entfernt.
- App- und Web-Produktionsimage nach der Paketänderung erfolgreich gebaut. Separates Compose-Projekt `company-ai-package-check` mit leerer PostgreSQL-Datenbank gestartet, migriert und per HTTP geprüft. AI SDK vorhanden; Telescope, Boost und PHPUnit fehlen. Keine Demo-Benutzer, Telescope- oder Conversation-Tabellen. Keine Devlogin-/Telescope-Routen trotz beider aktivierter Variablen. Anschließend ausschließlich dieses Prüfprojekt mit seinen Volumes entfernt.

Die oben dokumentierten vollständigen Worker-Abbruch-, Persistenz- und Restore-Prüfungen stammen aus dem vorherigen Implementierungsstand. Sie wurden nach dieser Paketergänzung nicht erneut gegen die inzwischen im LAN genutzte Demo ausgeführt. Die automatisierten Jobtests und der separate frische Produktionsstart wurden erneut ausgeführt. `tests/operations/lifecycle.py` prüft in CI zusätzlich die Telescope-Sperre und unterstützt die LAN-Zieladresse über `E2E_BASE_URL`; die geänderte Python-Datei wurde lokal auf Syntax geprüft.

Die PHP-Tests decken Rollen, direkte Aktionen, gesperrten Produktions-Devlogin, deaktivierte Sitzungen, private Downloads, Export, signierte OAuth-Fixtures, ungültige KI-Ausgaben, Job-Wiederholungen, doppelte Ausführung, Versionskonflikte und Schreibschutz nach Freigabe ab. Fehler im Formular werden am betroffenen Feld angezeigt. Die produktive Datenbank wird von PHPUnit nicht verwendet; die Suite setzt ausschließlich `company_ai_test` zurück.

Beim erneuten lokalen Start wurde eine veraltete FPM-Adresse im Nginx-DNS-Cache gefunden und behoben: Nginx löst den Dienstnamen nun über Dockers Resolver regelmäßig neu auf. Die Browserabläufe bestanden anschließend erneut. Siehe [FastCGI-Namensauflösung](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_pass).

## Native Laufzeitfehler und Analysemodus

Im ersten Betriebsprüflauf trat ein PHP-CLI-Absturz (Exit 139) auf. Ein weiterer Core-Dump zeigte einen Absturz bei `intl`-Initialisierung in `zend_register_functions`, vor Ausführung von Laravel. Danach liefen zehn Anwendungs-Leseaufrufe, 100 isolierte intl-Starts und 1.300 gewöhnliche leere PHP-Starts erfolgreich. Die ursprüngliche Ursache ist nicht abschließend geklärt; daraus wird keine bestätigte Upstream-Fehlerdiagnose abgeleitet.

PHPStan 2.2.14 zeigte zusätzlich native Speicherfehler und beschädigte generierte Cache-Dateien. Die Diagnose mit und ohne Turbo genügte nicht für eine eindeutige Zuordnung. Der Cache wurde neu erzeugt. Der abschließend erfolgreiche Aufruf in `bin/analyse` verwendet dieselbe gelockte Version mit `opcache.enable_cli=0`, `disable_functions=pcntl_exec` und `--debug`. Damit erfolgt die Analyse ohne PHPStans automatischen Neustart mit CLI-OPcache/Turbo und ohne parallele Worker. Diese Optionen gelten ausschließlich im Analyseprozess. App/FPM und Queue behalten ihre normale Konfiguration; alle Analyseregeln bleiben aktiv, es gibt keine Baseline oder pauschalen Fehlerunterdrückungen.

Bei zukünftigen PHP-/PHPStan-Updates diesen Modus erneut prüfen. Vor Kundenbetrieb die Laufzeit auf dem Zielhost einschließlich längerer Worker-/Healthcheck-Beobachtung abnehmen. Core-Dumps und Analyse-Caches werden weder versioniert noch in Produktionsimages kopiert.

Quellen: [PHP 8.5.10](https://www.php.net/ChangeLog-8.php#8.5.10), [PHPStan Turbo](https://github.com/phpstan/turbo-ext), außerdem der tatsächlich installierte `TurboProcessRestarter` und `ProcessHelper` in PHPStan 2.2.14. Die Dokumentation anderer Turbo-Versionen nennt teilweise einen Abschaltschalter, den diese installierte Version nicht auswertet; deshalb wird kein wirkungsloser Schalter verwendet.

## Noch separat abzunehmen

- Echter Entra-Testmandant: Tenant-/Client-ID, Secret, Redirect-URI, Zuweisung, Zustimmung, Conditional Access und Rotation fehlen. Anleitung in `entra.md`.
- Echter OpenAI-Aufruf: API-Schlüssel, Modellzugriff und kundenbezogene Qualitäts-/Kostenabnahme fehlen. Reguläre Tests verwenden ausschließlich Fake bzw. HTTP-Fixtures.
- Öffentlicher HTTPS-Abschluss und echter OAuth-Callback: Domain, Zertifikat und vertrauenswürdige Proxyadressen kundenseitig einrichten.
- CI ist implementiert; die GitHub-Actions-Ausführung auf einem Remote-Runner wurde nicht ausgelöst.
- Kein Lasttest mit 400 gleichzeitigen Requests, kein externer Penetrationstest und kein vollständiger Container-CVE-Scan. 400 Konten sind keine Gleichzeitigkeitsanforderung. Composer/NPM-Audits ersetzen diese Prüfungen nicht.

Das Template ist eine lokal geprüfte Grundlage. Die genannten externen Abnahmen und die Beobachtung des Zielhosts bleiben Voraussetzungen für die kundenspezifische Freigabe.
