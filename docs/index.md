# Dokumentation des KI-Anwendungstemplates

Die ausführliche Gesamtdokumentation besteht aus zwei zusammengehörigen Dokumenten:

1. **[Handbuch: Was das Template ist, warum es so aufgebaut ist und wie es verwendet wird](template-handbuch.md)** – Funktionsumfang, Technologien, Architektur, Datenmodell, Entra, Rollen, Dokumentenablauf, KI-SDK, Queue, Start, Konfiguration, Tests, Kundenanpassung, Deployment, Sicherung und Wartung.
2. **[Technische Analyse und Weiterentwicklungsplan](template-analyse.md)** – Beurteilung der Umsetzung, konkrete Grenzen, Erfüllungsgrad, priorisierte offene Aufgaben, Paketentscheidungen, Erweiterungskriterien und belastbare Aussagen für Kunden.

Beide Dokumente beschreiben den vorhandenen Quellstand vom 15. September 2026. Implementierte Funktionen, nachgewiesene Prüfungen und mögliche Erweiterungen werden ausdrücklich unterschieden.

| Aufgabe | Passendes Dokument |
| --- | --- |
| Schnell lokal starten | [README](../README.md) |
| Das gesamte Template verstehen | [Handbuch](template-handbuch.md) |
| Eignung und offene Arbeit beurteilen | [Analyse](template-analyse.md) |
| Architekturentscheidung kurz nachlesen | [Architektur](architecture.md) |
| Microsoft-Anmeldung einrichten | [Entra](entra.md) |
| KI-Anbieter und Fehlerfälle konfigurieren | [KI-Adapter](ai.md) |
| Telescope und optionale Pakete verstehen | [Paketauswahl](packages.md) |
| Auf einem Linux-Server betreiben und wiederherstellen | [Deployment/Backup](deployment.md) |
| Neues Kundenprojekt übernehmen | [Kundenprojekt-Checkliste](new-customer.md) |
| Ausgeführte und offene Prüfungen sehen | [Prüfbericht](verification.md) |
| Mit einem Coding-Agenten weiterarbeiten | [AGENTS.md](../AGENTS.md) |

Für Betriebsbefehle sind der jeweilige Umgebungskontext und die in den Dokumenten genannten Voraussetzungen zu beachten. Die passwortlose LAN-Demo ist eine Entwicklungsinstallation; ihre Daten und Geheimnisse werden nicht in neue Kundenprojekte übernommen.
