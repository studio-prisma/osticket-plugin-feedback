<?php

declare(strict_types=1);

/**
 * Notices when a ticket is closed and asks the customer, once per ticket.
 *
 * Detection: Ticket::setStatus() saves the ticket, the ORM sends
 * model.updated with the old status_id in $data['dirty']. A change from a
 * non-closed to a closed state is a close.
 *
 * "mail" mode only queues a row here. The mail goes out at the end of the
 * request (shutdown function), after osTicket has sent its own reply mail and
 * after AI Actions has rewritten its events; the cron picks up whatever a
 * dying request left behind. The checks run at send time.
 *
 * "line" mode works inside Ticket::postReply(): it creates the response
 * (threadentry.created, remembered here), then sets the status (model.updated,
 * handled here), then renders the reply mail from that same entry object. The
 * line is appended to the entry's cached body only, so the mail carries it and
 * the stored thread does not. See the spec, R1.
 *
 * Nothing in here may break closing a ticket: every handler catches Throwable
 * and logs it.
 */
final class FeedbackTrigger
{
    /** Backend name AI Actions gives its AI agents. */
    public const AI_BACKEND = 'ai-agent';

    /** @var array<int, ThreadEntry> responses created in this request, by ticket ID */
    private static $responses = array();

    /** @var bool */
    private static $flushing = false;

    /** @var array<int, string> where this request's closes came from, by ticket ID (for the note) */
    private static $sources = array();

    /**
     * "web" for an agent form on one ticket, "mass" for the status change of
     * selected tickets in the list, "api" without an agent session (API, cron).
     */
    public static function source(int $closer): string
    {
        if ($closer < 1) {
            return 'api';
        }
        $info = (string) ($_SERVER['PATH_INFO'] ?? '');
        if (preg_match('#^/tickets/status/#', $info) === 1 || (($_POST['a'] ?? '') === 'mass_process')) {
            return 'mass';
        }
        return 'web';
    }

    public static function sourceOf(int $ticketId): string
    {
        return self::$sources[$ticketId] ?? '';
    }

    /**
     * Never ask this ticket, with a note. Only when the rules would ask it
     * now; a ticket that wouldn't be asked anyway gets no row and no note.
     * $how: "close" (tick removed or API said no) or "menu".
     */
    public static function decline(Ticket $ticket, array $s, int $staffId, string $how): bool
    {
        $facts = self::facts($ticket);
        $facts['closed'] = true;
        if ($how === 'close' && FeedbackRules::skipReason($s, $facts) !== '') {
            return false;
        }
        if (!FeedbackStore::decline((int) $ticket->getId(), $s['scale'], $staffId)) {
            return false;
        }
        FeedbackActions::declinedNote($ticket, $how, $staffId);
        return true;
    }

    public static function onEntry($entry): void
    {
        try {
            if (!$entry instanceof ThreadEntry || (string) $entry->type !== 'R' || (int) $entry->staff_id < 1) {
                return;
            }
            $thread = $entry->getThread();
            if (!$thread || (string) $thread->object_type !== 'T') {
                return;
            }
            self::$responses[(int) $thread->object_id] = $entry;
        } catch (\Throwable $e) {
            self::log('entry', $e);
        }
    }

    public static function onUpdated($model, $data = null): void
    {
        try {
            if (!$model instanceof Ticket) {
                return;
            }
            $dirty = is_array($data) && isset($data['dirty']) && is_array($data['dirty']) ? $data['dirty'] : array();
            if (!array_key_exists('status_id', $dirty)) {
                return;
            }
            $s = FeedbackSettings::get();
            if (!$s['enabled'] || !FeedbackPlugin::key()) {
                return;
            }
            $status = $model->getStatus();
            if (!$status || $status->getState() !== 'closed') {
                return;
            }
            $old = $dirty['status_id'] ? TicketStatus::lookup((int) $dirty['status_id']) : null;
            if ($old && $old->getState() === 'closed') {
                return;
            }
            global $thisstaff;
            $closer = $thisstaff instanceof Staff ? (int) $thisstaff->getId() : 0;
            if ($closer && self::isAi($closer)) {
                return;
            }
            // Who decides (0.2): the agent's tick, the API's feedback flag or the setting.
            $source = self::source($closer);
            $decision = FeedbackRules::decide($s, $source, FeedbackRules::askValue($_POST['feedback_ask'] ?? null));
            if ($decision !== 'ask') {
                if ($decision === 'decline') {
                    self::decline($model, $s, $closer, 'close');
                }
                return;
            }
            self::$sources[(int) $model->getId()] = $source;
            if ($s['mode'] === FeedbackSettings::MODE_LINE) {
                self::line($model, $s, $closer);
                return;
            }
            if (FeedbackStore::queue((int) $model->getId(), $s['scale'], $closer)) {
                self::afterRequest();
            }
        } catch (\Throwable $e) {
            self::log('close', $e);
        }
    }

    /** Appends the question to the reply that is closing this ticket right now. */
    private static function line(Ticket $ticket, array $s, int $closer): void
    {
        $entry = self::$responses[(int) $ticket->getId()] ?? null;
        if (!$entry || self::isAi((int) $entry->staff_id)) {
            return;
        }
        // scp/tickets.php: "reply-to = none" posts the reply without a mail.
        if (isset($_POST['reply-to']) && is_string($_POST['reply-to']) && strcasecmp('none', $_POST['reply-to']) === 0) {
            return;
        }
        $facts = self::facts($ticket);
        $facts['human_replies'] = max(1, $facts['human_replies']);
        $facts['ai_closer'] = false;
        if (FeedbackRules::skipReason($s, $facts) !== '') {
            return;
        }
        $key = FeedbackPlugin::key();
        $nonce = FeedbackToken::nonce();
        $expires = time() + $s['valid_days'] * 86400;
        if (!FeedbackStore::line((int) $ticket->getId(), $s['scale'], $closer, $nonce, $expires)) {
            return;
        }
        $token = FeedbackToken::make($key, (int) $ticket->getId(), $expires, $nonce);
        $t = FeedbackI18n::strings(FeedbackPlugin::language());
        $html = FeedbackMail::line($s, $t, self::url($token));
        $body = $entry->getBody();
        if ($body instanceof HtmlThreadEntryBody) {
            $body->append($html);
        } else {
            $text = "\n\n" . ($s['line'] !== '' ? $s['line'] : $t['line']) . "\n";
            foreach (FeedbackScale::values($s['scale']) as $v) {
                $text .= html_entity_decode(FeedbackScale::button($s['scale'], $v, $t), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . ': ' . self::url($token)($v) . "\n";
            }
            $body->append($text);
        }
    }

    private static function afterRequest(): void
    {
        static $registered = false;
        if (!$registered) {
            $registered = true;
            register_shutdown_function(array(__CLASS__, 'flush'));
        }
    }

    /** Sends queued requests. Runs at the end of a request and in the cron. */
    public static function flush(): void
    {
        if (self::$flushing) {
            return;
        }
        self::$flushing = true;
        try {
            foreach (FeedbackStore::pending() as $id) {
                self::send($id);
            }
        } catch (\Throwable $e) {
            self::log('flush', $e);
        }
        self::$flushing = false;
    }

    public static function send(int $id): void
    {
        global $cfg;

        if (!FeedbackStore::claim($id)) {
            return;
        }
        $ticket = null;
        $to = '';
        try {
            $row = FeedbackStore::find($id);
            $ticket = $row ? Ticket::lookup((int) $row['ticket_id']) : null;
            $s = FeedbackSettings::get();
            $key = FeedbackPlugin::key();
            if (!$row || !$ticket || !$key) {
                FeedbackStore::drop($id);
                return;
            }
            $facts = self::facts($ticket);
            $facts['ai_closer'] = ((int) $row['closer_id'] && self::isAi((int) $row['closer_id'])) || self::closedByAi($ticket);
            if (FeedbackRules::skipReason($s, $facts) !== '') {
                FeedbackStore::drop($id);
                return;
            }
            $owner = $ticket->getOwner();
            $dept = $ticket->getDept();
            $email = ($dept ? $dept->getEmail() : null) ?: ($cfg ? $cfg->getDefaultEmail() : null);
            $to = $owner ? (string) $owner->getEmail() : '';
            if (!$owner || !$email) {
                FeedbackStore::failed($id);
                FeedbackActions::failedNote($ticket, $to);
                return;
            }
            $nonce = FeedbackToken::nonce();
            $expires = time() + $s['valid_days'] * 86400;
            // Saved before the mail goes out: a link must never point at nothing.
            FeedbackStore::sent($id, $nonce, $expires);
            $token = FeedbackToken::make($key, (int) $ticket->getId(), $expires, $nonce);
            $t = FeedbackI18n::strings(FeedbackPlugin::language());
            $msg = FeedbackMail::request($s, $t, self::url($token), FeedbackPlugin::desk());
            $msg = $ticket->replaceVars($msg, array('recipient' => $owner));
            $options = array();
            if ($last = self::lastResponse($ticket)) {
                // Threading: a reply to this mail lands in the ticket.
                $options['thread'] = $last;
            }
            $subject = FeedbackMail::oneLine((string) $msg['subj']);
            if ($email->send($owner, $subject, (string) $msg['body'], null, $options) === false) {
                FeedbackStore::failed($id);
                FeedbackActions::failedNote($ticket, $to);
                return;
            }
            FeedbackActions::sentNote($ticket, $s, $to, $expires, $subject, (string) $msg['body'],
                self::sourceOf((int) $ticket->getId()));
        } catch (\Throwable $e) {
            FeedbackStore::failed($id);
            self::log('send', $e);
            if ($ticket) {
                try {
                    FeedbackActions::failedNote($ticket, $to);
                } catch (\Throwable $e2) {
                    self::log('note', $e2);
                }
            }
        }
    }

    /** @return callable fn(int $value): string */
    public static function url(string $token): callable
    {
        $base = FeedbackPlugin::baseUrl();
        return static function (int $v) use ($base, $token): string {
            return $base . '/' . FeedbackPlugin::CLIENT_BASE . '/' . $token . '?v=' . $v;
        };
    }

    /** @return array<string,mixed> facts for FeedbackRules::skipReason() */
    public static function facts(Ticket $ticket): array
    {
        $owner = $ticket->getOwner();
        $email = $owner ? (string) $owner->getEmail() : '';
        return array(
            'closed'        => $ticket->isClosed(),
            'child'         => (int) $ticket->getPid() > 0,
            'dept'          => (int) $ticket->getDeptId(),
            'topic'         => (int) $ticket->getTopicId(),
            'status'        => (int) $ticket->getStatusId(),
            'email'         => $email,
            'own_domains'   => self::ownDomains(),
            'human_replies' => self::humanReplies($ticket),
            'ai_closer'     => false,
            'asked'         => false,
        );
    }

    public static function isAi(int $staffId): bool
    {
        if ($staffId < 1) {
            return false;
        }
        $backend = db_result(db_query(sprintf("SELECT backend FROM `%s` WHERE staff_id=%d", STAFF_TABLE, $staffId)));
        return (string) $backend === self::AI_BACKEND;
    }

    /** The latest "closed" event names an AI agent (AI Actions rewrites its events to the agent). */
    private static function closedByAi(Ticket $ticket): bool
    {
        $staff = db_result(db_query(sprintf("SELECT e.staff_id FROM `%s` e JOIN `%s` v ON v.id=e.event_id
            WHERE e.thread_id=%d AND v.name='closed' ORDER BY e.id DESC LIMIT 1",
            TABLE_PREFIX . 'thread_event', TABLE_PREFIX . 'event', (int) $ticket->getThreadId())));
        return self::isAi((int) $staff);
    }

    private static function humanReplies(Ticket $ticket): int
    {
        return (int) db_result(db_query(sprintf("SELECT COUNT(*) FROM `%s` e JOIN `%s` s ON s.staff_id=e.staff_id
            WHERE e.thread_id=%d AND e.type='R' AND COALESCE(s.backend,'')<>%s",
            THREAD_ENTRY_TABLE, STAFF_TABLE, (int) $ticket->getThreadId(), db_input(self::AI_BACKEND))));
    }

    /** The last reply by a human agent, or null. */
    public static function lastResponse(Ticket $ticket): ?ThreadEntry
    {
        $id = (int) db_result(db_query(sprintf("SELECT e.id FROM `%s` e JOIN `%s` s ON s.staff_id=e.staff_id
            WHERE e.thread_id=%d AND e.type='R' AND COALESCE(s.backend,'')<>%s ORDER BY e.id DESC LIMIT 1",
            THREAD_ENTRY_TABLE, STAFF_TABLE, (int) $ticket->getThreadId(), db_input(self::AI_BACKEND))));
        return $id ? (ThreadEntry::lookup($id) ?: null) : null;
    }

    /** Domains of the help desk's own system addresses. @return string[] */
    private static function ownDomains(): array
    {
        static $domains = null;
        if ($domains === null) {
            $domains = array();
            $res = db_query(sprintf("SELECT email FROM `%s`", EMAIL_TABLE));
            while ($res && ($r = db_fetch_row($res))) {
                if (($d = FeedbackRules::domain((string) $r[0])) !== '') {
                    $domains[] = $d;
                }
            }
            $domains = array_values(array_unique($domains));
        }
        return $domains;
    }

    public static function log(string $where, \Throwable $e): void
    {
        global $ost;
        $msg = sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine());
        if ($ost) {
            $ost->logError('Feedback plugin (' . $where . ')', $msg, false);
        } else {
            error_log('Feedback plugin (' . $where . '): ' . $msg);
        }
    }
}
