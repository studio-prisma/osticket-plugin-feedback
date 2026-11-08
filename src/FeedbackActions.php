<?php

declare(strict_types=1);

/**
 * What a rating does at the ticket: an internal note, and for a bad rating
 * either reopen + assign to the last agent who replied (the core's assignment
 * alert carries rating and comment in %{comments}) or a note plus a mail to
 * the responsible agent.
 *
 * Notes never contain a 4-byte character: osTicket decodes entities before it
 * saves, and the 3-byte utf8 connection would drop the character. Labels come
 * from FeedbackScale::noteLabel() ("Daumen hoch"), emojis in comments are
 * removed from the note (the full comment stays in the plugin's table).
 */
final class FeedbackActions
{
    /** How long a bad rating waits for its comment before the cron acts. */
    public const COMMENT_WAIT = 600;

    /**
     * Stores a rating and runs what it triggers.
     *
     * @param array<string,mixed> $row FeedbackStore row
     * @return array{bad:bool, waiting:bool}
     */
    public static function rate(array $row, int $value, string $ipHash): array
    {
        $s = FeedbackSettings::get();
        $id = (int) $row['id'];
        $scale = (string) $row['scale'];
        $bad = FeedbackScale::isBad($scale, $value, $s['bad_stars'], $s['bad_nps']);
        $d = FeedbackRules::onRating($s, $row, $value);
        $due = $d['act'] === 'later' ? time() + self::COMMENT_WAIT : ($d['act'] === 'cancel' ? -1 : 0);
        FeedbackStore::rate($id, $value, $bad, $ipHash, $d['note'] === 'changed', $due);

        $ticket = Ticket::lookup((int) $row['ticket_id']);
        if (!$ticket) {
            return array('bad' => $bad, 'waiting' => false);
        }
        $t = self::strings();
        if ($d['act'] === 'now') {
            self::bad(FeedbackStore::find($id) ?: $row, $ticket, '');
        } elseif ($d['note'] !== 'none' && !($bad && empty($row['acted']) && ($d['act'] === 'later' || !empty($row['action_due'])))) {
            // A bad rating that waits for its comment gets its note with the action.
            $old = $row['value'];
            $text = $d['note'] === 'changed' && !empty($row['noted'])
                ? sprintf($t['note_changed'], self::label($scale, (int) $old, $s), self::label($scale, $value, $s))
                : sprintf($t['note_rated'], self::label($scale, $value, $s));
            self::note($ticket, $t['note_title'], $text);
            FeedbackStore::noted($id);
        }
        $fresh = FeedbackStore::find($id) ?: $row;
        return array('bad' => $bad, 'waiting' => !empty($fresh['action_due']) && empty($fresh['acted']));
    }

    /** A comment after the rating: runs a waiting bad-rating action, else a note. */
    public static function comment(array $row, string $comment): void
    {
        $id = (int) $row['id'];
        if ($comment !== '') {
            FeedbackStore::comment($id, $comment);
        }
        $ticket = Ticket::lookup((int) $row['ticket_id']);
        if (!$ticket) {
            return;
        }
        if (!empty($row['bad']) && empty($row['acted']) && !empty($row['action_due'])) {
            self::bad(FeedbackStore::find($id) ?: $row, $ticket, $comment);
            return;
        }
        if ($comment !== '') {
            $t = self::strings();
            self::note($ticket, $t['note_comment_title'], sprintf($t['note_comment'], nl2br(FeedbackMail::e(FeedbackRules::noteText($comment)), false)));
        }
    }

    /** Cron: bad ratings whose comment didn't come within COMMENT_WAIT. */
    public static function cron(): void
    {
        foreach (FeedbackStore::due() as $id) {
            try {
                $row = FeedbackStore::find($id);
                $ticket = $row ? Ticket::lookup((int) $row['ticket_id']) : null;
                if ($row && $ticket) {
                    self::bad($row, $ticket, FeedbackStore::decodeComment($row['comment']));
                } else {
                    FeedbackStore::act($id);
                }
            } catch (\Throwable $e) {
                FeedbackTrigger::log('due', $e);
            }
        }
    }

    /** The bad-rating action, at most once per ticket. */
    private static function bad(array $row, Ticket $ticket, string $comment): void
    {
        if (!FeedbackStore::act((int) $row['id'])) {
            return;
        }
        $s = FeedbackSettings::get();
        $t = self::strings();
        $label = self::label((string) $row['scale'], (int) $row['value'], $s);
        $html = sprintf($t['note_rated'], $label);
        if ($comment !== '') {
            $html .= '<br>' . sprintf($t['note_comment'], nl2br(FeedbackMail::e(FeedbackRules::noteText($comment)), false));
        }
        FeedbackStore::noted((int) $row['id']);

        if ($s['on_bad'] === FeedbackSettings::BAD_NOTIFY) {
            self::note($ticket, $t['note_title'], $html);
            self::mailAgent($ticket, $s, $t, $label, $comment);
            return;
        }
        $staff = self::lastAgent($ticket);
        // assignToStaff() reopens a closed ticket (onAssign) and sends the
        // core's assignment alert with $html as %{comments}; that also
        // becomes the internal note.
        if ($staff && $staff->isAvailable() && $ticket->assignToStaff($staff, $html, true)) {
            return;
        }
        $ticket->reopen();
        self::note($ticket, $t['note_title'], $html . '<br>' . FeedbackMail::e($t['note_no_agent']));
    }

    /** The last human agent who replied, or null. */
    private static function lastAgent(Ticket $ticket): ?Staff
    {
        $entry = FeedbackTrigger::lastResponse($ticket);
        $staff = $entry ? Staff::lookup((int) $entry->staff_id) : null;
        return $staff instanceof Staff && $staff->isActive() ? $staff : null;
    }

    /** "Note + mail": to the assigned (or closing) agent, else the last one who replied. */
    private static function mailAgent(Ticket $ticket, array $s, array $t, string $label, string $comment): void
    {
        global $cfg;

        $staff = $ticket->getStaff();
        if (!$staff instanceof Staff || !$staff->isActive() || FeedbackTrigger::isAi((int) $staff->getId())) {
            $staff = self::lastAgent($ticket);
        }
        $email = $cfg ? ($cfg->getAlertEmail() ?: $cfg->getDefaultEmail()) : null;
        if (!$staff || !$email) {
            return;
        }
        $link = FeedbackPlugin::baseUrl() . '/scp/tickets.php?id=' . (int) $ticket->getId();
        $msg = FeedbackMail::agent($s, $t, FeedbackPlugin::desk(), $label, $comment, $link);
        $msg = $ticket->replaceVars($msg, array('recipient' => $staff));
        $email->sendAlert($staff, FeedbackMail::oneLine((string) $msg['subj']), (string) $msg['body']);
    }

    /** After the question went out: a note, unless switched off. Mail mode only. */
    public static function sentNote(Ticket $ticket, array $s, string $to, int $expires, string $subject, string $body): void
    {
        if ($s['note_sent'] === FeedbackSettings::NOTE_OFF) {
            return;
        }
        $t = self::strings();
        self::note($ticket, $t['note_sent_title'], FeedbackMail::sentNote($s, $t, $to, $expires, $subject, $body));
    }

    /** A question that couldn't be sent: always a note, so nobody believes the customer was asked. */
    public static function failedNote(Ticket $ticket, string $to): void
    {
        $t = self::strings();
        self::note($ticket, $t['note_sent_title'], FeedbackMail::e(sprintf($t['note_failed'], $to !== '' ? $to : '?')));
    }

    private static function note(Ticket $ticket, string $title, string $html): void
    {
        $ticket->logNote($title, $html, $title, false);
    }

    private static function label(string $scale, int $value, array $s): string
    {
        $t = self::strings();
        return FeedbackScale::noteLabel($scale, $value, $t) . ' ('
            . (FeedbackScale::isBad($scale, $value, $s['bad_stars'], $s['bad_nps']) ? $t['note_bad'] : $t['note_good']) . ')';
    }

    /** @return array<string,string> */
    private static function strings(): array
    {
        return FeedbackI18n::strings(FeedbackPlugin::language());
    }
}
