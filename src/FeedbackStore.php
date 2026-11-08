<?php

declare(strict_types=1);

/**
 * The plugin's own tables.
 *
 *   feedback_request  one row per asked ticket: token nonce, expiry, the
 *                     latest rating and comment, whether the bad-rating
 *                     action ran. UNIQUE(ticket_id) is the "at most once
 *                     per ticket" rule.
 *   feedback_hit      rate-limit counters per bucket and 10-minute window.
 *
 * Times are Unix seconds (INT), so the token MAC and every comparison use one
 * clock. Comments are stored JSON-encoded with \u escapes: osTicket's
 * database connection is 3-byte utf8 and would turn emojis into "????".
 * The IP address is never stored, only FeedbackToken::ipHash().
 *
 * Every query takes its values through db_input() or an int cast.
 */
final class FeedbackStore
{
    /** Rows older than this go in the cron (spec: 24 months). */
    public const KEEP_SECONDS = 730 * 86400;

    /** @var bool */
    private static $ready = false;

    public static function table(): string
    {
        return TABLE_PREFIX . 'feedback_request';
    }

    public static function hitTable(): string
    {
        return TABLE_PREFIX . 'feedback_hit';
    }

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }
        db_query(sprintf("CREATE TABLE IF NOT EXISTS `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ticket_id` INT UNSIGNED NOT NULL,
            `nonce` CHAR(22) NULL,
            `mode` VARCHAR(8) NOT NULL,
            `scale` VARCHAR(8) NOT NULL,
            `state` VARCHAR(12) NOT NULL,
            `closer_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `created` INT UNSIGNED NOT NULL,
            `expires` INT UNSIGNED NOT NULL DEFAULT 0,
            `sent` INT UNSIGNED NULL,
            `value` TINYINT NULL,
            `bad` TINYINT NOT NULL DEFAULT 0,
            `comment` TEXT NULL,
            `rated` INT UNSIGNED NULL,
            `ip_hash` CHAR(64) NULL,
            `changes` INT UNSIGNED NOT NULL DEFAULT 0,
            `noted` TINYINT NOT NULL DEFAULT 0,
            `acted` TINYINT NOT NULL DEFAULT 0,
            `action_due` INT UNSIGNED NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `ticket` (`ticket_id`),
            UNIQUE KEY `nonce` (`nonce`),
            KEY `state` (`state`),
            KEY `created` (`created`)
        ) DEFAULT CHARSET=utf8", self::table()));
        db_query(sprintf("CREATE TABLE IF NOT EXISTS `%s` (
            `bucket` CHAR(80) NOT NULL,
            `win` INT UNSIGNED NOT NULL,
            `hits` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`bucket`, `win`)
        ) DEFAULT CHARSET=utf8", self::hitTable()));
        self::$ready = true;
    }

    /** Queues a mail request. False when this ticket was already asked (or is queued). */
    public static function queue(int $ticketId, string $scale, int $closerId): bool
    {
        self::ensure();
        db_query(sprintf("INSERT IGNORE INTO `%s` (ticket_id, mode, scale, state, closer_id, created) VALUES (%d, 'mail', %s, 'pending', %d, %d)",
            self::table(), $ticketId, db_input($scale), $closerId, time()));
        return db_affected_rows() === 1;
    }

    /** Records a question that goes out with the closing reply, with its token data. */
    public static function line(int $ticketId, string $scale, int $closerId, string $nonce, int $expires): bool
    {
        self::ensure();
        db_query(sprintf("INSERT IGNORE INTO `%s` (ticket_id, nonce, mode, scale, state, closer_id, created, expires, sent)
            VALUES (%d, %s, 'line', %s, 'sent', %d, %d, %d, %d)",
            self::table(), $ticketId, db_input($nonce), db_input($scale), $closerId, time(), $expires, time()));
        return db_affected_rows() === 1;
    }

    public static function asked(int $ticketId): bool
    {
        self::ensure();
        return (bool) db_result(db_query(sprintf("SELECT COUNT(*) FROM `%s` WHERE ticket_id=%d", self::table(), $ticketId)));
    }

    /** The ticket's row state ("pending", "sent", "declined", …) or "" when it has none. */
    public static function stateOf(int $ticketId): string
    {
        self::ensure();
        return (string) db_result(db_query(sprintf("SELECT state FROM `%s` WHERE ticket_id=%d", self::table(), $ticketId)));
    }

    /** Never ask this ticket. False when it already has a row (asked, queued or declined). */
    public static function decline(int $ticketId, string $scale, int $staffId): bool
    {
        self::ensure();
        db_query(sprintf("INSERT IGNORE INTO `%s` (ticket_id, mode, scale, state, closer_id, created) VALUES (%d, 'mail', %s, 'declined', %d, %d)",
            self::table(), $ticketId, db_input($scale), $staffId, time()));
        return db_affected_rows() === 1;
    }

    /** Undoes decline(). False when the ticket wasn't declined (asking can't be undone). */
    public static function allow(int $ticketId): bool
    {
        self::ensure();
        db_query(sprintf("DELETE FROM `%s` WHERE ticket_id=%d AND state='declined'", self::table(), $ticketId));
        return db_affected_rows() === 1;
    }

    /** @return int[] IDs of queued mail requests */
    public static function pending(int $limit = 50): array
    {
        self::ensure();
        $out = array();
        // 'sending' older than an hour: a run that died midway; try once more.
        $res = db_query(sprintf("SELECT id FROM `%s` WHERE state='pending' OR (state='sending' AND created < %d) ORDER BY id LIMIT %d",
            self::table(), time() - 3600, $limit));
        while ($res && ($r = db_fetch_row($res))) {
            $out[] = (int) $r[0];
        }
        return $out;
    }

    /** Only one process sends a given request. */
    public static function claim(int $id): bool
    {
        db_query(sprintf("UPDATE `%s` SET state='sending' WHERE id=%d AND (state='pending' OR (state='sending' AND created < %d))",
            self::table(), $id, time() - 3600));
        return db_affected_rows() === 1;
    }

    public static function sent(int $id, string $nonce, int $expires): void
    {
        db_query(sprintf("UPDATE `%s` SET state='sent', nonce=%s, expires=%d, sent=%d WHERE id=%d",
            self::table(), db_input($nonce), $expires, time(), $id));
    }

    public static function failed(int $id): void
    {
        db_query(sprintf("UPDATE `%s` SET state='failed' WHERE id=%d", self::table(), $id));
    }

    /** A skipped request leaves no trace, so a later close of the ticket can still ask. */
    public static function drop(int $id): void
    {
        db_query(sprintf("DELETE FROM `%s` WHERE id=%d AND value IS NULL", self::table(), $id));
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        self::ensure();
        return self::one(sprintf("SELECT * FROM `%s` WHERE id=%d", self::table(), $id));
    }

    /** @return array<string,mixed>|null only rows whose question actually went out */
    public static function byNonce(string $nonce): ?array
    {
        self::ensure();
        return self::one(sprintf("SELECT * FROM `%s` WHERE nonce=%s AND state='sent'", self::table(), db_input($nonce)));
    }

    /** Saves the latest rating. $due: 0 keeps, -1 clears, >0 sets action_due. */
    public static function rate(int $id, int $value, bool $bad, string $ipHash, bool $changed, int $due): void
    {
        $set = sprintf("value=%d, bad=%d, rated=%d, ip_hash=%s, changes=changes+%d", $value, $bad ? 1 : 0, time(),
            db_input($ipHash), $changed ? 1 : 0);
        if ($due === -1) {
            $set .= ', action_due=NULL';
        } elseif ($due > 0) {
            $set .= sprintf(', action_due=%d', $due);
        }
        db_query(sprintf("UPDATE `%s` SET %s WHERE id=%d", self::table(), $set, $id));
    }

    public static function comment(int $id, string $comment): void
    {
        db_query(sprintf("UPDATE `%s` SET comment=%s WHERE id=%d", self::table(),
            db_input(json_encode($comment, JSON_UNESCAPED_SLASHES)), $id));
    }

    public static function decodeComment($stored): string
    {
        if (!is_string($stored) || $stored === '') {
            return '';
        }
        $v = json_decode($stored, true);
        return is_string($v) ? $v : '';
    }

    public static function noted(int $id): void
    {
        db_query(sprintf("UPDATE `%s` SET noted=1 WHERE id=%d", self::table(), $id));
    }

    /** Marks the bad-rating action as done. False if another request did it first. */
    public static function act(int $id): bool
    {
        db_query(sprintf("UPDATE `%s` SET acted=1, action_due=NULL WHERE id=%d AND acted=0", self::table(), $id));
        return db_affected_rows() === 1;
    }

    /** @return int[] requests whose bad-rating action waited long enough for a comment */
    public static function due(): array
    {
        self::ensure();
        $out = array();
        $res = db_query(sprintf("SELECT id FROM `%s` WHERE acted=0 AND action_due IS NOT NULL AND action_due <= %d LIMIT 50",
            self::table(), time()));
        while ($res && ($r = db_fetch_row($res))) {
            $out[] = (int) $r[0];
        }
        return $out;
    }

    /**
     * Counts one hit and returns the count in the current 10-minute window.
     */
    public static function hit(string $bucket): int
    {
        self::ensure();
        $win = intdiv(time(), 600);
        db_query(sprintf("INSERT INTO `%s` (bucket, win, hits) VALUES (%s, %d, 1) ON DUPLICATE KEY UPDATE hits=hits+1",
            self::hitTable(), db_input(substr($bucket, 0, 80)), $win));
        return (int) db_result(db_query(sprintf("SELECT hits FROM `%s` WHERE bucket=%s AND win=%d",
            self::hitTable(), db_input(substr($bucket, 0, 80)), $win)));
    }

    /** True the first time $key is seen within a day (the counters outlive a day, see purge()). */
    public static function once(string $key): bool
    {
        self::ensure();
        db_query(sprintf("INSERT IGNORE INTO `%s` (bucket, win, hits) VALUES (%s, %d, 1)",
            self::hitTable(), db_input('once:' . substr($key, 0, 70)), intdiv(time(), 600)));
        if (db_affected_rows() !== 1) {
            return false;
        }
        // A row from an earlier window of the same key means it already ran.
        return (int) db_result(db_query(sprintf("SELECT COUNT(*) FROM `%s` WHERE bucket=%s",
            self::hitTable(), db_input('once:' . substr($key, 0, 70))))) === 1;
    }

    /** Retention: requests after 24 months, orphans of deleted tickets, old counters. */
    public static function purge(): void
    {
        self::ensure();
        db_query(sprintf("DELETE FROM `%s` WHERE created < %d", self::table(), time() - self::KEEP_SECONDS));
        db_query(sprintf("DELETE r FROM `%s` r LEFT JOIN `%s` t ON t.ticket_id=r.ticket_id WHERE t.ticket_id IS NULL",
            self::table(), TICKET_TABLE));
        db_query(sprintf("DELETE FROM `%s` WHERE win < %d", self::hitTable(), intdiv(time(), 600) - 144));
    }

    /** @return array<int, array<string,mixed>> rows created since $since, for the statistics */
    public static function since(int $since): array
    {
        self::ensure();
        $rows = array();
        $res = db_query(sprintf("SELECT r.*, t.number FROM `%s` r LEFT JOIN `%s` t ON t.ticket_id=r.ticket_id
            WHERE r.created >= %d AND r.state IN ('sent','failed') ORDER BY r.id",
            self::table(), TICKET_TABLE, $since));
        while ($res && ($r = db_fetch_array($res))) {
            $rows[] = $r;
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    private static function one(string $sql): ?array
    {
        $res = db_query($sql);
        $row = $res ? db_fetch_array($res) : null;
        return $row ?: null;
    }
}
