<?php

declare(strict_types=1);

/**
 * Counting: per week or month, per scale. Pure over rows from
 * FeedbackStore::since(), so the numbers are unit-tested.
 *
 * Score per scale: thumbs = share of 👍 in %, stars = average,
 * NPS = % promoters (9–10) minus % detractors (0–6). The latest rating
 * counts; changes overwrite.
 */
final class FeedbackStats
{
    /**
     * @param array<int, array<string,mixed>> $rows
     * @param string $period 'week' (ISO week, "2026-W45") or 'month' ("2026-11")
     * @return array<int, array{period:string, scale:string, asked:int, rated:int, good:int, bad:int, score:?float}>
     */
    public static function aggregate(array $rows, string $period): array
    {
        $groups = array();
        foreach ($rows as $r) {
            $ts = (int) $r['created'];
            $key = $period === 'month' ? date('Y-m', $ts) : date('o-\WW', $ts);
            $scale = (string) $r['scale'];
            $g = &$groups[$key . '|' . $scale];
            if ($g === null) {
                $g = array('period' => $key, 'scale' => $scale, 'asked' => 0, 'rated' => 0, 'good' => 0, 'bad' => 0, 'values' => array());
            }
            $g['asked']++;
            if ($r['value'] !== null && $r['value'] !== '') {
                $g['rated']++;
                $g[(int) $r['bad'] ? 'bad' : 'good']++;
                $g['values'][] = (int) $r['value'];
            }
            unset($g);
        }
        krsort($groups);
        $out = array();
        foreach ($groups as $g) {
            $g['score'] = self::score($g['scale'], $g['values']);
            unset($g['values']);
            $out[] = $g;
        }
        return $out;
    }

    /** @param int[] $values */
    public static function score(string $scale, array $values): ?float
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        switch ($scale) {
            case FeedbackScale::STARS:
                return round(array_sum($values) / $n, 1);
            case FeedbackScale::NPS:
                $pro = count(array_filter($values, static function (int $v): bool {
                    return $v >= 9;
                }));
                $det = count(array_filter($values, static function (int $v): bool {
                    return $v <= 6;
                }));
                return round(($pro - $det) * 100 / $n);
            default:
                return round(count(array_filter($values, static function (int $v): bool {
                    return $v === 1;
                })) * 100 / $n);
        }
    }

    public static function scoreText(string $scale, ?float $score): string
    {
        if ($score === null) {
            return '–';
        }
        switch ($scale) {
            case FeedbackScale::STARS:
                return number_format($score, 1, ',', '') . ' / 5';
            case FeedbackScale::NPS:
                return 'NPS ' . ($score > 0 ? '+' : '') . (int) $score;
            default:
                return (int) $score . ' % 👍';
        }
    }

    /** One line for a weekly summary, e.g. "Feedback, 7 days: 12 asked, 7 rated, 6 good, 1 bad (86 % 👍)." */
    public static function digestLine(array $rows, string $lang): string
    {
        $asked = count($rows);
        $rated = $good = $bad = 0;
        $byScale = array();
        foreach ($rows as $r) {
            if ($r['value'] === null || $r['value'] === '') {
                continue;
            }
            $rated++;
            (int) $r['bad'] ? $bad++ : $good++;
            $byScale[(string) $r['scale']][] = (int) $r['value'];
        }
        $score = '';
        if (count($byScale) === 1) {
            $scale = (string) array_key_first($byScale);
            $score = ' (' . self::scoreText($scale, self::score($scale, $byScale[$scale])) . ')';
        }
        return $lang === 'de'
            ? sprintf('Feedback, 7 Tage: %d gefragt, %d bewertet, %d gut, %d schlecht%s.', $asked, $rated, $good, $bad, $score)
            : sprintf('Feedback, 7 days: %d asked, %d rated, %d good, %d bad%s.', $asked, $rated, $good, $bad, $score);
    }

    /**
     * Signal "feedback.digest": a weekly summary (AI Actions) can collect one
     * line per plugin. $lines is passed by reference; this appends a string.
     */
    public static function digestSignal($object, &$lines): void
    {
        try {
            if (!is_array($lines)) {
                $lines = array();
            }
            $lines[] = self::digestLine(FeedbackStore::since(time() - 7 * 86400), FeedbackPlugin::language());
        } catch (\Throwable $e) {
            FeedbackTrigger::log('digest', $e);
        }
    }
}
