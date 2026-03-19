# Dienstplaner

Webanwendung zur Dienstplanung für Versammlungen. Verwaltet Personen, Aufgaben, Abteilungen und Planungstage. Ersetzt eine Excel-Lösung.

**Stack:** Symfony 7.4 · PHP 8.3 · MariaDB 10.11 · Bootstrap 5 · DDEV

---

## Setup

```bash
ddev start
ddev composer install
ddev exec php bin/console doctrine:database:create
ddev exec php bin/console doctrine:migrations:migrate
ddev exec php bin/console app:user:create-admin
```

---

## Konfiguration

Kopiere `.env` nach `.env.local` und passe die Werte an:

```env
DATABASE_URL="mysql://user:pass@127.0.0.1:3306/dienstplaner"

MAILER_DSN=smtp://user:password@smtp.example.com:587?encryption=tls
MAILER_SENDER_EMAIL=noreply@example.com
MAILER_SENDER_NAME=Dienstplaner
```

Auf der DevBox (ddev) sendet Mailpit alle Mails ab — kein echter Versand.
Mailpit-Oberfläche: `https://dienstplaner.ddev.site:8026`

---

## Rollen

| Rolle               | Zugriff                                                              |
|---------------------|----------------------------------------------------------------------|
| `ROLE_ADMIN`        | Alle Versammlungen, Benutzerverwaltung, Versammlungswechsel          |
| `ROLE_ASSEMBLY_ADMIN` | Vollzugriff auf eine Versammlung (Personen, Export, Planung usw.) |
| `ROLE_PLANER`       | Planung und Stammdaten der eigenen Abteilungen                       |
| `ROLE_USER`         | Dashboard, Kalender, Profil (Lesezugriff)                            |

Jeder Benutzer hat genau eine Rolle (`role VARCHAR`). `getRoles()` gibt `[$role, 'ROLE_USER']` zurück.

`ROLE_PLANER` kann einer oder mehreren Abteilungen zugeordnet werden und sieht dann nur Daten dieser Abteilungen (`PlanerScope`-Service).

`ROLE_ADMIN` kann über die Statusleiste zwischen Versammlungen wechseln (Session-basiert).

---

## Datenbankmodell

Alle Tabellen werden über Doctrine ORM verwaltet. Migrationen liegen in `migrations/`.

| Entität       | Tabelle        | Beschreibung                                      |
|---------------|----------------|---------------------------------------------------|
| `Assembly`    | `assembly`     | Versammlung – oberste Organisationseinheit        |
| `Department`  | `department`   | Abteilung innerhalb einer Versammlung             |
| `Person`      | `person`       | Person, die Aufgaben übernimmt                    |
| `Task`        | `task`         | Aufgabe innerhalb einer Abteilung                 |
| `Day`         | `day`          | Planungstag einer Versammlung                     |
| `Assignment`  | `assignment`   | Zuteilung: Person + Task + Day                    |
| `Absence`     | `absence`      | Abwesenheit einer Person (Datumsbereich)          |
| `ExternalTask`| `external_task`| Externe Aufgabe – blockiert interne Zuteilung     |
| `SpecialDate` | `special_date` | Besonderer Termin (Kongress, Gedächtnisfeier usw.)|
| `User`        | `user`         | Systembenutzer mit Rolle und Versammlungszuordnung|
| `PlanningLock`| `planning_lock`| Bearbeitungssperre für Planungsabteilungen        |

Unique Constraints:
- `assignment`: eine Aufgabe pro Tag nur einmal (`task_id + day_id`)
- `assignment`: eine Person pro Tag nur eine Aufgabe (`person_id + day_id`)
- `external_task`: eine externe Aufgabe pro Person pro Tag (`person_id + day_id`)
- `day`: ein Planungstag pro Versammlung und Datum (`assembly_id + date`)

---

## Funktionen

### Stammdaten

Verwaltung unter `/assemblies`, `/departments`, `/persons`, `/tasks`.

Personen werden Aufgaben zugeordnet (ManyToMany, Tabelle `person_task`). Das Planungsraster zeigt pro Aufgabe nur Personen, die ihr zugeordnet sind.

Jede Versammlung hat konfigurierbare Planungswochentage (genau ein Wochentag Mo–Fr und ein Wochenendtag Sa/So). Diese steuern die automatische Tagesgenerierung und das Datumsmapping beim Import.

### Benutzerverwaltung

CRUD unter `/admin/users` (ab `ROLE_ASSEMBLY_ADMIN`).

Beim Anlegen eines Benutzers wird automatisch:
- ein 16-stelliges Einmal-Passwort generiert
- eine Einladungsmail mit Login-URL versandt
- ein Personenprofil erstellt und verknüpft (wenn Versammlung gesetzt)
- `forcePasswordChange = true` gesetzt → Weiterleitung bei erstem Login

Passwörter werden über `symfony/password-hasher` gehasht. Zurücksetzen über die Bearbeitungsansicht des Benutzers.

### Planung

Aufruf unter `/planning` (ab `ROLE_USER`).

Das Planungsraster zeigt alle Aufgaben als Spalten und die Planungstage als Zeilen, gruppiert nach Abteilungen. Planer weisen Personen per Dropdown zu — AJAX-Speicherung ohne Seitenneuladen.

Gleichzeitiges Bearbeiten wird über eine Datenbanksperre (`planning_lock`) verhindert. Wer zuerst eine Abteilung bearbeitet, hält die Sperre für 10 Minuten. Andere sehen ein Banner und deaktivierte Dropdowns. Heartbeat alle 60 Sekunden, Polling alle 30 Sekunden.

#### Planungsregeln

Der `PlanningRuleService` wendet Regeln auf den Tagesplan an:

- **Gedächtnismahl** (`memorial`): Der Tag wird gesperrt — keine Zuteilungen möglich
- **Kongress** (`congress`): Die Vorwoche wird aus dem Plan entfernt; Kongresstage blockiert
- **Dienstwoche** (`service_week`): Der reguläre Wochentagstermin wird auf Dienstag der Woche verschoben

#### Automatischer Planungsvorschlag

„Vorschlag generieren" füllt alle leeren Slots eines Monats automatisch. Algorithmus (`PlanningProposalService`):
- Abwesenheiten, externe Aufgaben und Tagessperren werden berücksichtigt
- Unter den verfügbaren Personen wird die mit dem niedrigsten Jahres-Fairness-Score gewählt
- Der Planer kann jeden Vorschlag einzeln annehmen oder überspringen

#### Planungssperre

Pro Zuteilung: `AssignmentService` prüft vor dem Speichern alle Konflikte serverseitig und wirft `DomainException` bei Verletzungen.

### Abwesenheiten

Verwaltung unter `/absences` (ab `ROLE_PLANER`).

Abwesenheiten blockieren die Zuweisung einer Person an betroffenen Tagen.

### Andere Aufgaben (Externe Aufgaben)

Verwaltung unter `/external-tasks` (ab `ROLE_PLANER`).

Blockieren eine Person an einem Planungstag für interne Zuteilungen.

**PDF-Import:** Dateien werden über `smalot/pdfparser` eingelesen. Datumsangaben (`dd.mm.yyyy`, `yyyy-mm-dd`, `dd.mm.yy`) werden erkannt und Personennamen per Fuzzy-Match zugeordnet. Das Datum wird auf den konfigurierten Versammlungstag der Woche gemappt.

### Kalender

Aufruf unter `/calendar` (ab `ROLE_USER`). Basiert auf FullCalendar v6.

Zeigt pro Tag:
- Eigene Zuteilungen (weiß mit Abteilungs-Border, fett, oben)
- Abwesenheiten (blau: eigene, grau: andere)
- Fremde Zuteilungen (Abteilungsfarbe)
- Besondere Daten (lila)
- Eigene externe Aufgaben (orange)

Eigene Abwesenheiten können per Klick bearbeitet werden.

Rollenbasierte Sichtbarkeit:
- `ROLE_PLANER`: Abwesenheiten der zugeordneten Abteilungen
- `ROLE_ASSEMBLY_ADMIN` / `ROLE_ADMIN`: alle Daten der Versammlung

### Persönlicher Kalender (Token-Zugang)

Jeder Benutzer kann unter `/profile/2fa` einen persönlichen Kalender-Link generieren. Die Seite ist ohne Login erreichbar (`/kalender/{token}`).

Zeigt: eigene Abwesenheiten, eigene externe Aufgaben, geplante Einsätze (readonly), besondere Daten. Navigation monatsweise. Abwesenheiten und externe Aufgaben können direkt eingetragen und gelöscht werden.

Der Token (`calendar_token VARCHAR(64) UNIQUE`) wird per `bin2hex(random_bytes(32))` generiert.

### ICS-Import (Abwesenheiten)

Die ICS-URL eines externen Kalenders (z. B. TeamUp) wird pro Versammlung gespeichert (`teamup_calendar_url`). Import über `/assemblies/{id}/teamup-import`.

Eventtitel werden mit Personennamen der Versammlung abgeglichen. Bereits vorhandene Abwesenheiten werden nicht doppelt angelegt.

### Export

Aufruf unter `/export` (ab `ROLE_PLANER`).

| Format | Beschreibung |
|--------|-------------|
| PDF    | Monatsdienstplan, DIN A4, Tagesblöcke mit Abteilungstabellen, dompdf |
| Excel  | Aufgaben nach Abteilung, PhpSpreadsheet |
| Word   | Tagesabschnitte mit Aufgabentabellen, PhpWord |

### Monatsbenachrichtigung

Über „Benachrichtigungen versenden" in der Planungsansicht (ab `ROLE_ASSEMBLY_ADMIN`) wird an jede Person mit Zuteilungen im Monat eine Mail mit ICS-Anhang versandt.

Die ICS-Datei wird ohne externe Bibliothek generiert (RFC 5545), kompatibel mit Google Calendar, Outlook und Apple Calendar.

### Suche & Übersichten

Unter `/search` (ab `ROLE_PLANER`):

- **Personen** – Suche, Detailseite mit Planungshistorie und Abwesenheiten
- **Aufgaben** – Filter nach Name/Abteilung, Zuweisungshäufigkeit pro Person
- **Verfügbarkeit** – wer ist an einem Datum verfügbar (optional mit Aufgabenfilter)
- **Fairness/Rotation** – Aufgabenverteilung über alle Personen mit Farbcodierung (±20 % Abweichung)
- **Übersichten** – Aufgabe-Personen, Personen-Aufgaben, Planungshistorie, Abwesenheiten; alle als CSV exportierbar

### Besondere Daten

CRUD unter `/special-dates` (ab `ROLE_PLANER`).

Typen: `memorial`, `congress`, `service_week`, `misc`. Beeinflussen die Planungslogik und werden in Kalender, Dashboard und PDF-Export angezeigt.

### Dashboard

Zeigt den Monatsplan der aktiven Versammlung als Aushang (read-only), gruppiert nach Abteilungen. Monatswechsel per Query-Parameter.

### Druckansicht

`@media print` blendet Sidebar, Topbar und Aktionsbuttons aus. Klasse `.no-print` / `.print-only` steuerbar.

### 2FA

Paket `scheb/2fa-bundle` v7 mit TOTP und E-Mail-Fallback.

Aktivierung unter `/profile/2fa` via QR-Code. 10 Backup-Codes werden bei Aktivierung generiert. Pro Versammlung konfigurierbar (`user_choice`, `disabled`, `totp`, `email`).

---

## Logging

Monolog schreibt in `var/log/prod-DATUM.log` (rotating, 30 Tage).

- `warning` und höher → alle Kanäle
- `info` → nur Kanal `app`

Admin-Logansicht unter `/admin/logs` (nur `ROLE_ADMIN`): letzte 500 Zeilen, neueste zuerst, mit Suchfeld.

---

## Tests

```bash
php bin/phpunit
```

49 Unit-Tests ohne Datenbankverbindung:

| Datei | Inhalt |
|-------|--------|
| `AssignmentServiceTest` | Zuteilungslogik, Konflikterkennung |
| `CalendarServiceTest` | Abwesenheitsbesitz, Eventaufbau |
| `PlanningRuleServiceTest` | Kongress-, Gedächtnisfeier- und Dienstwochen-Regeln |
| `PdfImportServiceTest` | Datumsextraktion, Personenmatching |
| `AssemblyServiceTest` | Wochentagsvalidierung, Datumsmapping |
| `PlanerScopeTest` | Rollen- und Abteilungsscope |

---

## Deployment

Siehe `DEPLOYMENT.md`.

---

## Mehrsprachigkeit

Deutsch (Standard), Englisch, Französisch. Sprachdateien unter `translations/`.

Umschaltung per Sprachschalter in der Topbar. Aktive Sprache wird in der Session gespeichert.
