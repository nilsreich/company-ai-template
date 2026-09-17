# Architekturentscheidung: modularer Laravel-Monolith

## Entscheidung

Eine Installation bedient eine Firma mit ungefähr 400 Konten. Daraus wird keine Lastannahme über gleichzeitige Anfragen abgeleitet. Filament und zusätzliche Laravel-Routen teilen sich den `web`-Guard und die datenbankbasierte Session. PostgreSQL speichert Geschäftsdaten, Queue, Cache und Sessions. Nginx und PHP-FPM bedienen HTTP; derselbe Anwendungscode läuft in einem separaten Queue-Worker.

Die Rollen sind bewusst klein und fest: editor darf hochladen, lesen, korrigieren und fehlgeschlagene Extraktionen neu starten. reviewer darf zusätzlich freigeben und freigegebene Ergebnisse exportieren. admin verwaltet zusätzlich Aktivierung und Rollen. Alle aktiven Benutzer sehen alle Dokumente der Firma. Es gibt zunächst keinen Vier-Augen-Zwang, kein Löschen und kein Wiederöffnen freigegebener Dokumente. Unbekannte Entra-Benutzer werden inaktiv als editor angelegt.

## Grenzen und Datenfluss

`UploadTask`, `StartExecution`, `ProcessExecution`, `CorrectTask`, `ResetTaskField`, `RestoreTaskRevision`, `ApproveTask`, `ExportTask`, `RejectAuditCleanup` (Rechnungsbeispiel: `examples/invoice-extraction`) und `UpdateUserAccess` kapseln die Anwendungsfälle. Filament übernimmt Darstellung; Policies autorisieren einschließlich direkter Aktionsaufrufe. Middleware und Policies aktualisieren das Benutzerobjekt, damit Rollenänderungen und Deaktivierungen bestehende Sitzungen beim nächsten Request erreichen.

Eine Aufgabe enthält Titel, privaten Dateipfad, MIME-Typ (`text/plain` oder `application/pdf`), SHA-256, Eingabeversion und Bearbeitungsrevision sowie den aktuellen Ergebnis-Payload (JSON). Jede Ausführung speichert Startrevisionen, Anbieter/Modell, Promptversion, Versuchszahl, Zeitpunkte, validiertes Originalergebnis, KI-Selbsteinschätzung (Konfidenz je Feld) und verfügbaren Tokenverbrauch. `applied=false` mit Kategorie `superseded` macht ein erfolgreiches, aber überholtes Ergebnis erkennbar. Fachliche Feldregeln liefert das Domain-Modul (Vorlage: Rechnungsfelder in `examples/invoice-extraction`). Audit-Einträge enthalten Akteur, Zeitpunkt und Änderungen; sie sind kein gegen privilegierte Datenbankadministratoren manipulationsgeschütztes Archiv.

Kurze Transaktionen beanspruchen eine Ausführung per Lease und Besitzer-UUID. Es folgen Dateiprüfung und Modellaufruf ohne offene Anwendungstransaktion. Der Abschluss sperrt Aufgabe und Ausführung und prüft Eigentümer, Lease, Status und beide Versionen erneut. Korrekturen verwenden dieselbe Aufgabensperre und eine optimistische Revision. Eine erfolgreiche Ausführung setzt höchstens `in_review`; nur die Freigabeaktion setzt `approved`.

Laravel-Jobs transportieren nur die Ausführungs-ID. Drei Modellversuche, Backoff 10/30 Sekunden, keine HTTP-Retries. Timeout-Kette: HTTP 30, Job 60, Lease 90, Queue-Reservierung 120 Sekunden. Die gespeicherte Ausführung fungiert zugleich als Dispatch-Absicht: bei einem Prozessabbruch zwischen Commit und Dispatch stellt `ai:recover` verwaiste Absichten nach spätestens 180 Sekunden erneut zu. Überlappungen sind durch die atomare Ausführungsbeanspruchung harmlos. Der Recovery-Aufruf wird beim Workerstart und per minütlichem Host-Cron ausgeführt. Menschliche Prüfung blockiert keinen Worker.

## Präzision und Eingaben

TXT bis standardmäßig 256 KiB, tatsächlich UTF-8, keine Binär-Steuerzeichen. Ein TXT darf HTML-artigen Text enthalten; dieser wird niemals als HTML interpretiert. PDF bis standardmäßig 8 MiB, geprüft über `%PDF-`-Kopf, `%%EOF`-Ende und MIME-Typ; die Vorschau erfolgt über einen autorisierten Inline-Endpunkt im iframe, der Download mit passender Dateiendung. Der Live-Adapter übergibt ein PDF als Dateianhang an den Anbieter; der Fake liefert weiterhin seine festen Demonstrationswerte. Der Kern-Export schreibt den Payload als JSON; zellensichere Fachformate (z. B. CSV mit Formelzellenschutz) liefert das Domain-Modul.

Die Upload-Obergrenze von PHP/Nginx beträgt 10/12 MiB; `DOCUMENT_MAX_KIB` muss darunter bleiben. Für deutlich größere Dokumente braucht es zusätzlich ein Tokenbudget und gegebenenfalls Segmentierung; v1 segmentiert nicht stillschweigend.

## Versionsauflösung

Implementierter Startstand: Laravel 13.31.0, Filament 5.8.1, Livewire 4.4.4, PHP 8.5.10, PostgreSQL 18.6, Socialite 5.31.0, Microsoft-Provider 4.10.0, Boost 2.9.0, Larastan 3.12.1. Der Composer-Solver wählte Guzzle 7.15.5, weil Socialites OAuth1-Abhängigkeit Guzzle 8 noch nicht zulässt. Keine Abweichung von den vorgegebenen Hauptversionen.

Die anfängliche Installation konnte vorübergehend über PHP 8.5.6 auf dem Alpine-Host erfolgen. Maßgebliche Containerprüfungen laufen mit PHP 8.5.10. Ein beschädigter Composer-Image-Download wurde durch den SHA-256-geprüften offiziellen Composer-PHAR ersetzt. Weitere Prüfergebnisse stehen in verification.md.

## Spätere Erweiterungen

Live-Verarbeitung verwendet Laravel AI SDK 0.11.2 hinter `TaskExtractor` mit den Drivern `OpenAiDriver`, `AzureOpenAiDriver` und `OllamaDriver`; Telescope 5.24.0 ist eine ausschließlich lokale Entwicklungsabhängigkeit. PHPUnit bleibt erhalten. Pulse und Spatie Backup sind als optionale Betriebsprofile dokumentiert, Pennant und Laradock nicht eingebaut. Begründung und Betriebsregeln stehen in [Paketauswahl](packages.md).

- PDF/OCR: PDF-Upload, Vorschau und Anbieterübergabe als Dokumentanhang sind implementiert. Eine Textextraktion oder OCR aus gescannten PDFs ohne Textevorebene gibt es nicht; solche Dateien liefert der Live-Adapter als Anhang, der Fake beantwortet sie mit Demonstrationswerten. Kein PDF-Parser in Filament.
- Chat-Streaming: autorisierter Laravel-Streaming-Endpunkt und kleine gezielte Browserkomponente; getrennte Lebensdauer von Chat und Dokumentenjobs.
- Retrieval: pgvector-Erweiterung in PostgreSQL, getrennte Chunk-/Embedding-Tabellen und dokumentbezogene Berechtigungsprüfung vor Retrieval.
- Python: separater spezialisierter Worker erst bei notwendiger Bibliothek, mit engem versioniertem Auftrag/Ergebnisvertrag und weiterhin zentralen Laravel-Geschäftsregeln.

Diese Erweiterungen sind nicht implementiert. Es gibt keine zusätzlichen Repositories, Event-Busse oder ein generisches Workflow-System.
