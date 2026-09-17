# Neues Kundenprojekt aus diesem Template

1. Repository als neues privates Kundenrepository kopieren. Keine `.env`, Uploads, Datenbankvolumes, Backups, Browser-Traces oder echte Kundendaten übernehmen. Eigene Compose-Projektkennung setzen, damit Volumes und Netzwerke nicht zwischen Installationen geteilt werden.
2. Mit `./bin/dev init` eine lokale Demonstration starten und `./bin/dev check` sowie `npm run test:e2e` ausführen. Kundenspezifische Änderungen zunächst gegen den Fake entwickeln.
3. Name, Oberfläche und extrahierte Felder anpassen. Feldänderungen gemeinsam an Validierung, Ergebnisvertrag, Adapter-Schema, Migrationen, Formular, Export und Tests durchführen. Promptversion erhöhen. Geschäftsaktionen bleiben außerhalb von Filament.
4. Berechtigungsannahme prüfen: derzeit sehen alle aktiven Konten alle Aufgaben und reviewer/admin dürfen selbst erstellte Aufgaben freigeben. Falls der Kunde Abteilungsgrenzen oder Vier-Augen-Prüfung braucht, zentrale Policies und entsprechende negative Tests erweitern.
5. Entra-App im Kundentenant registrieren, Benutzerzuweisung konfigurieren, Secrets bereitstellen und ersten Administrator per CLI aktivieren. Keine lokalen Demo-Konten in die produktive Datenbank kopieren.
6. Bei Live-KI Anbieterfreigabe, Modellzugriff, Kostenlimit, Aufgabenumfang und Qualität mit synthetischen sowie freigegebenen repräsentativen Beispielen prüfen. Ergebnisse bleiben menschlich freizugeben.
7. Eigene APP_KEY-/DB-Geheimnisse, Domain, HTTPS-Proxy, Backup-Ziel, Rotation, Recovery-Cron und Überwachung einrichten. Produktions-Compose verwenden. Einen Restore-Test in einer isolierten Installation durchführen.
8. Die gesamte CI-Suite und die separat beschriebenen externen Entra-/Modelltests ausführen; Ergebnisse und offene Punkte vor Übergabe dokumentieren.

Laravel AI SDK ist bereits integriert; eine zusätzliche SDK-Abstraktion ist nicht erforderlich. Telescope nur lokal bei Bedarf aktivieren und dessen Daten nicht übernehmen. Pulse/Spatie Backup anhand des Kundenbetriebs entscheiden, siehe [Paketauswahl](packages.md). Die LAN-IP eines Entwicklungsservers gehört nicht in die neue Kundenkonfiguration.

Bis ungefähr 400 Konten ist keine Aussage über gleichzeitige Verarbeitung. Workerzahl, PHP-FPM-Pool, Uploadlimit und Providerlimits anhand realer Lastmessung einstellen. Zunächst ein Worker; weitere Worker verwenden dasselbe Image und dieselbe Datenbank-Queue. Die vorhandenen Ausführungs-Leases schützen auch bei mehreren Workern.
