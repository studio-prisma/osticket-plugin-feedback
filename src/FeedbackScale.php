<?php

declare(strict_types=1);

/**
 * The three scales: which values exist, which count as bad, how a value
 * reads in a note. Pure functions, no osTicket.
 *
 * Emojis go out as HTML entities: osTicket's database connection is 3-byte
 * utf8, a literal 👍 in a thread note would be stored as "????".
 */
final class FeedbackScale
{
    public const THUMBS = 'thumbs';
    public const STARS = 'stars';
    public const NPS = 'nps';

    public const ALL = array(self::THUMBS, self::STARS, self::NPS);

    /** @return int[] */
    public static function values(string $scale): array
    {
        switch ($scale) {
            case self::STARS:
                return array(1, 2, 3, 4, 5);
            case self::NPS:
                return range(0, 10);
            default:
                return array(1, 0);
        }
    }

    /** A value from the URL or a form, or null when it doesn't belong to the scale. */
    public static function parse(string $scale, $raw): ?int
    {
        if (!is_string($raw) || preg_match('/^(0|[1-9][0-9]?)$/D', $raw) !== 1) {
            return null;
        }
        $v = (int) $raw;
        return in_array($v, self::values($scale), true) ? $v : null;
    }

    /**
     * Bad means: thumbs down, stars up to $badStars, NPS up to $badNps.
     */
    public static function isBad(string $scale, int $value, int $badStars = 2, int $badNps = 6): bool
    {
        switch ($scale) {
            case self::STARS:
                return $value <= $badStars;
            case self::NPS:
                return $value <= $badNps;
            default:
                return $value === 0;
        }
    }

    /** HTML for notes and pages, e.g. "&#x1F44D;" or "&#x2605;&#x2605;&#x2605;&#x2606;&#x2606; (3/5)". */
    public static function label(string $scale, int $value): string
    {
        switch ($scale) {
            case self::STARS:
                return str_repeat('&#x2605;', $value) . str_repeat('&#x2606;', 5 - $value) . ' (' . $value . '/5)';
            case self::NPS:
                return 'NPS ' . $value . '/10';
            default:
                return $value === 1 ? '&#x1F44D;' : '&#x1F44E;';
        }
    }

    /**
     * The value as stored in a thread note. osTicket's sanitizer decodes
     * every entity before saving, so a 👍 would reach the 3-byte utf8
     * connection as a 4-byte character and vanish; the stars (3 bytes) stay.
     */
    public static function noteLabel(string $scale, int $value, array $t): string
    {
        switch ($scale) {
            case self::STARS:
                return str_repeat("\u{2605}", $value) . str_repeat("\u{2606}", 5 - $value) . ' (' . $value . '/5)';
            case self::NPS:
                return 'NPS ' . $value . '/10';
            default:
                return $value === 1 ? $t['thumb_up_note'] : $t['thumb_down_note'];
        }
    }

    /** Text on a button in the mail or on the page. */
    public static function button(string $scale, int $value, array $t): string
    {
        switch ($scale) {
            case self::STARS:
                return $value . ' &#x2605;';
            case self::NPS:
                return (string) $value;
            default:
                return $value === 1 ? '&#x1F44D; ' . $t['thumb_up'] : '&#x1F44E; ' . $t['thumb_down'];
        }
    }
}
