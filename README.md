# commonsbooking-extended

The repository name is `commonsbooking-extended`; the WordPress plugin slug
and update slug remain `cb-additional-features` so existing installations and
the private update server continue to recognize the plugin.

Dieses WordPress-Plugin erweitert das **CommonsBooking**-Plugin um zusätzliche Funktionen für Verwaltung, Kommunikation und Darstellung.

## Voraussetzungen

- WordPress
- Aktives Plugin **CommonsBooking**
- PHP 8.0+

## Funktionen

- Backend-Menü **„CB Additional Features“** mit Unterseiten:
  - **Feiertage**: Einpflegen und Entfernen von Feiertagen (Timeframes) für CommonsBooking.
  - **Blacklist**: Regeln zur Begrenzung von Buchungen pro Nutzer (Item, Standort, global) inkl. automatischer Ablehnung und Benachrichtigung.
  - **QR-Code**: QR-Code in Buchungsbestätigungs-E-Mails und Check-Seite zur Buchungsprüfung.
  - **Statistik**: Auswertungen und Export als Excel-Datei.
  - **Statistik**: Zusatz-Übersicht zu Registrierungen, Aktivitäten und Verifizierungen.
- **E-Mail Resender**: Admin-Seite zum erneuten Versenden von Buchungsbestätigungen.
- **Kurzcodes**
  - `[afcb_bookings]`: Aktuelle Buchungen des eingeloggten Nutzers.
  - `[afcb_qr_check]`: Check-Seite für QR-Code-Buchungsprüfung (wird bei Bedarf automatisch angelegt).
  - `[afcb_register]`: Registrierungsformular inkl. Adresse, Datenschutz/Nutzungsbedingungen und reCAPTCHA.
  - `[afcb_login]`: Login-Formular inkl. reCAPTCHA.
  - `[afcb_profile]`: Profil-Seite mit Pflichtfeldern, E-Mail- und Telefon-Verifikation.
  - `[afcb_forgot_password]`: Passwort-zurücksetzen Formular (E-Mail).
  - `[afcb_forgot_username]`: Benutzername per SMS zusenden.
- **Backend-Filter für Buchungen**: Filter nach Benutzername oder E‑Mail-Adresse in der Buchungsliste.
- **Buchungsverbünde**: Zusammengehörige Artikel wie Fahrrad und Anhänger können bei Standort- und Global-Limits als eine Buchung gezählt werden.
- **User Management**:
  - Eigene Registrierung/Login/Profilpflege.
  - Pflichtfelder + Status „vollständig authentifiziert“ (E-Mail + Telefon bestätigt).
  - OpenStreetMap (Nominatim) Adresssuche.
  - Soft-Duplikate (Name + Adresse) mit Admin-Prüfung.
  - Import aus Ultimate Member (Felder per Mapping).
  - Auto-Anlage der User-Management-Seiten beim Aktivieren des Plugins.
  - Überschreibt WordPress-Login/Register/Lost-Password URLs auf die eigenen Seiten.

## Installation

Das Repository enthält den vollständigen, gepinnten Runtime-Quellstand samt
Abhängigkeiten. Auf WordPress-Servern werden weder Composer noch Node.js
ausgeführt.

GitHub Actions builds and publishes the plugin package on release tags matching
`v*`. The package is written as `dist/cb-additional-features.zip` with
`cb-additional-features` as its ZIP root. The update metadata is served by
`https://updates.drutschmann.dev/?action=get_metadata&slug=cb-additional-features`.

Local checks are:

```bash
npm run audit:production
scripts/test.sh
scripts/package-release.sh dist
```

## Hinweise

- Einige Funktionen sind nur verfügbar, wenn **CommonsBooking** aktiv ist.
- Buchungsverbünde werden unter **CB Additional Features → Beschränkung → Buchungsverbünde** gepflegt:
  1. Einen verständlichen Namen vergeben, zum Beispiel „Fahrrad + Anhänger“.
  2. Mindestens zwei zusammengehörige Artikel auswählen.
  3. Einstellungen speichern.
- Ein Verbund greift nur für Buchungen derselben Person mit exakt gleichem Standort, Start und Ende. Einzelbuchungen sowie Buchungen an anderen Standorten oder in anderen Zeiträumen zählen weiterhin separat. Artikel-Limits, Verfügbarkeit und der Folgebuchungsabstand bleiben unverändert.
- Jeder Artikel kann nur einem Buchungsverbund angehören.
- Die QR-Code-Funktion kann in den Plugin-Einstellungen aktiviert/deaktiviert werden.
- Für den SMS-Versand den Filter `afcb_send_sms` integrieren (muss `true` zurückgeben, wenn die SMS erfolgreich gesendet wurde).
- User-Management-Einstellungen findest du im Backend-Menü **CB Additional Features → User Management**.
- Beim Löschen des Plugins werden die automatisch erzeugten Seiten entfernt, Benutzermetadaten bleiben erhalten (können manuell im Backend bereinigt werden).
- Seiten können im User-Management-Menü per Button erstellt oder mit dem passenden Shortcode befüllt werden.
- Standard-Login ist unter `/fallback-login` verfügbar.

## Staging-Sicherheit

Für Entwicklungs- und Staging-Instanzen werden die folgenden Konstanten in der
serverseitigen WordPress-Konfiguration gesetzt (nicht im Repository):

```php
define('WP_ENVIRONMENT_TYPE', 'staging');
define('LASTENRAD_SITE_KEY', 'wetterau'); // oder main
define('LASTENRAD_DEV_MAIL_SINK', 'dev-sink@example.org');
define('LASTENRAD_DEV_SMS_DELIVERY_ENABLED', false);
```

- Bei `local`, `development` und `staging` werden E-Mails an den Sink umgeleitet;
  `Cc` und `Bcc` werden entfernt.
- Ein fehlender oder ungültiger Sink blockiert den Versand vollständig.
- SMS- und Signal-Provideraufrufe werden standardmäßig vor dem HTTP-Request
  blockiert und ohne Nachrichtentext protokolliert.
- Eine bewusst konfigurierte Instanz darf echte SMS für alle AFCB-SMS-Abläufe
  versenden, wenn der serverseitige boolesche Schalter
  `LASTENRAD_DEV_SMS_DELIVERY_ENABLED` ausdrücklich `true` ist. Der Standard
  bleibt `false`; Signal bleibt in allen Nicht-Produktionsumgebungen gesperrt.
- In `production` bleiben Empfänger und Provider-Versand unverändert.

## Ultimate-Member-Migration

`UltimateMemberImporter::preview()` liefert denselben Änderungsplan wie der
Import, schreibt aber keine Daten. `UltimateMemberImporter::import()` übernimmt
Main- und Wetterau-Feldvarianten, Status und echte Einwilligungszeitpunkte.
Vorhandene AFCB-Werte haben Vorrang; sämtliche UM-Quellmetadaten bleiben
unverändert erhalten. Fehlende CommonsBooking-Kompatibilitätsfelder werden
ergänzt, bestehende Werte in `phone`, `address` und `terms_accepted` aber nicht
überschrieben. Auch der letzte Login wird standortspezifisch normalisiert.
Freigegebene UM-Konten werden mit einer AFCB-Provenienz grandfathered,
unbekannte Statuswerte als manuell zu prüfen markiert. Beide Methoden können
optional auf eine Liste von User-IDs eingeschränkt werden.

Die operative Migration wird ausschließlich über den abgesicherten WP-CLI-
Befehl `wp lastenrad users migrate-legacy --dry-run|--apply` gestartet. Im
WordPress-Backend existiert absichtlich kein Import-POST-Handler.

Ein migriertes, noch nicht bestätigtes Konto kann beim Login mit korrektem
Passwort eine neue Bestätigungs-E-Mail auslösen. Dabei wird kein Auth-Cookie
gesetzt; ein Cooldown verhindert wiederholten Versand. Eine bestätigte E-Mail
aktiviert nur Konten ohne zusätzliche manuelle Prüfmarkierung. Abgelehnte oder
weiterhin zu prüfende Konten erhalten keine Sitzung.

Der eigenständige Regressionstest benötigt keine Dev-Abhängigkeiten:

```bash
php tests/run.php
```
