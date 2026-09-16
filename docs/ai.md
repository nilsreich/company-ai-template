# KI-Adapter

Standard ist `AI_DRIVER=fake`. Für den echten Adapter werden `AI_DRIVER=live`, `AI_API_KEY`, `AI_MODEL` und optional `AI_TIMEOUT` gesetzt. Nach einer Änderung App und Worker neu erstellen. Der API-Schlüssel wird niemals in einem KI-Lauf gespeichert.

Der Live-Adapter verwendet `laravel/ai` 0.11.2 als Laufzeitabhängigkeit. `InvoiceExtraction` wurde mit `php artisan make:agent InvoiceExtraction --structured` erzeugt und auf ein striktes Schema ohne Tools oder Gesprächsverlauf begrenzt. Der Adapter ruft den SDK-Provider mit einem `AgentPrompt`, dem gespeicherten Modell und der Promptversion des Laufs auf. Der SDK baut den Request und dekodiert strukturierte Antworten. `ValidateExtraction` prüft danach weiterhin sämtliche fachlichen Regeln. Quelle: [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk).

`DocumentOpenAiGateway` ergänzt den SDK-Transport um fünf Sekunden Verbindungs-Timeout, abgeschaltete HTTP-Redirects und die Prüfung auf vollständige bzw. verweigerte Antworten vor der Dekodierung. Ein Provider, maximal ein Schritt und keine HTTP-Retries: ausschließlich der bestehende Datenbank-Job entscheidet über weitere Versuche. Keine SDK-Queue, kein Failover und keine automatische Conversation-Speicherung; die SDK-Conversation-Migrationen werden dafür nicht benötigt. Konfiguration bleibt in `config/ai.php`; der Adapter überführt den bestehenden vollständigen `AI_URL`-Endpunkt in die SDK-Basis-URL. Es sind keine zusätzlichen `OPENAI_*`-Variablen erforderlich.

Die stabile Paketveröffentlichung ist noch vor Version 1.0. Der Einsatz erfolgt bewusst bereits jetzt; `^0.11.2` erlaubt keine automatische Aktualisierung auf 0.12, und `composer.lock` fixiert die getestete Version. Bei einem SDK-Upgrade insbesondere strukturierte Ausgabe, Transportoptionen, Fehlerklassen und Zahl der HTTP-Aufrufe erneut prüfen. Die SDK-Breite bedeutet keine Freigabe zusätzlicher Anbieter im Template.

Der Live-Adapter unterstützt ausschließlich die dokumentierte OpenAI Responses API: `POST https://api.openai.com/v1/responses`, `text.format.type=json_schema`, `strict=true`, ohne Tools und mit `store=false`. `AI_URL` bezeichnet diesen Endpunkt; es ist keine Zusage für Azure OpenAI oder andere „OpenAI-kompatible“ Dienste. Der Standard `gpt-4.1-mini-2025-04-14` ist ein zentral konfigurierter Snapshot mit strukturierter Ausgabe. Modellverfügbarkeit und Kontolimits sind kundenseitig zu prüfen.

Quellen: [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs), [Modell](https://developers.openai.com/api/docs/models/gpt-4.1-mini), [Datenverwendung](https://developers.openai.com/api/docs/guides/your-data).

`store=false` deaktiviert die zusätzliche Speicherung für spätere API-Abfragen; es ersetzt keine Prüfung der Anbieterbedingungen oder kundenspezifischen Aufbewahrungsregeln. Das gesamte Dokument wird an den Anbieter übermittelt. Der Live-Betrieb muss daher für die betreffenden Kundendaten freigegeben sein.

## Fake und Fehler

- `success`: nach `AI_FAKE_DELAY=8` Sekunden reproduzierbare Demonstrationswerte.
- `timeout`: alle Versuche schlagen mit temporärem Timeout fehl.
- `rate_limit`: erster Versuch fehlschlägt, der nächste ist erfolgreich.
- `invalid`: Pflichtfelder fehlen; serverseitige Validierung lehnt ab, ohne Retry.

Tests setzen `AI_FAKE_DELAY=0`. Die Szenariowahl stammt aus Konfiguration, nicht aus hochgeladenen Dokumentanweisungen.

Fehlerkategorien: `timeout`, `rate_limit`, `provider_unavailable` sind vorübergehend; `provider_rejected`, `refused`, `incomplete`, `invalid_result`, `configuration`, `input_integrity`, `input_missing`, `stale_input` sind fachlich bzw. technisch zu prüfen. `worker_interrupted` kennzeichnet ausgeschöpfte Wiederanläufe, `superseded` ein nicht mehr übernommenes Ergebnis. `internal_error` erfordert eine Untersuchung ohne Ausgabe vertraulicher Nutzdaten.

Der gespeicherte Anbieter und das gespeicherte Modell bestimmen auch spätere Versuche desselben Laufs. Ein manueller Neustart legt einen neuen Lauf an. API-Aufrufe können bei Verbindungsabbrüchen anbieterseitig bereits verarbeitet worden sein; fachliche Idempotenz verhindert doppelte Dokumentänderungen, nicht zwangsläufig doppelte Abrechnung.

## Separater echter Integrationstest

1. Einen separaten Testaccount/API-Schlüssel mit Ausgabenlimit bereitstellen.
2. In einer isolierten Installation auf `live` umstellen.
3. Eine synthetische Rechnung (TXT oder PDF) hochladen und Tokenverbrauch, validiertes Ergebnis, Bearbeitung und Freigabe prüfen.
4. Schlüssel anschließend entfernen/rotieren und wieder auf `fake` schalten.

Diese externen Schritte sind nicht Teil der regulären Suite und wurden ohne bereitgestellte Zugangsdaten nicht ausgeführt.
