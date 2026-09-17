# Technische Analyse und Weiterentwicklungsplan

**Bewertung des vorhandenen KI-Anwendungstemplates**

Stand: 16. September 2026 (nach Entkernung zum Task-Kern; Rechnungsdomäne in `examples/invoice-extraction`). Diese Analyse ergänzt das [ausführliche Handbuch](template-handbuch.md). Sie beruht auf einer Durchsicht von Anwendungsklassen, Policies, Authentifizierung, SDK-Driver, Migrationen, Filament-Ressourcen, Compose-/Dockerdateien, Betriebsskripten und Tests. Sie ist keine externe Sicherheitszertifizierung und kein Lasttest.

## 1. Gesamtbewertung

Das Template ist eine sinnvoll begrenzte und lokal geprüfte Grundlage für interne KI-Anwendungen mit menschlicher Prüfung. Seine wesentliche Stärke ist die Verbindung von Anmeldung, Berechtigungen, Hintergrundverarührter Modellaufruf, dessen Antwort unmittelbar als freigegebener Datensatz gilt.

Die Architektur ist für den vorgegebenen Grundfall nachvollziehbar. Laravel, Filament, Datenbank-Queue und PostgreSQL halten die Zahl der Laufzeitdienste klein. Die fachlichen Aktionen liegen in überschaubaren Klassen. Die Oberfläche muss weder eine eigene Identitätsverwaltung noch eine zweite Implementierung der Geschäftsregeln besitzen.

Das Template ist zugleich noch kein fertig abgenommenes Kundenprodukt. Der echte Entra-Tenant, die reale Modellqualität, Betriebsüberwachung, Datenlebenszyklus und Zielhost-Stabilität sind nicht durch die lokale Demonstration bewiesen. Diese offenen Punkte sind keine Nebensache: Sie bestimmen, ob die technische Grundlage zu einer verantwortbar betriebenen Kundenanwendung wird.

Eine sinnvolle Entscheidung lautet daher: **als Ausgangspunkt übernehmen, die fachlichen und betrieblichen Annahmen bewusst prüfen und die kundenspezifische Abnahme einplanen.** Eine bloße Änderung von Logo, Tenant-ID und API-Schlüssel reicht für diese Abnahme nicht aus.

## 2. Was bereits besonders gut gelöst ist

### 2.1 Autorisierung ist mehr als Oberflächensteuerung

Dokumentdownload, Export und Statusabfrage werden autorisiert. Bearbeitende Anwendungsklassen prüfen die Policy erneut. Filament blendet Aktionen zusätzlich aus. Diese Kombination reduziert die Gefahr, dass eine neue Oberfläche oder ein direkt aufgerufener Endpunkt die Geschäftsrechte umgeht.

Die erneute Prüfung des lokalen Benutzerstands unterstützt den praktischen Betriebsfall einer Deaktivierung oder Rollenänderung bei bestehender Sitzung. Der letzte aktive Administrator wird mit einer serialisierten Änderung geschützt. Die entsprechenden negativen Tests sind vorhanden.

Belege: [TaskPolicy](../app/Policies/TaskPolicy.php), [EnsureActiveUser](../app/Http/Middleware/EnsureActiveUser.php), [UpdateUserAccess](../app/Actions/UpdateUserAccess.php), [AccessTest](../tests/Feature/AccessTest.php).

### 2.2 KI-Fehler werden fachlich eingegrenzt

Der Extraktor darf keine Tools ausführen und keine Dokumentfreigabe vornehmen. Modellantworten werden gegen feste Datenregeln geprüft. Geld bleibt präzise repräsentiert. Schemaorientierte Ausgabe und fachliche Validierung ergänzen sich, statt dass eine der beiden Prüfungen als vollständiger Ersatz für die andere betrachtet wird.

Die Integration des Laravel AI SDK ist tatsächlich im Adapter vorhanden und durch HTTP-Fixtures geprüft. Sie beschränkt sich auf den benötigten Anwendungsfall. Der Fake bleibt verfügbar, sodass Entwickler und CI ohne externe Zugangsdaten arbeiten können.

Belege: [LiveTaskExtractor](../app/Ai/LiveTaskExtractor.php), [GeneralTaskAgent](../app/Ai/Agents/GeneralTaskAgent.php), [ValidateTaskPayload](../app/Ai/ValidateTaskPayload.php) (Rechnungsvariante: [examples/invoice-extraction](../examples/invoice-extraction)), [LiveAdapterTest](../tests/Feature/LiveAdapterTest.php).

### 2.3 Nebenläufigkeit wurde nicht auf später verschoben

Ausführungs-Leases, Besitzerkennung, Revisionsvergleich und getrennte Transaktionsphasen behandeln Probleme, die in einer echten Hintergrundverarbeitung regelmäßig auftreten können. Der erfolgreiche Anbieteraufruf allein berechtigt nicht zur Übernahme eines inzwischen veralteten Ergebnisses. Der persistierte Ausführungsverlauf dokumentiert sogar einen gültigen, aber nicht übernommenen KI-Vorschlag.

Die Wiederaufnahme nach einem Absturz verlässt sich nicht allein auf den ursprünglichen Dispatch. Festgeschriebene Ausführungen sind eine wiederauffindbare Verarbeitungsabsicht. Das ist eine kleine, konkrete Zuverlässigkeitsmaßnahme ohne Einführung eines allgemeinen Workflow-Frameworks.

Belege: [ProcessExecution](../app/Actions/ProcessExecution.php), [StartExecution](../app/Actions/StartExecution.php), [RecoverExecutions](../app/Console/Commands/RecoverExecutions.php), [ExecutionTest](../tests/Feature/ExecutionTest.php).

### 2.4 Der Betrieb ist mitgedacht

Web, FPM, Worker und Datenbank sind getrennte Prozesse. Migrationen werden explizit ausgeführt, Daten liegen in Volumes und Entwicklungsabhängigkeiten fehlen im Produktionsimage. Backup und Restore sind beschrieben und wurden in einem vorherigen Implementierungsstand vollständig erprobt. Nach der SDK-/Telescope-Ergänzung wurde ein frischer Produktionsstart gesondert erneut geprüft.

Das vermeidet typische Lücken eines reinen Codebeispiels. Der Betrieb bleibt dennoch bewusst ein einfacher Einzelserverbetrieb mit Wartungsfenstern. Genau diese Begrenzung muss gegenüber einem Kunden deutlich bleiben.

## 3. Erfüllungsgrad der ursprünglichen Anforderungen

| Anforderungsbereich | Bewertung | Präzisierung |
| --- | --- | --- |
| Vorgegebener Stack | Implementiert und lokal geprüft | Laravel 13, Filament 5, Livewire 4, PHP 8.5, PostgreSQL 18 |
| Single-Tenant-Entra-Anmeldung | Implementiert, extern noch abzunehmen | Lokale signierte Fixtures; kein realer Testmandant verwendet |
| Lokale Rollen und Sitzungswirksamkeit | Implementiert und getestet | Drei feste Rollen, keine freie Rollenmodellierung |
| Entwicklungslogin nur lokal/testing | Implementiert und getestet | Produktion zusätzlich über Compose und Routing abgesichert |
| Aufgabenablauf bis JSON | Implementiert und im Browser geprüft | PDF-/TXT-Einzeldatei und Einzel-JSON-Export; Rechnungsfelder und CSV im Beispielmodul |
| KI-Adapter | Fake und SDK-Live-Driver (OpenAI, Azure, Ollama) vorhanden | Nur OpenAI per Fixtures geprüft; kein realer Modellaufruf ausgeführt |
| Wiederholung und Idempotenz | Automatisiert geprüft | Keine Garantie gegen doppelte Anbieterabrechnung |
| Original/Korrektur unterscheidbar | Implementiert | KI-Ergebnis in der Ausführung, aktueller Payload in der Aufgabe, Auditänderungen separat |
| Freigabe und Schreibschutz | Implementiert und getestet | Kein Wiederöffnen, kein Vier-Augen-Zwang |
| Containerbetrieb und Persistenz | Implementiert und lokal geprüft | Einzelserver, keine Hochverfügbarkeit |
| Backup/Restore | Skript und Anleitung; frühere vollständige Betriebsprüfung erfolgreich | Offsite-Ablage, Verschlüsselung und Betriebskalender bleiben offen |
| CI | Konfiguration vorhanden | Externer Runner noch nicht ausgeführt |
| Agentenunterstützung | Boost und AGENTS.md vorhanden | Kein Produktionsbedarf an diesen Werkzeugen |
| Kundenspezifische Produktionsfreigabe | Noch offen | Identität, Daten, Qualität, Betrieb und Zielhost separat abnehmen |

Die Einstufung „implementiert“ bedeutet, dass der entsprechende Code vorhanden ist. „Getestet“ bezieht sich auf die im [Prüfbericht](verification.md) genannten Prüfungen. Sie bedeutet nicht, dass sämtliche denkbaren Browser, Providerantworten oder Betriebsausfälle vollständig untersucht wurden.

## 4. Präzise Grenzen der aktuellen Implementierung

### 4.1 Promptversionierung ist eine Kennzeichnung, noch keine historische Ausführung

Eine KI-Ausführung speichert `prompt_version`. Der aktuelle Agent baut seine Instruktion jedoch aus dem gegenwärtigen Quellcode und hängt die Kennzeichnung an. Er besitzt keine Zuordnung von alten Versionskennungen zu archivierten alten Prompttexten. Nach einer späteren Änderung des Agentencodes könnte eine wartende Ausführung mit alter Kennzeichnung bereits neue Instruktionen erhalten.

Für die heutige einzelne Promptversion ist das kein nachgewiesener Fehler im Demoablauf. Für einen regelmäßig weiterentwickelten Kundenbetrieb ist es jedoch eine relevante Grenze der Reproduzierbarkeit. Vor häufigen Promptänderungen sollten alte Ausführungen kontrolliert abgeschlossen oder eine echte versionsabhängige Promptauflösung eingeführt werden. Optional kann zusätzlich ein Hash des tatsächlich verwendeten Prompts gespeichert werden.

Ähnlich bleiben Modell und Treiber pro Ausführung festgelegt, nicht aber die damalige Anbieter-URL oder eine vollständige Adapterversion. Geheimnisse sollen weiterhin nicht in der Ausführung archiviert werden. Für technische Nachvollziehbarkeit kann stattdessen eine ungefährliche Kennzeichnung des Konfigurations- oder Softwarereleases dienen.

### 4.2 Eine Ausführung ist kein vollständiges Versuchstagebuch

`executions` speichert die Anzahl der Versuche, einen aktuellen Fehlerzustand und Zeitpunkte. `started_at` wird beim nächsten Versuch aktualisiert. Die Tabelle enthält keine eigene Zeile mit Dauer, Fehler und Verbrauch für jeden einzelnen Versuch. Ein manueller Neustart erzeugt zwar eine neue Ausführung, automatische Wiederholungen bleiben aber innerhalb desselben Ausführungsdatensatzes.

Das genügt zur Demonstration des Wiederholungsbudgets. Für detaillierte Kostenanalyse oder die Untersuchung schwankender Anbieterantwortzeiten kann eine schmale zusätzliche Versuchstabelle sinnvoll sein. Sie sollte nur erforderliche Metadaten enthalten, keine vollständigen Dokumente oder geheimnishaltigen Rohantworten.

### 4.3 Formale Validität ist keine fachliche Richtigkeit

Der Kern prüft nur die Containerform des Payloads (Objekt, Feldzahl, skalare Werte, Konfidenzbereich). Fachliche Richtigkeit steuert das Domain-Modul bei: Das Rechnungsbeispiel kann feststellen, ob `123.45` ein zulässiger EUR-Betrag ist, aber nicht ohne Stammdaten, ob dieser Betrag auf der Rechnung Gesamtbetrag, Nettobetrag oder ein zufällig ähnlicher Zahlenwert ist. Auch Lieferanten- und Dublettenprüfungen sind kundenspezifisch.

Vor Kundenbetrieb ist deshalb ein Bewertungsdatensatz besonders wertvoll. Er sollte unterschiedliche Lieferanten, fehlende Felder, mehrere Beträge, ungewöhnliche Datumsformate, Gutschriften und irreführende Texte enthalten. Die Bewertung muss pro Feld und für den gesamten Datensatz sichtbar machen, welche Fehler auftreten und wie viel menschliche Nacharbeit entsteht. Ein allgemeiner Erfolgsstatus des API-Aufrufs ist dafür kein Ersatz.

### 4.4 Benutzerführung ist bewusst minimal

Der komplette Grundablauf ist vorhanden. Nicht vorhanden sind frei speicherbare unvollständige Entwürfe, Sammelupload, Sammelfreigabe, Sammel-Export, Wiedervorlagen, Kommentare, Benachrichtigungen, Bearbeitungszuweisung oder ein Vier-Augen-Prozess. Eine Datei kann mehrfach hochgeladen werden; die Prüfsumme dient der Integritätskontrolle und erzwingt keine Dublettenvermeidung.

Die englischen technischen Ausführungsstatus und Fehlerkategorien sind nachvollziehbar, aber noch keine vollständig redaktionell ausgearbeitete Fachanwenderkommunikation. Für einen Kunden können deutsch formulierte Handlungshinweise wie „Anbieter vorübergehend nicht erreichbar; nächster Versuch folgt“ nützlicher sein als allein `provider_unavailable`. Die technischen Kategorien sollten dabei erhalten bleiben.

### 4.5 Datenlebenszyklus und Archivanforderungen sind offen

Audit und freigegebene Aufgaben sind auf Anwendungsebene nachvollziehbar und geschützt. Es gibt keine manipulationssichere Archivierung, keine definierte Löschfrist und keine vollständige Übersicht zur Aufbewahrung aller Datenklassen. Originale, aktuelle Payloads, KI-Ergebnisse, Auditwerte, Backups und Diagnosedaten benötigen jeweils eine bewusste Regel.

Eine spätere Löschfunktion muss diese Beziehungen berücksichtigen. Nur die Originaldatei zu entfernen würde beispielsweise noch Payload und Auditwerte zurücklassen. Eine solche Funktion sollte erst nach der fachlichen Entscheidung über Historie, Freigaben und Nachweise implementiert werden.

### 4.6 Dateisystem und Datenbank können getrennt fehlschlagen

Der Upload räumt die Datei bei normalen Transaktionsfehlern auf. Ein harter Prozessabbruch kann diese Aufräumlogik verhindern. Ein Abgleich zwischen gespeicherten Pfaden und vorhandenen Dateien sowie eine kontrollierte Behandlung verwaister Dateien ist noch nicht vorhanden.

Umgekehrt erkennt die Verarbeitung eine fehlende oder veränderte Datei, kann sie aber nicht automatisch wiederherstellen. Dafür sind Backup und Betriebsdiagnose zuständig. Der SHA-256-Wert ist eine Integritätsreferenz, keine zweite Kopie des Inhalts.

### 4.7 Der Datenbank- und Serverbetrieb ist konzentriert

Geschäftsdaten, Sessions, Cache und Queue teilen sich PostgreSQL. Das spart Infrastruktur, konzentriert aber Last und Ausfallfolgen. Zehn mögliche FPM-Kinder und ein Worker sind konfigurierbare Startwerte. Ihre Eignung für einen konkreten Server wurde nicht mit einem Kundenlastprofil nachgewiesen.

Eine belastbare Kapazitätsbewertung benötigt typische Requesthäufigkeiten, Dateigrößen, gleichzeitig prüfende Benutzer, Modelllaufzeiten und zulässige Queuewartezeit. Die Zahl von 400 Konten beantwortet keine dieser Fragen automatisch.

### 4.8 Einige Generatorreste sind noch vorhanden

In den Filament-Verzeichnissen bestehen zusätzliche generierte Schema-, Tabellen- und Seitenklassen, die von den tatsächlich registrierten Ressourcen teilweise nicht verwendet werden. Beispielsweise definiert `ExecutionResource` seine aktive Tabelle und Infolist selbst; die leeren generierten Klassen daneben sind nicht die aktive Ausführungsübersicht. Create-/Edit-Dateien bedeuten ebenfalls nicht automatisch, dass entsprechende Ressourcenrouten freigegeben sind.

Das ist vor allem ein Wartbarkeitsthema. Eine gezielte Bereinigung unbenutzter Generatorreste würde die Orientierung verbessern. Sie sollte anhand der tatsächlichen Referenzen und Ressourcenrouten erfolgen, nicht durch pauschales Löschen aller ähnlich benannten Dateien.

## 5. Offene Aufgaben nach Priorität

Die folgende Priorisierung ist eine technische Einschätzung für die Entwicklung zu einem Kundenprodukt. Sie unterscheidet erforderliche Abnahme von optionaler Funktionserweiterung. Sie behauptet keine aktuell ausgenutzte Sicherheitslücke.

| Priorität | Aufgabe | Warum | Konkreter Abschlussnachweis |
| --- | --- | --- | --- |
| Vor Kundenbetrieb | Echten Entra-Testmandanten und anschließend Kundenkonfiguration prüfen | Lokale Fixtures decken Mandantenrichtlinien und tatsächliche Redirects nicht ab | Dokumentierte Positiv-/Negativanmeldungen und Rotation |
| Vor Kundenbetrieb | Reale KI-Qualität auf freigegebenen Beispielen bewerten | Strikte JSON-Ausgabe beweist keine korrekte Verarbeitung | Bewertungsdatensatz und vereinbarte Akzeptanzkriterien |
| Vor Kundenbetrieb | Konfigurierten Live-Driver abnehmen | Nur OpenAI ist per HTTP-Fixtures geprüft; Azure/Ollama wurden nie echt aufgerufen | Dokumentierter Positiv-/Negativlauf je eingesetztem Driver |
| Vor Kundenbetrieb | Zielhost-Stabilität untersuchen | Im bisherigen Umfeld wurden native PHP-/Analyse-Speicherfehler beobachtet | Wiederholbare stabile Builds, Prüfungen und kontrollierte Laufzeitbeobachtung |
| Vor Kundenbetrieb | HTTPS, Proxyvertrauen und Zugangsgrenzen abnehmen | Die lokale LAN-Demo ist kein produktiver Zugang | Tatsächlicher HTTPS- und Callback-Test |
| Vor Kundenbetrieb | Backupziel, Verschlüsselung, Recovery-Cron und Alarmierung betreiben | Dokumentierte Befehle laufen nicht automatisch | Geplanter Sicherungslauf, Fehlermeldung und isolierter Restore |
| Vor Kundenbetrieb | Datenzugriff und Freigaberegeln bestätigen | Alle aktiven Konten sehen aktuell alle Aufgaben; Eigenfreigabe erlaubt | Fachlich freigegebene Rollenmatrix und passende negative Tests |
| Vor Verteilung des Templates | Releasekennung und Zuständigkeit festlegen | Der vorbereitete Git-Ausgangsstand benötigt einen geregelten Releaseprozess | Nachprüfbare Quellrevision mit Lockfiles und Prüfbericht |
| Vor regelmäßigen Promptupdates | Historische Promptauflösung oder kontrolliertes Leerlaufen der Queue | Versionslabel allein konserviert den alten Prompt nicht | Test mit wartender alter Ausführung während Versionswechsel |
| Nächste Wartungsrunde | Portable Backup-Prüfsummen und klare Backupfehlerbehandlung verbessern | Absolute Pfade erschweren Prüfung an anderem Restore-Ort | Archivprüfung nach Kopieren in einen anderen Pfad |
| Nächste Wartungsrunde | Generatorreste und verbleibende Beispieldateien sichten | Weniger Mehrdeutigkeit für spätere Entwickler | Referenzprüfung und weiterhin grüne Suite |
| Bei fachlichem Bedarf | Teilentwürfe, Kommentare oder Vier-Augen-Freigabe | Erweitert tatsächliche Sachbearbeitung statt Infrastruktur um ihrer selbst willen | Konkrete Fachfälle und Browser-/Policytests |
| Bei Betriebsbedarf | Versuchshistorie, Kostenmetriken und ggf. Pulse | Bessere Ursachen- und Kapazitätsanalyse | Nachvollziehbare Metriken ohne vertrauliche Nutzdaten |

## 6. Bewertung der zusätzlich diskutierten Pakete

### Laravel AI SDK: sinnvoll eingesetzt, bewusst begrenzt

Der SDK-Einsatz ist für ein KI-Template nachvollziehbar, weil die Anbindung an Modelle zum Kernzweck gehört. Der vorhandene Adapter nutzt ihn bereits tatsächlich. Die fachliche Schnittstelle verhindert, dass Filament und Geschäftsaktionen von allen SDK-Details abhängig werden.

Die vor-1.0-Version verlangt bewusste Updates. Die drei Driver (`OpenAiDriver`, `AzureOpenAiDriver`, `OllamaDriver` hinter `LlmDriver`) teilen sich bewusst einen schmalen Vertrag: genau ein Driver pro Ausführung, keine SDK-Queue, kein Failover. Nur der OpenAI-Weg ist per HTTP-Fixtures geprüft; Azure und Ollama sind konfigurierbar, aber ohne echten Aufruf abgenommen. Der derzeitige kleine Adapter mit klaren Tests ist deshalb eine angemessene Grenze.

### Telescope: nützlich als lokale Hilfe

Telescope unterstützt Entwickler bei Request- und Query-Laufzeiten. Die Standardbreite seiner Aufzeichnungen wäre für vertrauliche Aufgaben unnötig. Die implementierte Beschränkung auf bereinigte Metadaten ist deshalb fachlich begründet. Sie reduziert zugleich den Diagnoseumfang: Wer vollständige SQL-Bindings oder Modellantworten erwartet, erhält diese absichtlich nicht.

Telescope ersetzt keine Produktionsüberwachung. Der bewusste Ausschluss aus Produktion vermeidet zudem eine zusätzliche administrative Oberfläche und Entwicklungspakete im Kundenbetrieb. Funktionsweise und Standardoptionen beschreibt die [offizielle Telescope-Dokumentation](https://laravel.com/docs/13.x/telescope); die konkreten Einschränkungen stehen in [Paketauswahl](packages.md).

### PHPUnit/Pest: Werkzeugkonsistenz vor zusätzlicher Installation

PHPUnit prüft die relevanten Verhaltensfälle bereits. Pest kann eine passende Teamsyntax sein, liefert aber nicht allein durch seine Installation zusätzliche fachliche Tests. Deshalb gibt es derzeit keinen zwingenden Grund, die vorhandene Suite umzuschreiben oder parallel zwei Schreibweisen einzuführen. Eine bewusste Teamentscheidung kann diese Bewertung später ändern.

### Pulse und Spatie Backup: Betriebskonzept zuerst festlegen

Pulse kann bei der regelmäßigen Beobachtung mehrerer Installationen nützlich werden. Spatie Backup kann Sicherungsziele, Bereinigung und Benachrichtigungen in Laravel zusammenführen. Beide schaffen aber erst mit konkreter Konfiguration, laufenden Hintergrundaufgaben, Aufbewahrung und Zuständigkeit einen vollständigen Nutzen.

Für Spatie muss insbesondere der ausführende Container die private Dateimenge und einen passenden PostgreSQL-Dumpclient erreichen. Für Pulse müssen Recorder und Zugriffsschutz zum Datenschutz- und Betriebskonzept passen. Die [optionalen Profile](packages.md) beschreiben diese Voraussetzungen. Der aktuelle Stand installiert keines dieser Pakete.

### Pennant und Laradock: kein gegenwärtiger fachlicher Bedarf

Feature-Flags sind sinnvoll, wenn eine Funktion unabhängig vom Deployment schrittweise aktiviert werden muss. Sie ersetzen keine Rollenrechte. Der heutige feste Aufgabenprozess benötigt noch keine eigene Flag-Lebensdauer. Pennant wäre daher momentan eine zusätzliche Konfiguration ohne konkret gezeigten Anwendungsfall.

Laradock würde neben dem bereits vorhandenen kleinen Compose-Aufbau einen weiteren Entwicklungsstandard einführen. Für diesen Ausgangspunkt ist eine zweite Infrastrukturvariante kein erkennbarer Vorteil. Ein Kunde mit einem verbindlichen anderen Standard kann den Entwicklungsaufbau später gezielt ersetzen.

## 7. Erweiterungen mit klaren Eintrittskriterien

| Erweiterung | Vorher zu klärende Frage | Empfohlene Architekturgrenze | Was zu vermeiden ist |
| --- | --- | --- | --- |
| PDF/OCR | Welche Erkennungsqualität wird für Scans ohne Textebene benötigt? | Texterkennung erzeugt versionierten Text vor dem Extractor | Parser- und OCR-Aufrufe direkt in Filament |
| Chat mit Streaming | Welche Gesprächsdaten und Abbruchregeln gelten? | Eigener autorisierter Anwendungsfall und Streaming-Endpunkt | Ausführungs-Jobs dauerhaft für offene Chats reservieren |
| Retrieval | Welche Quellen dürfen welche Benutzer finden? | Berechtigte Aufgaben, Chunks und Embeddings; gegebenenfalls pgvector (Grundstein: `task_chunks`, `RetrieveChunks` mit Prüfung vor Abruf, noch ohne Embeddings) | Zugriffsschutz erst nach dem Abruf vertraulicher Treffer anwenden |
| Python-Worker | Welche konkrete Bibliothek lässt sich sonst nicht sinnvoll einsetzen? | Enger versionierter Auftrag-/Ergebnisvertrag | Benutzerrechte und Freigaben in einem zweiten Dienst duplizieren |
| ERP-Anbindung | Was bestätigt das Zielsystem, und wie werden Wiederholungen erkannt? | Eigene Export-/Übertragungsaktion mit Zustellstatus | Einen JSON-Erzeugungs-Auditeintrag als ERP-Buchungsbestätigung verwenden |
| Mehrere Kunden in einer Anwendung | Welche Isolation und Betriebsvorteile werden tatsächlich gebraucht? | Neue Tenantarchitektur mit umfassenden negativen Zugriffstests | Lediglich `company_id` in einige Tabellen schreiben |

Ein sinnvoller Erweiterungspunkt bewahrt die vorhandenen Grenzen. Beispielsweise darf ein Python-OCR-Ergebnis Text liefern, aber nicht durch eine zweite Rollenverwaltung entscheiden, dass eine Aufgabe freigegeben ist. Ein Chat darf Tools benötigen; daraus folgt nicht, dass der bestehende Aufgabenextractor plötzlich Aktionen ausführen darf.

## 8. Ein praktikables Weiterentwicklungsverfahren

Eine Änderung beginnt mit einem überprüfbaren Verhaltenssatz und den betroffenen Daten. Danach wird bestimmt, welche Policies, Anwendungsklassen, Adapter und Darstellungen tatsächlich geändert werden müssen. Die Tests werden so gewählt, dass sie das neue Verhalten und seine verbotenen Gegenfälle abdecken.

Anschließend erfolgen Implementierung, gezielte Tests, Formatierung, statische Analyse und die vollständige reguläre Suite. Browserprüfungen sind besonders bei Upload, Formularinteraktion, Freigabe und Sessionverhalten relevant. Betriebsprüfungen werden dann wiederholt, wenn Jobs, Images, Migrationen, Persistenz oder Wiederherstellung betroffen sind. Ein echter Provideraufruf bleibt eine gesonderte Integration mit eigenen Zugangsdaten und kontrollierten Beispielen.

Die Übergabe einer Änderung sollte erklären, welches Verhalten nun anders ist, welche bestehenden Daten betroffen sind, wie migriert wird und welche Nachweise vorliegen. Bei Modell- oder Promptänderungen gehören fachliche Qualitätsvergleiche dazu. Bei einem reinen Dokumentationsupdate ist dagegen kein erneuter Workerabbruch gegen eine gerade benutzte Demo erforderlich.

Für dieses Dokumentationsvorhaben wurden vorhandener Code und Prüfbelege ausgewertet und die neuen Dokumente auf interne Verweise und Struktur geprüft. Die Anwendung wurde dadurch nicht fachlich verändert. Die zuvor berichteten 103 Tests (444 Assertions) und Browser-/Produktionsprüfungen bleiben historische Nachweise des beschriebenen Implementierungsstands und werden nicht als in diesem Dokumentationsschritt neu ausgeführt dargestellt.

## 9. Welche Zusagen man gegenüber einem Kunden machen kann

Vertretbar ist die Aussage, dass eine lokal geprüfte technische Grundlage mit Entra-Anbindung, Rollen, privaten Dateien, kontrollierter KI-Verarbeitung, menschlicher Freigabe, Tests und vorbereitetem Containerbetrieb vorliegt. Ebenso vertretbar ist, dass zentrale Integrationsprobleme bereits behandelt werden und neue Projekte darauf aufbauen können.

Nicht belegt wären pauschale Aussagen wie „vollständig produktionsreif“, „für 400 gleichzeitige Nutzer getestet“, „revisionssicheres Archiv“, „KI-Ergebnisse sind korrekt“, „automatisch datenschutzkonform“ oder „alle KI-Anbieter funktionieren ohne Abnahme“. Auch eine bestimmte Betriebskostenersparnis oder Verfügbarkeit wurde nicht gemessen.

Eine überzeugende Beschreibung gewinnt daher durch Präzision: Das Template reduziert wiederkehrende technische Integrationsarbeit und bietet nachvollziehbare Schutzmechanismen. Die fachliche Eignung, tatsächliche Modellqualität und Betriebsabnahme werden gemeinsam mit dem Kunden auf dieser Grundlage erarbeitet.
