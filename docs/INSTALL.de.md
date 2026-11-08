# Installation: Feedback für osTicket

Die Reihenfolge ist bindend. Jeder Schritt endet mit einer Prüfung; scheitert sie, dort aufhören.

**[English version →](INSTALL.md)**

---

## 0. Vorab

Du brauchst:

- osTicket 1.18.x mit Zugang zum Admin-Bereich
- Shell- oder SFTP-Zugang zu `include/plugins/`
- ein Testticket mit einer Kundenadresse, die **nicht** zu den eigenen Domains des Helpdesks gehört (z. B. eine private Adresse). Eigene Domains werden standardmäßig nie gefragt; ein Test mit der eigenen Firmenadresse bleibt deshalb still.

Drei Dinge prüfen, bevor du weitermachst:

1. **`SECRET_SALT`**: In `include/ost-config.php` steht eine lange Zufallszeichenkette, nicht `%CONFIG-SIRI`. Ohne sie fragt das Plugin niemanden (die Auswertung zeigt dann eine rote Meldung).
2. **Cron**: Admin → Einstellungen → System, oder der Cronjob für `api/cron.php` läuft mindestens alle 5 Minuten. Ohne Cron bleiben abgebrochene Mails liegen, und eine schlechte Bewertung ohne Kommentar öffnet das Ticket nie.
3. **Helpdesk-URL**: Admin → Einstellungen → System → „Helpdesk URL“ ist die öffentliche https-Adresse. Die Links in den Mails werden daraus gebaut.

**Kill-Switch.** Macht das Plugin Ärger, entweder

- Admin → Verwalten → Plugins → Feedback → Instanz → „Ask customers“ aus → Speichern (keine neuen Fragen, bestehende Links funktionieren weiter), oder
- Haken der Instanz entfernen → Speichern (Fragen und Klickseite sofort weg), oder
- den Ordner `include/plugins/feedback/` löschen.

Bewertungen, Notizen und Zuweisungen, die schon passiert sind, bleiben normale osTicket-Daten.

Abbruch, wenn: `SECRET_SALT` fehlt oder der Beispielwert ist, oder kein Cron läuft.

---

## 1. Dateien

1. Das Paket `feedback-<version>.zip` nach `include/plugins/` entpacken. Danach gibt es `include/plugins/feedback/plugin.php`.
2. Rechte wie bei den anderen Plugins: lesbar für den Webserver, nicht schreibbar.

Prüfung: `include/plugins/feedback/` enthält `plugin.php`, `config.php`, `class.FeedbackPlugin.php`, `LICENSE` und `src/` mit 12 Dateien.

---

## 2. Plugin installieren, noch nicht einschalten

1. Admin → Verwalten → Plugins → „Neues Plugin hinzufügen“ → Feedback → Installieren.
2. Status auf „Aktiv“, Speichern.
3. Reiter „Instanzen“ → „Neue Instanz hinzufügen“, Name z. B. `Feedback`, Haken bei „Aktiv“.
4. In der Instanz die Einstellungen durchgehen. **„Ask customers“ bleibt aus.**
   - „Never ask for → Departments“: Abteilungen für Alerts, Monitoring, Protokolle. Vorausgewählt sind Abteilungen mit „Alert“, „Protokoll“ oder „Monitoring“ im Namen.
   - „Closing statuses“: Schließen mit einem dieser Status fragt nie, z. B. „Dublette“ oder „Spam“. Vorausgewählt sind geschlossene Status mit „Duplicate“, „Spam“, „Rejected“ oder „Hinfällig“ im Namen.
   - „Help topics“ und „Sender domains“ nach Bedarf, z. B. Domains von Partnern, die nicht gefragt werden sollen.
   - „Note at the ticket when asked“: kurz (Standard), mit der gesendeten Mail oder keine. Mit der Mail sieht jeder Agent, der das Ticket öffnet, die Bewertungslinks des Kunden.
   - Texte leer lassen für den eingebauten Text (Du-Form). „Language of the built-in texts“ auf „Deutsch (du)“, wenn das Helpdesk nicht auf Deutsch steht.
5. Speichern.
6. **Instanz noch einmal öffnen und die Häkchen prüfen.** osTicket 1.18 speichert beim Anlegen einer Instanz die Standardwerte von Häkchen nicht: Nach dem ersten Speichern können „Comment field after the click“, „Own domains“ und „Footer with AI notice“ aus sein, obwohl sie standardmäßig an sind. Gewünschte Häkchen wieder setzen, Speichern, erneut öffnen und prüfen, dass sie bleiben.

Prüfung: Agentenpanel → Anwendungen → „Feedback“ öffnet die Auswertung mit „Status: aus“ und ohne rote Meldung.

Abbruch, wenn: rote Meldung zu `SECRET_SALT` (zurück zu Schritt 0).

---

## 3. Zuweisungsmail vorbereiten (nur für „wieder öffnen + zuweisen“)

Bei schlechter Bewertung schickt osTicket seine eigene Zuweisungsmail an den Agenten. Bewertung und Kommentar stehen darin nur, wenn die Vorlage `%{comments}` enthält.

1. Admin → E-Mails → Vorlagen → das Set der Abteilungen → „Ticket zugewiesen“ (assigned.alert): enthält `%{comments}`. Die Vorlage von osTicket hat es; eigene Sets prüfen.
2. Admin → Einstellungen → Benachrichtigungen → „Zuweisungsbenachrichtigung“ ist an, Empfänger „Zugewiesener Agent“.

Prüfung: beides gesetzt. Ohne Schritt 2 wird das Ticket trotzdem geöffnet und zugewiesen, es kommt nur keine Mail.

---

## 4. Einschalten und testen

1. „Ask customers“ an, Speichern.
2. Testticket mit der Testadresse aus Schritt 0 anlegen, als Agent darauf antworten, Ticket schließen.
3. In der Testadresse liegt eine Mail „Kurze Frage zu deinem Ticket [Nummer]“ mit 👍/👎.
4. Am Ticket: interne Notiz „Zufriedenheitsfrage“ mit Empfänger und Gültigkeit. Steht dort „konnte nicht gesendet werden“, ist der Mailversand von osTicket gescheitert (Systemprotokoll; bei OAuth das Token des ausgehenden Kontos prüfen).
5. 👍 klicken: Seite „Danke!“, am Ticket die interne Notiz „Kundenbewertung · Bewertung: Daumen hoch (zufrieden)“. Das Ticket bleibt zu.
6. Zweites Testticket, gleicher Ablauf, 👎 klicken, Kommentar schreiben, „Kommentar senden“: Das Ticket ist offen, dem Agenten zugewiesen, der geantwortet hat, und er hat eine Zuweisungsmail mit Bewertung und Kommentar.

Abbruch, wenn: bei Schritt 3 keine Mail kommt. Dann zuerst prüfen: Steht am Ticket eine Notiz „Zufriedenheitsfrage“ (siehe Schritt 4)? Ist die Kundenadresse eine eigene Domain? Hat ein Agent geantwortet (keine KI)? Ist die Abteilung oder der Status beim Schließen ausgenommen? Steht im Systemprotokoll ein Eintrag „Feedback plugin“?

---

## 5. Security-Gate

| Fall | Vorgehen | Erwartet |
|---|---|---|
| Gefälschter Link | im Link aus der Mail ein Zeichen nach dem Punkt ändern, öffnen | „Link nicht gültig“, am Ticket ändert sich nichts |
| Seitenaufruf ohne Klick | Link mit `curl` abrufen (wie ein Scanner) | Seite mit Button, Bewertung bleibt leer |
| Fremdes Ticket | Link von Ticket A öffnen | ändert nur Ticket A |
| Abgelaufen | nach Ablauf der Gültigkeit öffnen | „Link nicht gültig“ |
| Kommentar mit HTML | `<script>alert(1)</script>` als Kommentar | Notiz zeigt ‹script›…‹/script› als Text, nichts wird ausgeführt |
| Viele Aufrufe | über 60 Aufrufe in 10 Minuten von einer IP | „Zu viele Anfragen“ (HTTP 429) |
| Zweites Schließen | Ticket wieder öffnen, erneut schließen | keine zweite Frage |

---

## 6. Datenschutzinfo

In die Datenschutzhinweise für Kunden gehört ein Satz zur Bewertung, zum Beispiel:

> Nach Abschluss eines Tickets fragen wir dich gegebenenfalls einmal, wie zufrieden du warst. Bewertung und freiwilliger Kommentar werden zusammen mit dem Ticket in unserem Helpdesk bis zu 24 Monate gespeichert und nur genutzt, um unseren Support zu verbessern.

Gespeichert werden Ticket-ID, Bewertung, Kommentar, Zeit und ein Hash der IP-Adresse (für das Rate-Limit), nicht die IP selbst. Der Cron löscht Zeilen nach 24 Monaten.

---

## 7. Update und Entfernen

- **Update:** neuen Ordner über den alten entpacken. Die Tabellen bleiben, Einstellungen auch. Neue Einstellungen gelten mit ihrem eingebauten Standard, bis die Instanz einmal gespeichert ist: Instanz öffnen, neue Felder prüfen, Speichern.
- **Entfernen:** Instanz löschen, Plugin deinstallieren, Ordner löschen. Die Tabellen `<prefix>feedback_request` und `<prefix>feedback_hit` bleiben in der Datenbank, bis du sie selbst löschst (`DROP TABLE`). Die Notizen an den Tickets bleiben.
