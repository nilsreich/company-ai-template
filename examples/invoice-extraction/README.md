# Beispiel: Rechnungsextraktion

Dieses Verzeichnis enthält die aus dem App-Kern ausgelagerte Rechnungsdomäne.
Der Kern (`App\…`) kennt nur generische Tasks mit JSON-Payload; alles
Rechnungsspezifische lebt hier und dient als Vorlage für eigene Domain-Module.

## Inhalt

| Datei | Zweck |
| --- | --- |
| `src/Agents/InvoiceExtraction.php` | Strukturierter SDK-Agent (striktes Schema, 1 Schritt) |
| `src/Validation/InvoiceValidator.php` | Fachliche Feldregeln (Datum, Dezimalstring, ISO-Währung, IBAN-Format) |
| `src/Support/InvoiceFieldAssessment.php` | Rechenprüfung (Netto + Steuer = Brutto), IBAN Mod-97, Ampellogik |
| `src/Export/InvoiceCsvExporter.php` | CSV-Export mit Formelzellenschutz |
| `src/Actions/SubmitGoldenDataset.php` | Freigegebenen Stand als Eval-Fixture sichern |
| `src/Console/EvaluateInvoice.php` | `ai:eval`: Trefferquote gegen den Golden-Datensatz messen |
| `src/Filament/InvoiceTaskForm.php` | Feldgenaue Korrekturmaske (statt generischem JSON-Editor) |
| `src/Filament/InvoiceTaskInfolist.php` | Feldgenaue Prüfung mit Ampel und Konfidenz |
| `src/Filament/Pages/EditInvoiceTask.php` | Edit-Seite, die 8 Felder auf den Payload abbildet |

## Verdrahtung (Kern → Rechnung)

1. `composer dump-autoload` (Namespace `Examples\InvoiceExtraction\…` ist registriert).
2. `.env`: `AI_AGENT=Examples\InvoiceExtraction\Agents\InvoiceExtraction`,
   `AI_VALIDATOR=Examples\InvoiceExtraction\Validation\InvoiceValidator`.
   Der Kern löst Agent und Validator aus `config/ai.php` auf; neue Ausführungen
   nutzen automatisch Schema und Regeln des Beispiels.
3. Optional Filament: In `TaskResource::form()` `InvoiceTaskForm::configure()`
   verwenden, `EditTask` durch `EditInvoiceTask` ersetzen und die Infolist um
   `InvoiceTaskInfolist::entries()` ergänzen.
4. Optional Export/Eval: `InvoiceCsvExporter` statt `ExportTask` aufrufen,
   `EvaluateInvoice` nach `app/Console/Commands` kopieren (registriert `ai:eval`).

Alte Läufe behalten Modell, Promptversion und Treiberkennung; ein Wechsel wirkt
nur für neue Ausführungen. Echte Modellqualität immer an freigegebenen
Beispielen messen (`ai:eval` mit `--allow-external` nur isoliert ausführen).
