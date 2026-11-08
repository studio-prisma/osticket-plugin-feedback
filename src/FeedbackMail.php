<?php

declare(strict_types=1);

/**
 * HTML for the feedback mail, the line under a closing reply and the mail to
 * the agent. Pure: the caller passes the URLs and the help desk name and runs
 * the result through osTicket's replaceVars() for %{ticket.*} and friends.
 *
 * The CSS sticks to what caniemail lists as supported by the large clients:
 * no text-transform, letter-spacing, font-weight, max-width, border-radius or
 * display. Bold comes from <strong>, capitals are written as capitals, and a
 * button is a table cell with a background colour (Outlook for Windows
 * ignores padding on <a>).
 */
final class FeedbackMail
{
    private const GREY = '#888888';
    private const TEXT = '#1d1d1f';

    /**
     * @param array<string,mixed>  $s    FeedbackSettings::get()
     * @param array<string,string> $t    FeedbackI18n::strings()
     * @param callable             $url  fn(int $value): string, absolute link for one value
     * @return array{subj:string, body:string}
     */
    public static function request(array $s, array $t, callable $url, string $desk): array
    {
        $accent = $s['accent'];
        $subject = $s['subject'] !== '' ? $s['subject'] : $t['subject'];
        $text = $s['text'] !== '' ? $s['text'] : $t['text'];
        $question = $s['question'] !== '' ? $s['question'] : ($s['scale'] === FeedbackScale::NPS ? $t['question_nps'] : $t['question']);

        $body = self::brand($desk, $accent)
            . self::paragraphs($text)
            . '<p style="margin:24px 0 0"><strong>' . self::e($question) . '</strong></p>'
            . self::buttons($s['scale'], $t, $url, $accent);
        if ($s['foot_on']) {
            $body .= self::foot(str_replace('{days}', (string) $s['valid_days'], $t['foot_lead']), $s['foot'] !== '' ? $s['foot'] : $t['foot']);
        }
        return array('subj' => self::oneLine($subject), 'body' => self::wrap($body));
    }

    /** The question under the reply that closes the ticket ("line" mode). */
    public static function line(array $s, array $t, callable $url): string
    {
        $text = $s['line'] !== '' ? $s['line'] : $t['line'];
        return '<div style="margin:24px 0 0;padding:12px 0 0;border-top:1px solid #e5e5e5">'
            . '<p style="margin:0"><strong>' . self::e($text) . '</strong></p>'
            . self::buttons($s['scale'], $t, $url, $s['accent'])
            . '</div>';
    }

    /**
     * Mail to the responsible agent ("note and mail" setting).
     *
     * @return array{subj:string, body:string}
     */
    public static function agent(array $s, array $t, string $desk, string $rating, string $comment, string $link): array
    {
        $accent = $s['accent'];
        $body = self::brand($desk, $accent, 'intern')
            . '<p style="margin:0 0 4px;font-size:18px"><strong>%{ticket.subject}</strong></p>'
            . '<p style="margin:0 0 20px;color:' . self::GREY . ';font-size:13px">%{ticket.number} · %{ticket.name}</p>'
            . '<div style="border-left:3px solid ' . $accent . ';padding:2px 0 2px 16px;margin:0 0 16px">'
            . '<p style="margin:0 0 8px;color:' . $accent . ';font-size:15px"><strong>' . sprintf(self::e($t['note_rated']), $rating) . '</strong></p>'
            . '<p style="margin:0 0 8px">' . self::e($t['agent_lead']) . '</p>'
            . ($comment !== '' ? '<p style="margin:0">' . sprintf(self::e($t['note_comment']), nl2br(self::e($comment))) . '</p>' : '')
            . '</div>'
            . self::button($link, self::e($t['agent_open']), $accent);
        return array('subj' => $t['agent_subject'], 'body' => self::wrap($body));
    }

    /** Rating buttons: one row of table cells; NPS gets its two end labels below. */
    public static function buttons(string $scale, array $t, callable $url, string $accent): string
    {
        $cells = '';
        foreach (FeedbackScale::values($scale) as $i => $v) {
            $pad = $scale === FeedbackScale::NPS ? '8px 9px' : '10px 18px';
            $cells .= ($i > 0 ? '<td style="width:6px;font-size:1px">&nbsp;</td>' : '')
                . '<td bgcolor="' . $accent . '" style="background-color:' . $accent . ';padding:' . $pad . ';text-align:center">'
                . '<a href="' . self::e($url($v)) . '" style="color:#ffffff;text-decoration:none;font-size:14px"><strong>'
                . FeedbackScale::button($scale, $v, $t) . '</strong></a></td>';
        }
        $html = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:16px 0 8px"><tr>'
            . $cells . '</tr></table>';
        if ($scale === FeedbackScale::NPS) {
            $html .= '<p style="margin:0;color:' . self::GREY . ';font-size:12px">' . self::e($t['nps_low']) . ' · '
                . self::e($t['nps_high']) . '</p>';
        }
        return $html;
    }

    private static function button(string $href, string $label, string $accent): string
    {
        return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:24px 0 8px"><tr>'
            . '<td bgcolor="' . $accent . '" style="background-color:' . $accent . ';padding:10px 20px">'
            . '<a href="' . self::e($href) . '" style="color:#ffffff;text-decoration:none;font-size:14px"><strong>' . $label . '</strong></a>'
            . '</td></tr></table>';
    }

    /** Brand line: the help desk name in capitals, accent colour, above an accent rule. */
    private static function brand(string $desk, string $accent, string $suffix = ''): string
    {
        return '<p style="margin:0 0 24px;padding:0 0 10px;border-bottom:2px solid ' . $accent . ';color:' . $accent
            . ';font-size:11px"><strong>' . self::e(mb_strtoupper($desk)) . '</strong>'
            . ($suffix !== '' ? '<span style="color:' . self::GREY . '">&nbsp;&nbsp;·&nbsp;&nbsp;' . self::e(mb_strtoupper($suffix)) . '</span>' : '')
            . '</p>';
    }

    private static function foot(string $lead, string $text): string
    {
        return '<p style="color:' . self::GREY . ';font-size:12px;margin:32px 0 0;padding:12px 0 0;border-top:1px solid #e5e5e5">'
            . self::e($lead) . '<br>' . self::e($text) . '</p>';
    }

    /** One font for every client; without it some Outlook versions fall back to Times. */
    private static function wrap(string $body): string
    {
        return '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;color:'
            . self::TEXT . '">' . $body . '</div>';
    }

    /** Settings text is plain text: escaped, blank lines become paragraphs, %{…} stays as it is. */
    public static function paragraphs(string $text): string
    {
        $out = '';
        foreach (preg_split("/\n\s*\n/", trim($text)) as $p) {
            if (trim($p) !== '') {
                $out .= '<p style="margin:0 0 12px">' . nl2br(self::e(trim($p)), false) . '</p>';
            }
        }
        return $out;
    }

    /**
     * The internal note after a sent question. "full" appends the mail as
     * sent, links included. Characters beyond U+FFFF (the thumbs emojis, as
     * entity or literal) are removed: osTicket decodes entities before saving
     * and the 3-byte utf8 connection would drop them; the buttons keep their
     * words ("Gut", "Nicht gut").
     *
     * @param array<string,mixed>  $s FeedbackSettings::get()
     * @param array<string,string> $t FeedbackI18n::strings()
     */
    public static function sentNote(array $s, array $t, string $to, int $expires, string $subject, string $body,
        string $source = ''): string
    {
        $scale = $t['scale_' . $s['scale']] ?? $s['scale'];
        $html = self::e(sprintf($t['note_sent'], $to, $scale, date($t['date_format'], $expires)));
        if (isset($t['source_' . $source])) {
            $html .= ' ' . self::e(sprintf($t['note_source'], $t['source_' . $source]));
        }
        if ($s['note_sent'] === FeedbackSettings::NOTE_FULL) {
            $html .= '<br><br>' . self::e(sprintf($t['note_mail'], $subject)) . '<hr>' . self::noBeyondBmp($body);
        }
        return $html;
    }

    /** Removes characters beyond U+FFFF, literal or as numeric entity, and the space after an entity. */
    public static function noBeyondBmp(string $html): string
    {
        $html = (string) preg_replace_callback('/&#(x[0-9a-f]{1,8}|[0-9]{1,10});\s?/i', static function (array $m): string {
            $n = ($m[1][0] === 'x' || $m[1][0] === 'X') ? hexdec(substr($m[1], 1)) : (int) $m[1];
            return $n > 0xFFFF ? '' : $m[0];
        }, $html);
        return (string) preg_replace('/[\x{10000}-\x{10FFFF}]\s?/u', '', $html);
    }

    /** A subject line never carries a line break (header injection) or markup. */
    public static function oneLine(string $s): string
    {
        return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags($s)));
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
