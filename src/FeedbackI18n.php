<?php

declare(strict_types=1);

/**
 * Built-in texts for the customer side (mail, line, page) and for notes and
 * the agent mail. German uses the informal "du". The mail texts can be
 * replaced in the settings; empty settings fall back to these.
 */
final class FeedbackI18n
{
    /** "de" or "en": the setting, else the help desk's primary language. */
    public static function language(string $setting, string $primary): string
    {
        if ($setting === 'de' || $setting === 'en') {
            return $setting;
        }
        return stripos($primary, 'de') === 0 ? 'de' : 'en';
    }

    /** @return array<string,string> */
    public static function strings(string $lang): array
    {
        return $lang === 'de' ? self::DE : self::EN;
    }

    private const DE = array(
        'subject'        => 'Kurze Frage zu deinem Ticket [%{ticket.number}]',
        'text'           => "Hallo %{recipient.name.first},\n\nwir haben dein Ticket %{ticket.number} „%{ticket.subject}“ abgeschlossen. Uns interessiert, wie die Bearbeitung bei dir angekommen ist.",
        'question'       => 'Wie zufrieden bist du mit der Lösung?',
        'question_nps'   => 'Wie wahrscheinlich ist es, dass du uns weiterempfiehlst?',
        'line'           => 'Ticket erledigt. Wie zufrieden bist du mit der Lösung?',
        'foot'           => 'Bei der Bearbeitung unterstützt uns ein KI-Assistent. Antworten, die er selbst schreibt, sind als solche gekennzeichnet.',
        'foot_lead'      => 'Ticket %{ticket.number} · Ein Klick genügt, deine Bewertung kannst du innerhalb von {days} Tagen ändern.',
        'thumb_up'       => 'Gut',
        'thumb_down'     => 'Nicht gut',
        'thumb_up_note'  => 'Daumen hoch',
        'thumb_down_note'=> 'Daumen runter',
        'nps_low'        => '0 = unwahrscheinlich',
        'nps_high'       => '10 = sehr wahrscheinlich',
        'page_title'     => 'Deine Bewertung',
        'confirm_lead'   => 'Deine Bewertung für Ticket %s:',
        'confirm_wait'   => 'Einen Moment, die Bewertung wird gespeichert …',
        'confirm_button' => 'Bewertung abschicken',
        'choose_lead'    => 'Wie zufrieden bist du mit der Lösung von Ticket %s?',
        'thanks'         => 'Danke!',
        'thanks_lead'    => 'Deine Bewertung für Ticket %s ist gespeichert.',
        'change_hint'    => 'Du kannst sie bis zum %s über den Link in der Mail ändern.',
        'comment_label'  => 'Möchtest du uns noch etwas mitgeben? (freiwillig)',
        'comment_bad'    => 'Das tut uns leid. Was ist schiefgelaufen? (freiwillig)',
        'comment_send'   => 'Kommentar senden',
        'comment_skip'   => 'Ohne Kommentar fertig',
        'comment_done'   => 'Danke, dein Kommentar ist angekommen.',
        'error_title'    => 'Link nicht gültig',
        'error_lead'     => 'Dieser Link ist abgelaufen oder ungültig. Bei Fragen antworte einfach auf die Mail zu deinem Ticket.',
        'limit_title'    => 'Zu viele Anfragen',
        'limit_lead'     => 'Bitte versuch es in ein paar Minuten noch einmal.',
        'note_title'     => 'Kundenbewertung',
        'note_rated'     => 'Bewertung: %s',
        'note_changed'   => 'Bewertung geändert: %s → %s',
        'note_bad'       => 'nicht zufrieden',
        'note_good'      => 'zufrieden',
        'note_comment'   => 'Kommentar: %s',
        'note_comment_title' => 'Kommentar zur Bewertung',
        'note_no_agent'  => 'Der letzte Bearbeiter ist nicht verfügbar, das Ticket ist offen und nicht zugewiesen.',
        'note_sent_title'=> 'Zufriedenheitsfrage',
        'note_sent'      => 'Zufriedenheitsfrage per Mail gesendet an %1$s (Skala: %2$s, Link gültig bis %3$s).',
        'note_mail'      => 'Gesendete Mail „%s“:',
        'note_failed'    => 'Die Zufriedenheitsfrage an %s konnte nicht gesendet werden und wird nicht wiederholt. Den Grund nennt das Systemprotokoll.',
        'scale_thumbs'   => 'Daumen',
        'scale_stars'    => 'Sterne',
        'scale_nps'      => 'NPS',
        'date_format'    => 'd.m.Y',
        'note_source'    => 'Ausgelöst durch: %s.',
        'source_web'     => 'Schließen im Agentenbereich',
        'source_mass'    => 'Statuswechsel in der Ticketliste',
        'source_api'     => 'API oder Cron',
        'note_declined_close' => 'Zufriedenheitsfrage beim Schließen abgewählt (%s). Zu diesem Ticket wird nicht mehr gefragt.',
        'note_declined_menu'  => 'Zufriedenheitsfrage für dieses Ticket abgeschaltet (%s).',
        'note_allowed'   => 'Zufriedenheitsfrage für dieses Ticket wieder erlaubt (%s).',
        'by_system'      => 'API oder Cron',
        'ui_ask'         => 'Zufriedenheitsfrage senden',
        'ui_menu_off'    => 'Keine Zufriedenheitsfrage',
        'ui_menu_on'     => 'Zufriedenheitsfrage wieder erlauben',
        'ui_off_hint'    => 'Für dieses Ticket ist die Zufriedenheitsfrage abgeschaltet.',
        'agent_subject'  => 'Schlechte Bewertung: %{ticket.subject} [%{ticket.number}]',
        'agent_lead'     => 'Der Kunde hat das abgeschlossene Ticket schlecht bewertet. Das Ticket bleibt geschlossen.',
        'agent_open'     => 'Ticket öffnen',
    );

    private const EN = array(
        'subject'        => 'A quick question about your ticket [%{ticket.number}]',
        'text'           => "Hello %{recipient.name.first},\n\nwe have closed your ticket %{ticket.number} \"%{ticket.subject}\". We'd like to know how it went for you.",
        'question'       => 'How satisfied are you with the solution?',
        'question_nps'   => 'How likely are you to recommend us?',
        'line'           => 'Ticket resolved. How satisfied are you with the solution?',
        'foot'           => 'An AI assistant helps us handle tickets. Replies it writes on its own are marked as such.',
        'foot_lead'      => 'Ticket %{ticket.number} · One click is enough, you can change your rating within {days} days.',
        'thumb_up'       => 'Good',
        'thumb_down'     => 'Not good',
        'thumb_up_note'  => 'thumbs up',
        'thumb_down_note'=> 'thumbs down',
        'nps_low'        => '0 = not likely',
        'nps_high'       => '10 = very likely',
        'page_title'     => 'Your rating',
        'confirm_lead'   => 'Your rating for ticket %s:',
        'confirm_wait'   => 'One moment, saving your rating …',
        'confirm_button' => 'Send rating',
        'choose_lead'    => 'How satisfied are you with the solution of ticket %s?',
        'thanks'         => 'Thank you!',
        'thanks_lead'    => 'Your rating for ticket %s is saved.',
        'change_hint'    => 'You can change it until %s through the link in the mail.',
        'comment_label'  => 'Anything else you would like to tell us? (optional)',
        'comment_bad'    => "We're sorry. What went wrong? (optional)",
        'comment_send'   => 'Send comment',
        'comment_skip'   => 'Done without a comment',
        'comment_done'   => 'Thank you, your comment has arrived.',
        'error_title'    => 'Link not valid',
        'error_lead'     => 'This link has expired or is not valid. If you have questions, just reply to the mail about your ticket.',
        'limit_title'    => 'Too many requests',
        'limit_lead'     => 'Please try again in a few minutes.',
        'note_title'     => 'Customer rating',
        'note_rated'     => 'Rating: %s',
        'note_changed'   => 'Rating changed: %s → %s',
        'note_bad'       => 'not satisfied',
        'note_good'      => 'satisfied',
        'note_comment'   => 'Comment: %s',
        'note_comment_title' => 'Comment on the rating',
        'note_no_agent'  => 'The last agent who replied is not available; the ticket is open and unassigned.',
        'note_sent_title'=> 'Satisfaction question',
        'note_sent'      => 'Satisfaction question sent by mail to %1$s (scale: %2$s, link valid until %3$s).',
        'note_mail'      => 'Mail as sent, "%s":',
        'note_failed'    => 'The satisfaction question to %s could not be sent and is not retried. The system log names the reason.',
        'scale_thumbs'   => 'Thumbs',
        'scale_stars'    => 'Stars',
        'scale_nps'      => 'NPS',
        'date_format'    => 'Y-m-d',
        'note_source'    => 'Triggered by: %s.',
        'source_web'     => 'closing in the agent panel',
        'source_mass'    => 'status change in the ticket list',
        'source_api'     => 'API or cron',
        'note_declined_close' => 'Satisfaction question switched off when closing (%s). This ticket won\'t be asked.',
        'note_declined_menu'  => 'Satisfaction question switched off for this ticket (%s).',
        'note_allowed'   => 'Satisfaction question allowed again for this ticket (%s).',
        'by_system'      => 'API or cron',
        'ui_ask'         => 'Send satisfaction question',
        'ui_menu_off'    => 'No satisfaction question',
        'ui_menu_on'     => 'Allow satisfaction question again',
        'ui_off_hint'    => 'The satisfaction question is switched off for this ticket.',
        'agent_subject'  => 'Bad rating: %{ticket.subject} [%{ticket.number}]',
        'agent_lead'     => 'The customer gave the closed ticket a bad rating. The ticket stays closed.',
        'agent_open'     => 'Open ticket',
    );
}
