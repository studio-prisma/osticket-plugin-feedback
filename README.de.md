# Feedback für osTicket

Eine Zufriedenheitsfrage beim Schließen eines Tickets. Der Kunde klickt einmal in der Mail, die Bewertung landet als interne Notiz am Ticket, und bei schlechter Bewertung liegt das Ticket sofort wieder beim Agenten, der zuletzt geantwortet hat.

**[English version →](README.md)**

Die Bewertungen bleiben in der Datenbank des Helpdesks. Kein Umfragedienst, kein Tracking-Pixel, kein Dritter.

---

## Was es tut

- **Beim Schließen eines Tickets**, auf das mindestens ein menschlicher Agent geantwortet hat, bekommt der Kunde eine Frage. Nie zweimal pro Ticket.
- **Zwei Wege zu fragen:** eine eigene Mail direkt nach dem Schließen (Standard) oder eine Zeile unter der Antwort, die das Ticket schließt.
- **Drei Skalen:** Daumen (Standard), Sterne 1–5, NPS 0–10. „Schlecht“ heißt Daumen runter, bis 2 Sterne, NPS bis 6; beide Schwellen sind einstellbar.
- **Bei schlechter Bewertung:** wieder öffnen und dem letzten antwortenden Agenten zuweisen (Standard). Die Zuweisungsmail von osTicket trägt Bewertung und Kommentar in `%{comments}`. Oder nur Notiz plus Mail an den Zuständigen, das Ticket bleibt zu.
- **Klickseite** ohne Login: bestätigt, bedankt sich, bietet einen freiwilligen Kommentar an. Sie zeigt die Ticketnummer und sonst nichts vom Ticket. Solange der Link gilt (Standard 14 Tage), lässt sich die Bewertung ändern; es zählt die letzte.
- **Auswertung** für Admins unter Anwendungen → Feedback: je Woche und Monat, Anteil gut und schlecht, Durchschnitt oder NPS, die letzten Kommentare.

## Wer nie gefragt wird

- Tickets ohne Antwort eines menschlichen Agenten
- Tickets, die ein KI-Agent schließt (Agenten mit dem Backend `ai-agent`, wie [AI Actions](https://github.com/studio-prisma/osticket-plugin-ai-actions) sie anlegt), auch wenn das im Cron passiert
- Unter-Tickets
- Abteilungen, Hilfethemen und Absender-Domains aus den Einstellungen; standardmäßig die eigenen Domains des Helpdesks
- Schließen mit einem Status wie „Dublette“ oder „Spam“ (Einstellung, nach Namen vorausgewählt)
- Tickets, zu denen schon gefragt wurde

## Voraussetzungen

- osTicket 1.18.x (getestet: 1.18.4), PHP ab 8.1 mit `mbstring`
- ein `SECRET_SALT` in `include/ost-config.php`, der nicht der Beispielwert ist (der Installer setzt einen). Ohne ihn fragt das Plugin niemanden und nimmt keinen Link an.
- der Cron von osTicket läuft (für Mails, die eine Anfrage nicht mehr geschafft hat, und für schlechte Bewertungen, die auf einen Kommentar warten)
- die Helpdesk-URL unter Admin → Einstellungen → System ist die öffentliche Adresse: Links werden daraus gebaut, nie aus der Anfrage

## Installation

Schritt für Schritt mit Prüfpunkten: **[docs/INSTALL.de.md](docs/INSTALL.de.md)**. Kurzfassung: nach `include/plugins/feedback/` entpacken, unter Admin → Verwalten → Plugins installieren, Ausnahmen prüfen, „Ask customers“ einschalten.

## Einstellungen

| Einstellung | Auswahl | Standard |
|---|---|---|
| Ask customers | an / aus | aus |
| When to ask | eigene Mail / Zeile unter der schließenden Antwort | eigene Mail |
| Scale | Daumen / Sterne / NPS | Daumen |
| On a bad rating | wieder öffnen + zuweisen / Notiz + Mail | wieder öffnen + zuweisen |
| Bad means | Sterne bis … / NPS bis … | 2 / 6 |
| Link valid for | Tage (1–90) | 14 |
| Comment field after the click | an / aus | an |
| Note at the ticket when asked | keine / kurz / mit der gesendeten Mail | kurz; ein gescheiterter Versand bekommt immer eine Notiz |
| Never ask for | Abteilungen, Hilfethemen, Status beim Schließen, Absender-Domains, eigene Domains | Abteilungen mit „Alert“/„Protokoll“ im Namen, Status mit „Duplicate“/„Spam“/„Hinfällig“ im Namen, eigene Domains |
| Texte | Betreff, Mailtext, Frage, Zeile, KI-Hinweis; leer = eingebauter Text | eingebaut, Deutsch (du) oder Englisch |
| Accent colour | `#rrggbb` | `#330066` |

Die Mailtexte verstehen osTicket-Variablen wie `%{ticket.number}` und `%{recipient.name.first}`.

## Wie es funktioniert

- **Schließen erkennen:** Das Plugin hört auf `model.updated` an Tickets und reagiert, wenn der Status von einem offenen in einen geschlossenen Zustand wechselt. Im Modus „Mail“ merkt es die Anfrage nur vor; die Mail geht am Ende der Anfrage raus, nach der Antwortmail von osTicket. Was eine abgebrochene Anfrage liegen lässt, schickt der Cron.
- **Modus „Zeile“** hängt sich ohne Core-Patch in `Ticket::postReply()`: osTicket legt die Antwort an, setzt dann den Status und baut danach die Mail aus demselben Antwort-Objekt. Dazwischen hängt das Plugin die Frage an den zwischengespeicherten Text der Antwort. Die Mail trägt die Zeile, der gespeicherte Verlauf nicht.
- **Links** tragen einen Zufallswert mit 128 Bit und einen HMAC-SHA256 (Schlüssel aus `SECRET_SALT` mit eigenem Subkey) über Ticket-ID, Ablauf und diesen Wert. Keine Ticket-ID in der URL.
- **Link-Scanner** wie Microsoft Safe Links öffnen jeden Link einer Mail. Ein Seitenaufruf speichert nie etwas: Die Seite schickt ein Formular per JavaScript ab, mit einem Button für Browser ohne JavaScript. Ein Scanner, der das Formular nicht abschickt, bewertet nichts.

## Bekannte Grenzen

- **Keine Emojis in Notizen.** osTicket dekodiert HTML-Entities vor dem Speichern, und die Datenbankverbindung ist 3-Byte-utf8, ein 👍 würde verschwinden. In Notizen steht „Daumen hoch“; Sterne (★) gehen. Emojis in Kommentaren fehlen in der Notiz, bleiben aber in der Tabelle des Plugins und auf der Auswertung.
- **Spitze Klammern in Kommentaren** erscheinen in der Notiz als ‹ ›, weil der Sanitizer von osTicket alles entfernt, was wie ein Tag aussieht.
- **Modus „Zeile“** braucht eine Antwort, die den Status auf geschlossen setzt. Schließen ohne Antwort fragt in diesem Modus niemanden, und eine Antwort mit „Antworten an: niemand“ trägt keine Zeile und zählt nicht als gefragt.
- **Safe Links mit JavaScript:** Ein Scanner, der das JavaScript der Seite in einer Sandbox ausführt, würde die Bewertung abschicken. Bisher nicht beobachtet; im echten Microsoft-365-Tenant zu prüfen.
- **Häkchen-Standardwerte bei einer neuen Instanz:** osTicket 1.18 speichert sie beim ersten Speichern nicht. Nach dem Anlegen die Instanz noch einmal öffnen und die Häkchen prüfen (siehe INSTALL, Schritt 2).
- **Notiz „mit der Mail“** legt die Bewertungslinks des Kunden ans Ticket. Jeder Agent, der das Ticket öffnen darf, kann ihnen folgen; das ist der Preis dafür, genau zu sehen, was rausging.
- **Nur eine Frage,** keine Erinnerungen, kein Export, keine Bewertung einzelner Agenten. Das ist bewusst außerhalb des Umfangs.
- **Wochenzusammenfassung von AI Actions:** Das Plugin beantwortet das Signal `feedback.digest` mit einer Zeile. AI Actions schickt es noch nicht.

## Datenschutz

Gespeichert wird je gefragtem Ticket: Ticket-ID, Bewertung, Kommentar, Zeit, ein mit Schlüssel gebildeter Hash der IP-Adresse (Rate-Limit), nie die IP selbst. Nach 24 Monaten werden die Zeilen gelöscht. In die Datenschutzinfo gehört ein Satz, zum Beispiel:

> Nach Abschluss eines Tickets fragen wir dich gegebenenfalls einmal, wie zufrieden du warst. Bewertung und freiwilliger Kommentar werden zusammen mit dem Ticket in unserem Helpdesk bis zu 24 Monate gespeichert und nur genutzt, um unseren Support zu verbessern.

## Entwicklung

```bash
./dev/up.sh                       # osTicket 1.18.4, MariaDB, Mailpit auf 127.0.0.1:8095 / :8031
php tests/unit.php                # Unit-Tests ohne osTicket
(cd tests/e2e && npm ci && npx playwright test)
./tools/build-zip.sh              # dist/feedback-<version>.zip
```

Details: [CONTRIBUTING.md](CONTRIBUTING.md), [dev/README.md](dev/README.md). Sicherheitsmeldungen: [SECURITY.md](SECURITY.md).

## Lizenz

GPL-2.0-or-later wie osTicket, dessen Klassen das Plugin erweitert. Siehe [LICENSE](LICENSE).
