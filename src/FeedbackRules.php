<?php

declare(strict_types=1);

/**
 * Who gets asked, and what a rating triggers. Pure decisions over facts the
 * caller collects from osTicket, so the unit tests cover every branch.
 */
final class FeedbackRules
{
    /**
     * Empty string: ask. Otherwise the reason not to.
     *
     * @param array<string,mixed> $s     FeedbackSettings::get()
     * @param array<string,mixed> $facts closed, child, dept, topic, status, email, own_domains, human_replies, ai_closer, asked
     */
    public static function skipReason(array $s, array $facts): string
    {
        if (!$s['enabled']) {
            return 'disabled';
        }
        if (!empty($facts['asked'])) {
            return 'asked';
        }
        if (empty($facts['closed'])) {
            return 'reopened';
        }
        if (!empty($facts['child'])) {
            return 'child';
        }
        if (!empty($facts['ai_closer'])) {
            return 'ai_closer';
        }
        if ((int) ($facts['human_replies'] ?? 0) < 1) {
            return 'no_reply';
        }
        if (in_array((int) ($facts['dept'] ?? 0), $s['exclude_depts'], true)) {
            return 'dept';
        }
        if (in_array((int) ($facts['topic'] ?? 0), $s['exclude_topics'], true)) {
            return 'topic';
        }
        if (in_array((int) ($facts['status'] ?? 0), $s['exclude_statuses'], true)) {
            return 'status';
        }
        $domain = self::domain((string) ($facts['email'] ?? ''));
        if ($domain === '') {
            return 'no_email';
        }
        $blocked = $s['exclude_domains'];
        if ($s['exclude_own']) {
            $blocked = array_merge($blocked, (array) ($facts['own_domains'] ?? array()));
        }
        foreach ($blocked as $d) {
            $d = strtolower((string) $d);
            if ($d !== '' && ($domain === $d || substr($domain, -strlen($d) - 1) === '.' . $d)) {
                return 'domain';
            }
        }
        return '';
    }

    public static function domain(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? '' : strtolower(trim(substr($email, $at + 1)));
    }

    /**
     * What a click does, given the stored state.
     *
     * @param array<string,mixed> $row   value (null before the first rating), acted, action_due
     * @return array{note:string, act:string} note: rated|changed|none; act: now|later|cancel|none
     */
    public static function onRating(array $s, array $row, int $value): array
    {
        $old = $row['value'] ?? null;
        $note = $old === null ? 'rated' : ((int) $old === $value ? 'none' : 'changed');
        $bad = FeedbackScale::isBad((string) $row['scale'], $value, $s['bad_stars'], $s['bad_nps']);
        if (!empty($row['acted'])) {
            // The bad-rating action runs at most once per ticket.
            return array('note' => $note, 'act' => 'none');
        }
        if ($bad) {
            if (!empty($row['action_due'])) {
                return array('note' => $note, 'act' => 'none');
            }
            // With the comment field the action waits for the comment (or the cron).
            return array('note' => $note, 'act' => $s['comment_on'] ? 'later' : 'now');
        }
        return array('note' => $note, 'act' => !empty($row['action_due']) ? 'cancel' : 'none');
    }

    /**
     * Customer text for a thread note. osTicket decodes every entity before
     * saving and then strips tags, so escaping alone would lose "<b>" and
     * whatever stands between brackets: angle brackets become look-alikes
     * (U+2039, U+203A). Characters beyond U+FFFF are removed (3-byte utf8
     * connection). The caller still escapes the result.
     */
    public static function noteText(string $s): string
    {
        $s = (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $s);
        return strtr($s, array('<' => "\u{2039}", '>' => "\u{203A}"));
    }

    /** A comment as stored: no control characters, at most $max characters. */
    public static function comment($raw, int $max = 1000): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $s = preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $raw));
        if ($s === null) {
            // Not valid UTF-8.
            return '';
        }
        return mb_substr(trim($s), 0, $max);
    }
}
