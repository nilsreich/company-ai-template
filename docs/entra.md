# Microsoft Entra ID einrichten

## Manuelle Voraussetzungen

1. Im vorgesehenen Entra-Mandanten eine App registrieren: **Nur Konten in diesem Organisationsverzeichnis**. Keine persönliche Microsoft-Anmeldung und kein `common`/`organizations`-Tenant.
2. Tenant-ID und Anwendungs-/Client-ID als UUIDs übernehmen. Ein Client-Secret mit passender Laufzeit erstellen und sicher hinterlegen; niemals in Git oder Support-Logs kopieren. Ablauf überwachen. Rotation: zweites Secret erstellen, Konfiguration aktualisieren, App/Worker neu erstellen, Anmeldung testen, altes Secret widerrufen.
3. Als **Web**-Redirect exakt `https://<kundenhost>/auth/entra/callback` registrieren. Für einen getrennten lokalen Test ist `http://localhost:8080/auth/entra/callback` möglich. Scheme, Host, Port und Pfad müssen mit `MICROSOFT_REDIRECT_URI` übereinstimmen.
4. Delegierte Berechtigungen `openid`, `profile` und Microsoft Graph `User.Read` verwenden. Abhängig von den Mandantenrichtlinien Administratorzustimmung erteilen. Keine Gruppen-/Directory-Leserechte nötig.
5. In der Enterprise Application **Zuweisung erforderlich** aktivieren und die vorgesehenen Benutzer/Gruppen zuweisen. MFA und Conditional Access werden zentral in Entra eingerichtet.

```dotenv
MICROSOFT_TENANT_ID=<Tenant-UUID>
MICROSOFT_CLIENT_ID=<Client-UUID>
MICROSOFT_CLIENT_SECRET=<aus dem Secret-Manager>
MICROSOFT_REDIRECT_URI=https://<kundenhost>/auth/entra/callback
```

`APP_URL`, HTTPS-Proxy und sichere Session-Cookies entsprechend konfigurieren. Keine produktiven Demo-Seeds ausführen.

## Erster Administrator

Die **Object-ID des Benutzers im konfigurierten Tenant** im Entra-Portal unabhängig verifizieren. Dann auf dem Server ausführen:

```sh
docker compose run --rm app php artisan app:bootstrap-admin '<Benutzer-Object-UUID>' --name='Vorname Nachname'
```

Das Kommando aktiviert genau diese Identität, protokolliert den CLI-Ursprung und verweigert eine weitere Bootstrap-Vergabe, sobald ein aktiver Administrator existiert. Es gibt keine Bootstrap-Webroute. Danach kann der Administrator andere, nach erster Anmeldung angelegte Konten aktivieren und deren Rollen setzen. Der letzte aktive Administrator kann nicht deaktiviert oder herabgestuft werden.

## Ablauf und Schutz

Socialite erzeugt Session-State und PKCE; die Anwendung erzeugt zusätzlich eine Nonce. Nach Rückkehr tauscht das Paket den Code serverseitig aus, liest das Profil und validiert die ID-Token-Signatur über Microsofts JWKS. Die Anwendung verlangt zusätzlich exakte Client-Audience, konfigurierten Tenant, passenden Issuer, Zeitgültigkeit, Object-ID und die einmalige Nonce. Das ist insbesondere nötig, weil Provider 4.10.0 intern die Audience nur per Teilstringvergleich prüft.

Die Identität ist `(tid, oid)`. Name und E-Mail dürfen sich ändern. E-Mail-Domänen, Gruppen und externe Rollen erteilen keine lokalen Berechtigungen. Neue Konten bleiben bis zur manuellen Aktivierung inaktiv. Login regeneriert die Laravel-Session; Logout invalidiert die lokale Session. Ein vollständiger globaler Entra-Logout ist nicht implementiert.

Quellen: [Authorization Code Flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow), [ID-Token-Claims](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference), [Microsoft-Provider](https://socialiteproviders.com/Microsoft/), [installierter Provider-Code](https://github.com/SocialiteProviders/Microsoft/blob/4.10.0/Provider.php).

## Separate externe Abnahme

Mit einem echten Testmandanten prüfen: zugewiesener Benutzer, nicht zugewiesener Benutzer, fremder Tenant, aktiviertes/inaktives Konto, E-Mail-Änderung bei identischer Object-ID, neue Session nach Logout und Secret-Rotation. Die reguläre Testsuite prüft signierte lokale Token-/JWKS-Fixtures einschließlich falscher Signatur, Audience, Tenant, Issuer, Nonce und Zeitgrenzen. Sie ersetzt diese externe Mandantenprüfung nicht.
