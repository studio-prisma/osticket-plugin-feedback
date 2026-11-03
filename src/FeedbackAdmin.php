<?php

declare(strict_types=1);

/**
 * Evaluation page in the agent panel, scp/ajax.php/feedback/admin, for
 * admins only. Read-only: counts per week and month, the score per scale and
 * the latest comments. Comments are customer input and always escaped.
 */
final class FeedbackAdmin
{
    public const COMMENTS = 20;

    public static function page(): string
    {
        global $thisstaff, $nav;

        if (!$thisstaff instanceof Staff || !$thisstaff->isValid()) {
            Http::response(404, 'Not found');
            exit;
        }
        if (!$nav) {
            $nav = new StaffNav($thisstaff);
        }
        $nav->setActiveTab('apps');
        if (!$thisstaff->isAdmin()) {
            return self::render('<p>' . self::e(self::t('admins')) . '</p>');
        }
        $rows = FeedbackStore::since(time() - 400 * 86400);
        $body = self::status();
        $body .= '<h2>' . self::e(self::t('weeks')) . '</h2>'
            . self::table(array_slice(FeedbackStats::aggregate(self::recent($rows, 12 * 7), 'week'), 0, 24));
        $body .= '<h2>' . self::e(self::t('months')) . '</h2>'
            . self::table(array_slice(FeedbackStats::aggregate($rows, 'month'), 0, 24));
        $body .= '<h2>' . self::e(self::t('comments')) . '</h2>' . self::comments($rows);
        return self::render($body);
    }

    private static function status(): string
    {
        $s = FeedbackSettings::get();
        $items = array();
        if (!FeedbackPlugin::key()) {
            $items[] = '<div id="msg_error">' . self::e(self::t('no_key')) . '</div>';
        }
        $items[] = sprintf('<p>%s: <strong>%s</strong> · %s: <strong>%s</strong> · %s: <strong>%s</strong> · %s: <strong>%s</strong></p>',
            self::e(self::t('state')), self::e(self::t($s['enabled'] ? 'on' : 'off')),
            self::e(self::t('mode')), self::e(self::t('mode_' . $s['mode'])),
            self::e(self::t('scale')), self::e(self::t('scale_' . $s['scale'])),
            self::e(self::t('on_bad')), self::e(self::t('bad_' . $s['on_bad'])));
        return implode('', $items);
    }

    /** @param array<int, array<string,mixed>> $rows */
    private static function recent(array $rows, int $days): array
    {
        $since = time() - $days * 86400;
        return array_values(array_filter($rows, static function (array $r) use ($since): bool {
            return (int) $r['created'] >= $since;
        }));
    }

    private static function table(array $groups): string
    {
        if (!$groups) {
            return '<p>' . self::e(self::t('none')) . '</p>';
        }
        $html = '<table class="list" border="0" cellspacing="1" cellpadding="2" width="100%"><thead><tr>';
        foreach (array('period', 'scale', 'asked', 'rated', 'rate', 'good', 'bad', 'score') as $h) {
            $html .= '<th>' . self::e(self::t('col_' . $h)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($groups as $g) {
            $rate = $g['asked'] ? round($g['rated'] * 100 / $g['asked']) . ' %' : '–';
            $html .= sprintf('<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td></tr>',
                self::e($g['period']), self::e(self::t('scale_' . $g['scale'])), $g['asked'], $g['rated'], $rate,
                $g['good'], $g['bad'], self::e(FeedbackStats::scoreText($g['scale'], $g['score'])));
        }
        return $html . '</tbody></table>';
    }

    private static function comments(array $rows): string
    {
        $with = array_filter($rows, static function (array $r): bool {
            return FeedbackStore::decodeComment($r['comment']) !== '';
        });
        usort($with, static function (array $a, array $b): int {
            return (int) $b['rated'] <=> (int) $a['rated'];
        });
        $with = array_slice($with, 0, self::COMMENTS);
        if (!$with) {
            return '<p>' . self::e(self::t('none')) . '</p>';
        }
        $html = '<table class="list" border="0" cellspacing="1" cellpadding="2" width="100%"><thead><tr><th>'
            . self::e(self::t('col_ticket')) . '</th><th>' . self::e(self::t('col_rating')) . '</th><th>'
            . self::e(self::t('col_comment')) . '</th></tr></thead><tbody>';
        foreach ($with as $r) {
            $html .= sprintf('<tr><td><a href="tickets.php?id=%d">%s</a></td><td>%s</td><td>%s</td></tr>',
                (int) $r['ticket_id'], self::e((string) $r['number']),
                $r['value'] !== null ? FeedbackScale::label((string) $r['scale'], (int) $r['value']) : '–',
                nl2br(self::e(FeedbackStore::decodeComment($r['comment']))));
        }
        return $html . '</tbody></table>';
    }

    private static function render(string $body): string
    {
        global $ost, $cfg, $thisstaff, $nav, $errors, $msg, $warn, $info;

        // scp/ajax.php/... would break the core's relative links.
        $ost->addExtraHeader(sprintf('<base href="%s" />', self::e(ROOT_PATH . 'scp/')));
        ob_start();
        require STAFFINC_DIR . 'header.inc.php';
        echo '<h1>' . self::e(self::t('title')) . '</h1>' . $body;
        require STAFFINC_DIR . 'footer.inc.php';
        return (string) ob_get_clean();
    }

    private static function t(string $key): string
    {
        $de = FeedbackPlugin::language() === 'de';
        $map = array(
            'title'    => array('Feedback: Auswertung', 'Feedback: evaluation'),
            'admins'   => array('Die Auswertung sehen Administratoren.', 'The evaluation is for administrators.'),
            'no_key'   => array('SECRET_SALT in ost-config.php fehlt oder ist der Beispielwert. Ohne ihn fragt das Plugin niemanden.',
                                'SECRET_SALT in ost-config.php is missing or the sample value. Without it the plugin asks nobody.'),
            'state'    => array('Status', 'State'), 'on' => array('an', 'on'), 'off' => array('aus', 'off'),
            'mode'     => array('Wann', 'When'), 'mode_mail' => array('eigene Mail', 'own mail'),
            'mode_line' => array('Zeile unter der Antwort', 'line under the reply'),
            'scale'    => array('Skala', 'Scale'), 'scale_thumbs' => array('Daumen', 'Thumbs'),
            'scale_stars' => array('Sterne', 'Stars'), 'scale_nps' => array('NPS', 'NPS'),
            'on_bad'   => array('Bei schlecht', 'When bad'),
            'bad_reopen' => array('wieder öffnen + zuweisen', 'reopen + assign'),
            'bad_notify' => array('Notiz + Mail', 'note + mail'),
            'weeks'    => array('Je Woche (12 Wochen)', 'Per week (12 weeks)'),
            'months'   => array('Je Monat', 'Per month'),
            'comments' => array('Letzte Kommentare', 'Latest comments'),
            'none'     => array('Noch nichts.', 'Nothing yet.'),
            'col_period' => array('Zeitraum', 'Period'), 'col_scale' => array('Skala', 'Scale'),
            'col_asked' => array('Gefragt', 'Asked'), 'col_rated' => array('Bewertet', 'Rated'),
            'col_rate' => array('Quote', 'Rate'), 'col_good' => array('Gut', 'Good'), 'col_bad' => array('Schlecht', 'Bad'),
            'col_score' => array('Wert', 'Score'), 'col_ticket' => array('Ticket', 'Ticket'),
            'col_rating' => array('Bewertung', 'Rating'), 'col_comment' => array('Kommentar', 'Comment'),
        );
        return $map[$key][$de ? 0 : 1] ?? $key;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
