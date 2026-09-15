# Betrieb, Deployment und Wiederherstellung

## Produktionsaufbau

Nur `compose.yaml` verwenden. Es startet Nginx, PHP-FPM, einen separaten Datenbank-Queue-Worker und PostgreSQL. FPM/DB besitzen keine Host-Portfreigaben. Nginx bindet standardmäßig an `127.0.0.1:8080`. Ein hostseitiger HTTPS-Reverse-Proxy leitet darauf weiter. HTTP aus dem Internet nicht direkt freigeben.

Für dieses Proxy-Modell `WEB_BIND_ADDRESS=127.0.0.1` beibehalten; eine LAN-Freigabe der Demo nicht aus deren `.env` übernehmen. Telescope ist nicht Bestandteil des Produktionsimages und bleibt unabhängig von `TELESCOPE_ENABLED` unerreichbar. Eine lokale Telescope-Datenbank ist keine produktive Quelldatenbank. Optionale Pulse-/Spatie-Erweiterungen sind in [Paketauswahl](packages.md) beschrieben und derzeit nicht aktiviert.

`compose.dev.yaml` bindet Quellcode ein, verwendet Entwicklungsabhängigkeiten und erlaubt ausdrücklich aktivierten lokalen Login. Die Produktionsdatei erzwingt `APP_ENV=production`, `APP_DEBUG=false`, deaktivierten Entwicklungslogin und sichere Session-Cookies unabhängig von entsprechenden `.env`-Werten.

Images sind mehrstufig gebaut; Node dient ausschließlich dem Asset-Build. App und Worker verwenden dasselbe Image. PHP-/PostgreSQL-/Nginx-/Node-Basisimages sind per Digest fixiert; Composer-PHAR per Prüfsumme. Lockfiles werden eingecheckt. Sicherheitsupdates erfordern bewusstes Neubauen mit aktualisierten Versionen/Digests und erneute Prüfung.

## Erste Installation

1. Projekt auf den Linux-Server übertragen, `.env.example` nach `.env` kopieren, Berechtigungen auf `0600` setzen. Eindeutigen DB-Namen/-Benutzer und ein zufälliges starkes DB-Passwort konfigurieren. `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<host>`, `SESSION_SECURE_COOKIE=true`, `DEV_LOGIN_ENABLED=false` setzen. `APP_KEY` einmalig sicher erzeugen, beispielsweise mit `php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'` in einem PHP-Container. Den Schlüssel sichern; bei Deployments nicht neu generieren.
2. Entra und gegebenenfalls den echten KI-Anbieter nach den separaten Anleitungen konfigurieren.
3. Images lokal bauen oder aus der eigenen geprüften Registry beziehen. Keine Schlüssel als Build-Argument übergeben.

```sh
docker compose build app web
docker compose up -d db
docker compose run --rm app php artisan migrate --force
docker compose up -d --no-build app worker web
```

4. Ersten Administrator per CLI anlegen, HTTPS-Zugriff und Rollen prüfen. `db:seed` gehört ausdrücklich nicht zur Produktion.

Der Produktionsprozess läuft als `www-data`. Der private Upload-Volume wird beim erstmaligen Erstellen mit passenden Besitzrechten initialisiert. Das PostgreSQL-18-Volume wird an `/var/lib/postgresql` eingehängt, nicht an den früher üblichen `/var/lib/postgresql/data`-Pfad.

## HTTPS und Proxy-Vertrauen

Ein hostseitiger Nginx/Caddy oder vergleichbarer Reverse Proxy übernimmt Zertifikate und HTTPS. Beispiel für einen bereits eingerichteten hostseitigen Nginx-TLS-Serverblock:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Forwarded-For $remote_addr;
}
```

`TRUSTED_PROXIES` enthält ausschließlich die tatsächlich verwendeten Proxyadressen/Netze, die PHP als `REMOTE_ADDR` sieht (der innere Nginx im Compose-Netz). Kein pauschales `*`. Den Compose-Netzbereich bei Kundeneinrichtung stabil festlegen bzw. dokumentieren. Nur eigene Proxyketten akzeptieren; auf dem TLS-Proxy Client-Header überschreiben. Falsches Proxyvertrauen kann HTTP-Redirects erzeugen oder manipulierte Forwarded-Header erlauben. Zertifikatsausstellung und echte HTTPS-Callbacks wurden lokal nicht getestet.

## Recovery, Healthchecks und Beobachtung

Minütlichen Host-Cron unter einem eingeschränkten Betriebskonto einrichten:

```cron
* * * * * cd /srv/company-ai && docker compose exec -T worker php artisan ai:recover >> /var/log/company-ai-recovery.log 2>&1
```

`ai:recover` besitzt einen Datenbank-Cache-Lock. Der Worker führt es auch beim Start aus. Queue-Reservierungen laufen nach 120 Sekunden ab, verwaiste Dispatch-Absichten werden nach 180 Sekunden erneut zugestellt. Healthchecks prüfen PostgreSQL, FPM-Ping, Web-Startfähigkeit sowie Worker-Prozess/DB/privates Verzeichnis. Compose startet beendete Prozesse neu; `unhealthy` allein bewirkt keinen automatischen Neustart. Healthzustände, Queue-Alter und fehlgeschlagene Läufe müssen durch den Kundenbetrieb überwacht werden.

```sh
docker compose ps
docker compose logs --tail=100 worker
docker compose exec -T app php artisan queue:failed
docker compose exec -T app php artisan app:health
```

Fachliche Wiederholung fehlgeschlagener Läufe erfolgt autorisiert über Filament. Keine massenhafte unkontrollierte Wiederholung via `queue:retry all`. Keine Rohdokumente oder API-Antworten zum Debuggen in Standardlogs schreiben.

## Geordnetes Deployment

Neue Images vor dem Wartungsfenster bauen. Dann den öffentlichen Zugang und Worker geordnet stoppen; 75 Sekunden Abschaltfrist lassen laufende 60-Sekunden-Jobs abschließen. Vor Schemaänderungen Datenbank und Uploads sichern.

```sh
docker compose stop web worker
# Sicherung wie unten erstellen; Web und Worker bleiben bis zum Abschluss gestoppt.
docker compose run --rm app php artisan migrate --force
docker compose up -d --no-build --force-recreate app worker web
```

Das neue App- und Worker-Image muss denselben Release-Tag haben. Schemaänderungen rückwärtskompatibel gestalten; ein Image-Rollback allein macht irreversible Migrationen nicht rückgängig. Der Worker beendet sich zusätzlich stündlich kontrolliert und wird von Compose neu gestartet. Migrationen und Seeds laufen niemals automatisch im Container-Entrypoint.

## Backup

Für eine eigenständige Sicherung ohne gleichzeitiges Deployment:

```sh
./bin/backup /srv/backups/company-ai/2026-09-14
```

Das Skript stoppt Web und Worker, schreibt PostgreSQL-Custom-Dump, privates Dateiarchiv und Prüfsummen und startet erst nach Erfolg wieder. Bei Fehlern bleiben Schreibzugriffe gestoppt. Das Backupverzeichnis muss neu sein. Ein fehlgeschlagener Dump wird nicht als erfolgreiche Sicherung betrachtet.

Die entsprechenden Einzelbefehle im Deployment-Wartungsfenster:

```sh
umask 077
docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > database.dump
docker compose run --rm --no-deps -T app tar -C storage/app/private -czf - . > uploads.tar.gz
sha256sum database.dump uploads.tar.gz > SHA256SUMS
```

Datenbank und Dateien gemeinsam sichern, Backups außerhalb des Servers verschlüsselt aufbewahren. APP_KEY und externe Zugangsdaten separat über den Secret-Manager sichern. Aufbewahrungsdauer und regelmäßigen Restore-Test kundenseitig festlegen. Die Anwendung bietet kein automatisches Point-in-Time-Recovery.

## Restore

Zuerst in einer **separaten leeren Installation** mit eigenem Compose-Projektnamen und eigenen Volumes prüfen. Dieselben passenden Images, APP_KEY und DB-Zugangsdaten bereitstellen; keine laufende Kundeninstallation als Testziel verwenden.

```sh
sha256sum -c SHA256SUMS
docker compose up -d db
docker compose exec -T db sh -c 'pg_restore --no-owner -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < database.dump
docker compose run --rm --no-deps -T app tar -C storage/app/private -xzf - < uploads.tar.gz
docker compose run --rm app php artisan migrate --force
docker compose up -d --no-build app worker web
```

Nach Wiederherstellung Dokumentanzahl, Stichproben, Datei-Prüfsummen, Rollen und Queuezustände prüfen. Vorhandene `running`-/`queued`-Läufe werden über Reservierungsablauf und Recovery behandelt. Produktive Wiederherstellung überschreibt Daten und ist ein gesonderter Betriebsvorgang; dieses Projekt führt sie nicht automatisch durch.
