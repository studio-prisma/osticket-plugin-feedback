<?php

declare(strict_types=1);

/**
 * Plugin settings as one normalised array. Every value is clamped here, so
 * the rest of the code never sees a raw config string.
 */
final class FeedbackSettings
{
    public const MODE_MAIL = 'mail';
    public const MODE_LINE = 'line';
    public const BAD_REOPEN = 'reopen';
    public const BAD_NOTIFY = 'notify';
    public const NOTE_OFF = 'off';
    public const NOTE_SHORT = 'short';
    public const NOTE_FULL = 'full';

    /** @var array<string,mixed>|null */
    private static $current = null;

    /** @param array<string,mixed> $raw values as PluginConfig::get() returns them */
    public static function configure(array $raw): void
    {
        self::$current = self::normalise($raw);
    }

    /** @return array<string,mixed> */
    public static function get(): array
    {
        return self::$current ?? self::normalise(array());
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalise(array $raw): array
    {
        $mode = (string) ($raw['mode'] ?? '');
        $scale = (string) ($raw['scale'] ?? '');
        $bad = (string) ($raw['on_bad'] ?? '');
        $lang = (string) ($raw['language'] ?? '');
        $accent = (string) ($raw['accent'] ?? '');
        $note = (string) ($raw['note_sent'] ?? '');
        return array(
            'enabled'        => self::flag($raw['enabled'] ?? false),
            'mode'           => in_array($mode, array(self::MODE_MAIL, self::MODE_LINE), true) ? $mode : self::MODE_MAIL,
            'scale'          => in_array($scale, FeedbackScale::ALL, true) ? $scale : FeedbackScale::THUMBS,
            'on_bad'         => in_array($bad, array(self::BAD_REOPEN, self::BAD_NOTIFY), true) ? $bad : self::BAD_REOPEN,
            'bad_stars'      => self::int($raw['bad_stars'] ?? null, 2, 1, 4),
            'bad_nps'        => self::int($raw['bad_nps'] ?? null, 6, 0, 9),
            'valid_days'     => self::int($raw['valid_days'] ?? null, 14, 1, 90),
            'exclude_depts'  => self::ids($raw['exclude_depts'] ?? null),
            'exclude_topics' => self::ids($raw['exclude_topics'] ?? null),
            'exclude_statuses' => self::ids($raw['exclude_statuses'] ?? null),
            'exclude_domains'=> self::domains((string) ($raw['exclude_domains'] ?? '')),
            'exclude_own'    => self::flag($raw['exclude_own'] ?? true),
            'comment_on'     => self::flag($raw['comment_on'] ?? true),
            'note_sent'      => in_array($note, array(self::NOTE_OFF, self::NOTE_SHORT, self::NOTE_FULL), true)
                ? $note : self::NOTE_SHORT,
            'language'       => in_array($lang, array('de', 'en'), true) ? $lang : 'auto',
            'accent'         => preg_match('/^#[0-9a-fA-F]{6}$/D', $accent) === 1 ? strtolower($accent) : '#330066',
            'subject'        => self::text($raw['subject'] ?? '', 200),
            'text'           => self::text($raw['text'] ?? '', 2000),
            'question'       => self::text($raw['question'] ?? '', 300),
            'line'           => self::text($raw['line'] ?? '', 300),
            'foot_on'        => self::flag($raw['foot_on'] ?? true),
            'foot'           => self::text($raw['foot'] ?? '', 500),
        );
    }

    /** PluginConfig stores checkboxes as "1"/"0", true/false or an empty string. */
    private static function flag($v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'on';
    }

    private static function int($v, int $default, int $min, int $max): int
    {
        if (is_int($v)) {
            $n = $v;
        } elseif (is_string($v) && preg_match('/^\s*-?[0-9]{1,6}\s*$/', $v) === 1) {
            $n = (int) trim($v);
        } else {
            return $default;
        }
        return max($min, min($max, $n));
    }

    /**
     * A multiselect ChoiceField stores JSON {"id":"label"}; arrays come
     * from forms and tests.
     *
     * @return int[]
     */
    private static function ids($v): array
    {
        if (is_string($v) && $v !== '') {
            $v = json_decode($v, true);
        }
        if (!is_array($v)) {
            return array();
        }
        $out = array();
        // A list holds the IDs as values; a map (the stored JSON) as keys.
        $list = array_is_list($v);
        foreach ($v as $k => $label) {
            $id = $list ? $label : $k;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $out[] = (int) $id;
            }
        }
        $out = array_values(array_unique(array_filter($out, static function (int $i): bool {
            return $i > 0;
        })));
        sort($out);
        return $out;
    }

    /** One domain per line or comma separated; "@" and "*." prefixes are dropped. @return string[] */
    public static function domains(string $text): array
    {
        $out = array();
        foreach (preg_split('/[\s,;]+/', strtolower($text)) as $d) {
            $d = ltrim(preg_replace('/^\*\./', '', $d), '@.');
            if ($d !== '' && preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d) === 1) {
                $out[] = $d;
            }
        }
        return array_values(array_unique($out));
    }

    /** Free text from the settings; empty means the built-in text. */
    private static function text($v, int $max): string
    {
        $s = trim(str_replace("\r", '', is_scalar($v) ? (string) $v : ''));
        return mb_substr($s, 0, $max);
    }
}
