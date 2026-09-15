# Architekturentscheidung: modularer Laravel-Monolith

## Entscheidung

Eine Installation bedient eine Firma mit ungefähr 400 Konten. Daraus wird keine Lastannahme über gleichzeitige Anfragen abgeleitet. Filament und zusätzliche Laravel-Routen teilen sich den `web`-Guard und die datenbankbasierte Session. PostgreSQL speichert Geschäftsdaten, Queue, Cache und Sessions. Nginx und PHP-FPM bedienen HTTP; derselbe Anwendungscode läuft in einem separaten Queue-Worker.

Die Rollen sind bewusst klein und fest: editor darf hochladen, lesen, korrigieren und fehlgeschlagene Extraktionen neu starten. reviewer darf zusätzlich freigeben und freigegebene Ergebnisse exportieren. admin verwaltet zusätzlich Aktivierung und Rollen. Alle aktiven Benutzer sehen alle Dokumente der Firma. Es gibt zunächst keinen Vier-Augen-Zwang, kein Löschen und kein Wiederöffnen freigegebener Dokumente. Unbekannte Entra-Benutzer werden inaktiv als editor angelegt.

## Grenzen und Datenfluss

`UploadDocument`, `StartExtraction`, `ProcessExtraction`, `CorrectDocument`, `ApproveDocument`, `ExportDocument` und `UpdateUserAccess` kapseln die Anwendungsfälle. Filament übernimmt Darstellung; Policies autorisieren einschließlich direkter Aktionsaufrufe. Middleware und Policies aktualisieren das Benutzerobjekt, damit Rollenänderungen und Deaktivierungen bestehende Sitzungen beim nächsten Request erreichen.

Ein Dokument enthält einen privaten Dateipfad, SHA-256, Eingabeversion und Bearbeitungsrevision sowie die aktuell bearbeiteten Rechnungsfelder. Jeder KI-Lauf speichert Startrevisionen, Anbieter/Modell, Promptversion, Versuchszahl, Zeitpunkte, validiertes Originalergebnis und verfügbaren Tokenverbrauch. `applied=false` mit Kategorie `superseded` macht ein erfolgreiches, aber überholtes Ergebnis erkennbar. Audit-Einträge enthalten Akteur, Zeitpunkt und Änderungen; sie sind kein gegen privilegierte Datenbankadministratoren manipulationsgeschütztes Archiv.

Kurze Transaktionen beanspruchen einen Lauf per Lease und Besitzer-UUID. Es folgen Dateiprüfung und Modellaufruf ohne offene Anwendungstransaktion. Der Abschluss sperrt Dokument und Lauf und prüft Eigentümer, Lease, Status und beide Versionen erneut. Korrekturen verwenden dieselbe Dokumentensperre und eine optimistische Revision. Ein erfolgreicher KI-Lauf setzt höchstens `in_review`; nur die Freigabeaktion setzt `approved`.

Laravel-Jobs transportieren nur die Lauf-ID. Drei Modellversuche, Backoff 10/30 Sekunden, keine HTTP-Retries. Timeout-Kette: HTTP 30, Job 60, Lease 90, Queue-Reservierung 120 Sekunden. Der gespeicherte Lauf fungiert zugleich als Dispatch-Absicht: bei einem Prozessabbruch zwischen Commit und Dispatch stellt `ai:recover` verwaiste Absichten nach spätestens 180 Sekunden erneut zu. Überlappungen sind durch die atomare Laufbeanspruchung harmlos. Der Recovery-Aufruf wird beim Workerstart und per minütlichem Host-Cron ausgeführt. Menschliche Prüfung blockiert keinen Worker.

## Präzision und Eingaben

TXT bis standardmäßig 256 KiB, tatsächlich UTF-8, keine Binär-Steuerzeichen. Ein TXT darf HTML-artigen Text enthalten; dieser wird niemals als HTML interpretiert. Geld wird als Dezimalstring validiert und als `numeric(18,4)` gespeichert. ISO-Währung und deren Dezimalstellen werden über Symfony Intl geprüft. Negative Beträge sind für Gutschriften zulässig. Exportwerte mit gefährlichen Formelpräfixen werden als Text markiert.

Die Upload-Obergrenze von PHP/Nginx beträgt 10/12 MiB; `DOCUMENT_MAX_KIB` muss darunter bleiben. Für deutlich größere Dokumente braucht es zusätzlich ein Tokenbudget und gegebenenfalls Segmentierung; v1 segmentiert nicht stillschweigend.

## Versionsauflösung

Implementierter Startstand: Laravel 13.31.0, Filament 5.8.1, Livewire 4.4.4, PHP 8.5.10, PostgreSQL 18.6, Socialite 5.31.0, Microsoft-Provider 4.10.0, Boost 2.9.0, Larastan 3.12.1. Der Composer-Solver wählte Guzzle 7.15.5, weil Socialites OAuth1-Abhängigkeit Guzzle 8 noch nicht zulässt. Keine Abweichung von den vorgegebenen Hauptversionen.

Die anfängliche Installation konnte vorübergehend über PHP 8.5.6 auf dem Alpine-Host erfolgen. Maßgebliche Containerprüfungen laufen mit PHP 8.5.10. Ein beschädigter Composer-Image-Download wurde durch den SHA-256-geprüften offiziellen Composer-PHAR ersetzt. Weitere Prüfergebnisse stehen in verification.md.

## Spätere Erweiterungen

Live-Extraktion verwendet Laravel AI SDK 0.11.2 hinter `DocumentExtractor`; Telescope 5.24.0 ist eine ausschließlich lokale Entwicklungsabhängigkeit. PHPUnit bleibt erhalten. Pulse und Spatie Backup sind als optionale Betriebsprofile dokumentiert, Pennant und Laradock nicht eingebaut. Begründung und Betriebsregeln stehen in [Paketauswahl](packages.md).

- PDF/OCR: eigene Eingabeaufbereitung vor dem Extractor, versioniertes Textergebnis und separate Dateivalidierung. Kein PDF-Parser in Filament.
- Chat-Streaming: autorisierter Laravel-Streaming-Endpunkt und kleine gezielte Browserkomponente; getrennte Lebensdauer von Chat und Dokumentenjobs.
- Retrieval: pgvector-Erweiterung in PostgreSQL, getrennte Chunk-/Embedding-Tabellen und dokumentbezogene Berechtigungsprüfung vor Retrieval.
- Python: separater spezialisierter Worker erst bei notwendiger Bibliothek, mit engem versioniertem Auftrag/Ergebnisvertrag und weiterhin zentralen Laravel-Geschäftsregeln.

Diese Erweiterungen sind nicht implementiert. Es gibt keine zusätzlichen Repositories, Event-Busse oder ein generisches Workflow-System.
