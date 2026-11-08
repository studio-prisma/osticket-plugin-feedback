<?php

declare(strict_types=1);

/**
 * Unit tests: no osTicket, no database, no network.
 *
 *   php tests/unit.php
 */

define('INCLUDE_DIR', __DIR__ . '/stubs/');
define('ROOT_PATH', '/');

$dir = getenv('PLUGIN_DIR') ?: dirname(__DIR__);
require $dir . '/class.FeedbackPlugin.php';

$failures = 0;
$count = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    if ($ok) {
        echo "ok   $name\n";
    } else {
        $failures++;
        echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n";
    }
}

// ----------------------------------------------------------------- manifest

$manifest = require $dir . '/plugin.php';
check('manifest: version matches the plugin class', $manifest['version'] === FeedbackPlugin::VERSION,
    $manifest['version'] . ' vs ' . FeedbackPlugin::VERSION);
check('manifest: id is namespaced', $manifest['id'] === 'studio-prisma:feedback');
list($file, $class) = explode(':', $manifest['plugin']);
check('manifest: plugin entry points at the class', $file === 'class.FeedbackPlugin.php' && $class === 'FeedbackPlugin');

// ------------------------------------------------------------------- config

$opts = (new FeedbackConfig())->getOptions();
$defaults = array();
foreach ($opts as $k => $field) {
    if (array_key_exists('default', $field->options)) {
        $defaults[$k] = $field->options['default'];
    }
}
$fromConfig = FeedbackSettings::normalise($defaults);
$builtIn = FeedbackSettings::normalise(array());
check('config: form defaults and built-in defaults agree', $fromConfig === $builtIn,
    json_encode(array_diff_assoc(array_map('json_encode', $fromConfig), array_map('json_encode', $builtIn))));
check('config: spec defaults (mail, thumbs, reopen, 14 days, comment on, own domains excluded)',
    $builtIn['mode'] === 'mail' && $builtIn['scale'] === 'thumbs' && $builtIn['on_bad'] === 'reopen'
    && $builtIn['valid_days'] === 14 && $builtIn['comment_on'] === true && $builtIn['exclude_own'] === true
    && $builtIn['bad_stars'] === 2 && $builtIn['bad_nps'] === 6);
check('config: off until switched on', $builtIn['enabled'] === false);
foreach (array('enabled', 'mode', 'scale', 'on_bad', 'bad_stars', 'bad_nps', 'valid_days', 'exclude_depts', 'exclude_topics',
             'exclude_statuses', 'exclude_domains', 'exclude_own', 'comment_on', 'note_sent', 'agent_decline', 'no_session',
             'language', 'subject', 'text',
             'question', 'line', 'foot_on', 'foot', 'accent') as $k) {
    check("config: field $k exists", isset($opts[$k]));
}

// ----------------------------------------------------------------- settings

$s = FeedbackSettings::normalise(array(
    'enabled' => '1', 'mode' => 'line', 'scale' => 'nps', 'on_bad' => 'notify', 'bad_stars' => '9', 'bad_nps' => '-3',
    'valid_days' => '500', 'exclude_depts' => '{"4":"Alerts","x":"bad","7":"Logs"}', 'exclude_topics' => array(3, '5'),
    'exclude_domains' => "@Example.COM\n*.corp.example, bad domain\nlocalhost", 'accent' => 'red',
));
check('settings: stored values are taken', $s['enabled'] && $s['mode'] === 'line' && $s['scale'] === 'nps' && $s['on_bad'] === 'notify');
check('settings: numbers clamped', $s['bad_stars'] === 4 && $s['bad_nps'] === 0 && $s['valid_days'] === 90);
check('settings: department JSON from a multiselect, junk keys dropped', $s['exclude_depts'] === array(4, 7));
check('settings: topic list from an array', $s['exclude_topics'] === array(3, 5));
check('settings: domains cleaned, invalid dropped', $s['exclude_domains'] === array('example.com', 'corp.example'),
    json_encode($s['exclude_domains']));
check('settings: bad accent falls back', $s['accent'] === '#330066' && FeedbackSettings::normalise(array('accent' => "#112233\n"))['accent'] === '#330066');
$s = FeedbackSettings::normalise(array('mode' => 'sms', 'scale' => 'hearts', 'on_bad' => 'delete', 'valid_days' => 'abc'));
check('settings: unknown choices fall back to the defaults', $s['mode'] === 'mail' && $s['scale'] === 'thumbs'
    && $s['on_bad'] === 'reopen' && $s['valid_days'] === 14);
check('settings: checkbox values', FeedbackSettings::normalise(array('enabled' => '0'))['enabled'] === false
    && FeedbackSettings::normalise(array('enabled' => true))['enabled'] === true
    && FeedbackSettings::normalise(array('comment_on' => ''))['comment_on'] === false);
check('settings: note after sending is short by default, unknown falls back',
    FeedbackSettings::normalise(array())['note_sent'] === 'short'
    && FeedbackSettings::normalise(array('note_sent' => 'full'))['note_sent'] === 'full'
    && FeedbackSettings::normalise(array('note_sent' => 'off'))['note_sent'] === 'off'
    && FeedbackSettings::normalise(array('note_sent' => 'loud'))['note_sent'] === 'short');
check('settings: closing statuses from a multiselect', FeedbackSettings::normalise(array('exclude_statuses' => '{"3":"Hinfällig"}'))['exclude_statuses'] === array(3)
    && FeedbackSettings::normalise(array())['exclude_statuses'] === array());

// -------------------------------------------------------------------- scale

check('scale: thumbs values', FeedbackScale::values('thumbs') === array(1, 0));
check('scale: stars values', FeedbackScale::values('stars') === array(1, 2, 3, 4, 5));
check('scale: nps values', FeedbackScale::values('nps') === range(0, 10));
check('scale: parse accepts members only', FeedbackScale::parse('stars', '3') === 3 && FeedbackScale::parse('stars', '0') === null
    && FeedbackScale::parse('stars', '6') === null && FeedbackScale::parse('nps', '10') === 10 && FeedbackScale::parse('thumbs', '2') === null);
check('scale: parse rejects non-strings and padding', FeedbackScale::parse('nps', 5) === null && FeedbackScale::parse('nps', ' 5') === null
    && FeedbackScale::parse('nps', '05') === null && FeedbackScale::parse('nps', '5abc') === null && FeedbackScale::parse('nps', array('5')) === null
    && FeedbackScale::parse('nps', '-1') === null && FeedbackScale::parse('nps', "5\n") === null);
check('scale: bad thumbs', FeedbackScale::isBad('thumbs', 0) && !FeedbackScale::isBad('thumbs', 1));
check('scale: bad stars up to 2 by default', FeedbackScale::isBad('stars', 2) && !FeedbackScale::isBad('stars', 3));
check('scale: bad stars threshold settable', FeedbackScale::isBad('stars', 3, 3) && !FeedbackScale::isBad('stars', 4, 3));
check('scale: bad NPS up to 6 by default', FeedbackScale::isBad('nps', 6) && !FeedbackScale::isBad('nps', 7));
check('scale: labels are ASCII (3-byte utf8 database)', preg_match('/[^\x20-\x7e]/', FeedbackScale::label('thumbs', 1)
    . FeedbackScale::label('thumbs', 0) . FeedbackScale::label('stars', 4) . FeedbackScale::label('nps', 9)) === 0);
$deT = FeedbackI18n::strings('de');
check('scale: note labels have no 4-byte characters (osTicket decodes entities before saving)',
    preg_match('/[\x{10000}-\x{10FFFF}]/u', FeedbackScale::noteLabel('thumbs', 1, $deT) . FeedbackScale::noteLabel('thumbs', 0, $deT)
    . FeedbackScale::noteLabel('stars', 3, $deT) . FeedbackScale::noteLabel('nps', 7, $deT)) === 0);
check('scale: note labels', FeedbackScale::noteLabel('thumbs', 1, $deT) === 'Daumen hoch'
    && FeedbackScale::noteLabel('stars', 2, $deT) === "\u{2605}\u{2605}\u{2606}\u{2606}\u{2606} (2/5)");
check('rules: note text keeps 3-byte characters, drops emoji, defuses brackets', FeedbackRules::noteText('gut 👍 ä ★ <b>') === "gut  ä ★ \u{2039}b\u{203A}");
check('scale: star label',FeedbackScale::label('stars', 2) === '&#x2605;&#x2605;&#x2606;&#x2606;&#x2606; (2/5)');

// -------------------------------------------------------------------- token

$key = str_repeat('k', 32);
check('token: sample SECRET_SALT refused', FeedbackToken::key('%CONFIG-SIRI') === null);
check('token: missing or short salt refused', FeedbackToken::key(null) === null && FeedbackToken::key('short') === null);
check('token: real salt accepted', FeedbackToken::key($key) === $key);
$nonce = FeedbackToken::nonce();
check('token: nonce is 22 base64url characters', preg_match('/^[A-Za-z0-9_-]{22}$/', $nonce) === 1);
check('token: nonces differ', FeedbackToken::nonce() !== FeedbackToken::nonce());
$exp = 1800000000;
$tok = FeedbackToken::make($key, 42, $exp, $nonce);
check('token: format', preg_match(FeedbackToken::PATTERN, $tok) === 1, $tok);
check('token: no ticket ID in it', strpos($tok, '42') === false || strpos($nonce, '42') !== false);
[$n, $m] = FeedbackToken::split($tok);
check('token: verifies for its ticket', FeedbackToken::verify($key, 42, $exp, $n, $m));
check('token: not for another ticket', !FeedbackToken::verify($key, 43, $exp, $n, $m));
check('token: not with another expiry', !FeedbackToken::verify($key, 42, $exp + 1, $n, $m));
check('token: not with another key', !FeedbackToken::verify(str_repeat('x', 32), 42, $exp, $n, $m));
check('token: not with another nonce', !FeedbackToken::verify($key, 42, $exp, FeedbackToken::nonce(), $m));
$flipped = $m;
$flipped[0] = $flipped[0] === 'A' ? 'B' : 'A';
check('token: a changed MAC fails', !FeedbackToken::verify($key, 42, $exp, $n, $flipped));
check('token: malformed rejected', FeedbackToken::split('abc') === null && FeedbackToken::split($tok . 'x') === null
    && FeedbackToken::split(str_replace('.', '-', $tok)) === null && FeedbackToken::split(null) === null
    && FeedbackToken::split($tok . "\n") === null && FeedbackToken::split(array($tok)) === null);
check('token: IP hash is keyed and stable', FeedbackToken::ipHash($key, '192.0.2.1') === FeedbackToken::ipHash($key, '192.0.2.1')
    && FeedbackToken::ipHash($key, '192.0.2.1') !== hash('sha256', '192.0.2.1')
    && FeedbackToken::ipHash($key, '192.0.2.1') !== FeedbackToken::ipHash(str_repeat('x', 32), '192.0.2.1'));

// --------------------------------------------------------------------- i18n

check('i18n: setting wins', FeedbackI18n::language('en', 'de_DE') === 'en' && FeedbackI18n::language('de', 'en_US') === 'de');
check('i18n: auto follows the help desk', FeedbackI18n::language('auto', 'de_DE') === 'de' && FeedbackI18n::language('auto', 'en_US') === 'en'
    && FeedbackI18n::language('auto', '') === 'en');
check('i18n: both languages have the same keys', array_keys(FeedbackI18n::strings('de')) === array_keys(FeedbackI18n::strings('en')));
$de = FeedbackI18n::strings('de');
check('i18n: German uses du', strpos($de['text'], 'dein Ticket') !== false && strpos($de['question'], 'bist du') !== false
    && strpos(implode(' ', $de), ' Sie ') === false && strpos(implode(' ', $de), ' Ihr') === false);
check('i18n: no 4-byte characters in notes (3-byte utf8 database)', preg_match('/[\x{10000}-\x{10FFFF}]/u',
    $de['note_title'] . $de['note_rated'] . $de['note_changed'] . $de['note_comment'] . $de['note_no_agent']
    . $de['note_bad'] . $de['note_good'] . $de['note_comment_title']) === 0);

// --------------------------------------------------------------------- mail

$url = static function (int $v): string {
    return 'https://support.example.com/ajax.php/feedback/TOKEN?v=' . $v;
};
$s = FeedbackSettings::normalise(array('enabled' => '1'));
$mail = FeedbackMail::request($s, $de, $url, 'Example Service Desk');
check('mail: brand line in capitals', strpos($mail['body'], 'EXAMPLE SERVICE DESK') !== false);
check('mail: two thumb buttons', substr_count($mail['body'], '?v=') === 2 && strpos($mail['body'], '?v=1') !== false
    && strpos($mail['body'], '?v=0') !== false);
check('mail: buttons are table cells with a background colour', substr_count($mail['body'], 'bgcolor="#330066"') === 2);
check('mail: subject carries the ticket number variable', strpos($mail['subj'], '%{ticket.number}') !== false);
check('mail: body keeps osTicket variables', strpos($mail['body'], '%{recipient.name.first}') !== false
    && strpos($mail['body'], '%{ticket.number}') !== false);
check('mail: footer with AI notice and validity', strpos($mail['body'], 'KI-Assistent') !== false && strpos($mail['body'], '14 Tagen') !== false);
foreach (array('text-transform', 'letter-spacing', 'font-weight', 'max-width', 'border-radius', 'display:') as $css) {
    check("mail: no $css (caniemail)", stripos($mail['body'], $css) === false);
}
$stars = FeedbackMail::request(FeedbackSettings::normalise(array('scale' => 'stars')), $de, $url, 'X');
check('mail: five star buttons', substr_count($stars['body'], '?v=') === 5);
$nps = FeedbackMail::request(FeedbackSettings::normalise(array('scale' => 'nps')), $de, $url, 'X');
check('mail: eleven NPS buttons with end labels', substr_count($nps['body'], '?v=') === 11 && strpos($nps['body'], 'unwahrscheinlich') !== false);
check('mail: NPS asks the recommendation question', strpos($nps['body'], 'weiterempfiehlst') !== false);
$own = FeedbackMail::request(FeedbackSettings::normalise(array('subject' => "Hi\r\nBcc: x@example.com", 'text' => "<b>Hey</b>\n\nZweiter Absatz",
    'foot_on' => '0')), $de, $url, 'X');
check('mail: own subject is one line (no header injection)', strpos($own['subj'], "\n") === false && strpos($own['subj'], "\r") === false);
check('mail: own text escaped, blank line = paragraph', strpos($own['body'], '&lt;b&gt;Hey&lt;/b&gt;') !== false
    && substr_count($own['body'], '<p style="margin:0 0 12px">') === 2);
check('mail: footer can be switched off', strpos($own['body'], 'KI-Assistent') === false);
$evil = static function (int $v): string {
    return 'https://x.example/"><script>' . $v;
};
check('mail: URLs are attribute-escaped', strpos(FeedbackMail::buttons('thumbs', $de, $evil, '#330066'), '<script>') === false);
$line = FeedbackMail::line($s, $de, $url);
check('line: question and two buttons', strpos($line, $de['line']) !== false && substr_count($line, '?v=') === 2);
$agent = FeedbackMail::agent($s, $de, 'X', '&#x1F44E; (nicht zufrieden)', "Gar <nicht> gut\nzweite Zeile", 'https://support.example.com/scp/tickets.php?id=1');
check('agent mail: comment escaped with line breaks', strpos($agent['body'], 'Gar &lt;nicht&gt; gut<br') !== false);
check('agent mail: subject names the bad rating', strpos($agent['subj'], 'Schlechte Bewertung') !== false);

$exp = mktime(12, 0, 0, 11, 22, 2026);
$short = FeedbackMail::sentNote($s, $de, 'kim@acme.example', $exp, 'Kurze Frage [001]', $mail['body']);
check('sent note: short names recipient, scale and validity', strpos($short, 'kim@acme.example') !== false
    && strpos($short, 'Skala: Daumen') !== false && strpos($short, '22.11.2026') !== false);
check('sent note: short has no links', strpos($short, '?v=') === false && strpos($short, '<hr>') === false);
$full = FeedbackMail::sentNote(FeedbackSettings::normalise(array('note_sent' => 'full')), $de, 'kim@acme.example', $exp,
    'Kurze Frage [001]', $mail['body']);
check('sent note: full carries the mail with both links', strpos($full, '<hr>') !== false && substr_count($full, '?v=') === 2
    && strpos($full, 'Kurze Frage [001]') !== false);
check('sent note: full keeps the button words, drops the emojis (3-byte utf8)', strpos($full, 'Nicht gut') !== false
    && stripos($full, '&#x1F44') === false && preg_match('/[\x{10000}-\x{10FFFF}]/u', $full) === 0);
check('sent note: recipient escaped', strpos(FeedbackMail::sentNote($s, $de, '<b>x</b>', $exp, '', ''), '<b>') === false);
check('beyond BMP: entities and literals removed, the rest kept', FeedbackMail::noBeyondBmp('a &#x1F44D; b &#128078; c 👍 d &#x2605; &amp;')
    === 'a b c d &#x2605; &amp;', FeedbackMail::noBeyondBmp('a &#x1F44D; b &#128078; c 👍 d &#x2605; &amp;'));
check('sent note: names the source when known', strpos(FeedbackMail::sentNote($s, $de, 'k@x.example', $exp, '', '', 'api'),
    'Ausgelöst durch: API oder Cron.') !== false && strpos($short, 'Ausgelöst') === false);

// ----------------------------------------------------------- who decides (0.2)

$d = FeedbackSettings::normalise(array());
check('decide: defaults keep 0.1 behaviour', $d['agent_decline'] === true && $d['no_session'] === 'ask'
    && FeedbackRules::decide($d, 'web', null) === 'ask' && FeedbackRules::decide($d, 'api', null) === 'ask'
    && FeedbackRules::decide($d, 'mass', null) === 'ask');
check('decide: web tick removed declines', FeedbackRules::decide($d, 'web', '0') === 'decline');
check('decide: web tick on asks', FeedbackRules::decide($d, 'web', '1') === 'ask');
$noDecline = FeedbackSettings::normalise(array('agent_decline' => '0'));
check('decide: without agent_decline the tick is ignored', FeedbackRules::decide($noDecline, 'web', '0') === 'ask');
$req = FeedbackSettings::normalise(array('no_session' => 'requested'));
check('decide: "requested" skips API and mass without a flag', FeedbackRules::decide($req, 'api', null) === 'skip'
    && FeedbackRules::decide($req, 'mass', null) === 'skip');
check('decide: "requested" asks with feedback_ask=1, declines with 0', FeedbackRules::decide($req, 'api', '1') === 'ask'
    && FeedbackRules::decide($req, 'api', '0') === 'decline');
check('decide: "requested" does not touch web closes', FeedbackRules::decide($req, 'web', null) === 'ask');
$never = FeedbackSettings::normalise(array('no_session' => 'never'));
check('decide: "never" skips even when asked for', FeedbackRules::decide($never, 'api', '1') === 'skip'
    && FeedbackRules::decide($never, 'api', '0') === 'decline');
check('decide: unknown no_session falls back to ask', FeedbackSettings::normalise(array('no_session' => 'x'))['no_session'] === 'ask');
check('decide: only "1" and "0" count as a flag', FeedbackRules::askValue('1') === '1' && FeedbackRules::askValue('0') === '0'
    && FeedbackRules::askValue('yes') === null && FeedbackRules::askValue(array('1')) === null && FeedbackRules::askValue(null) === null);

check('i18n: no 4-byte characters in the sent notes', preg_match('/[\x{10000}-\x{10FFFF}]/u',
    $de['note_sent_title'] . $de['note_sent'] . $de['note_mail'] . $de['note_failed']) === 0);

// -------------------------------------------------------------------- rules

$on = FeedbackSettings::normalise(array('enabled' => '1', 'exclude_depts' => array(9), 'exclude_topics' => array(4),
    'exclude_domains' => 'partner.example', 'exclude_statuses' => array(3)));
$facts = array('closed' => true, 'child' => false, 'dept' => 1, 'topic' => 1, 'email' => 'alice@acme.example',
    'own_domains' => array('support.example.com', 'example.com'), 'human_replies' => 1, 'ai_closer' => false, 'asked' => false);
check('rules: plain case asks', FeedbackRules::skipReason($on, $facts) === '');
$cases = array(
    'disabled'  => array(FeedbackSettings::normalise(array()), array()),
    'asked'     => array($on, array('asked' => true)),
    'reopened'  => array($on, array('closed' => false)),
    'child'     => array($on, array('child' => true)),
    'ai_closer' => array($on, array('ai_closer' => true)),
    'no_reply'  => array($on, array('human_replies' => 0)),
    'dept'      => array($on, array('dept' => 9)),
    'topic'     => array($on, array('topic' => 4)),
    'status'    => array($on, array('status' => 3)),
    'no_email'  => array($on, array('email' => '')),
);
foreach ($cases as $reason => $c) {
    check("rules: $reason skips", FeedbackRules::skipReason($c[0], array_merge($facts, $c[1])) === $reason,
        FeedbackRules::skipReason($c[0], array_merge($facts, $c[1])));
}
check('rules: excluded domain', FeedbackRules::skipReason($on, array_merge($facts, array('email' => 'bob@partner.example'))) === 'domain');
check('rules: subdomain of an excluded domain', FeedbackRules::skipReason($on, array_merge($facts, array('email' => 'bob@mail.partner.example'))) === 'domain');
check('rules: similar domain is not excluded', FeedbackRules::skipReason($on, array_merge($facts, array('email' => 'bob@notpartner.example'))) === '');
check('rules: own domain excluded', FeedbackRules::skipReason($on, array_merge($facts, array('email' => 'me@example.com'))) === 'domain');
$noOwn = FeedbackSettings::normalise(array('enabled' => '1', 'exclude_own' => '0'));
check('rules: own domains can be allowed', FeedbackRules::skipReason($noOwn, array_merge($facts, array('email' => 'me@example.com'))) === '');
check('rules: domain is case-insensitive', FeedbackRules::domain('Bob@Partner.EXAMPLE') === 'partner.example');

$row = array('scale' => 'thumbs', 'value' => null, 'acted' => 0, 'action_due' => null);
$c = FeedbackSettings::normalise(array('enabled' => '1'));
$nc = FeedbackSettings::normalise(array('enabled' => '1', 'comment_on' => '0'));
check('rating: first good rating is noted, no action', FeedbackRules::onRating($c, $row, 1) === array('note' => 'rated', 'act' => 'none'));
check('rating: bad with comment field waits for the comment', FeedbackRules::onRating($c, $row, 0) === array('note' => 'rated', 'act' => 'later'));
check('rating: bad without comment field acts now', FeedbackRules::onRating($nc, $row, 0) === array('note' => 'rated', 'act' => 'now'));
check('rating: same value again changes nothing', FeedbackRules::onRating($c, array_merge($row, array('value' => '1')), 1)
    === array('note' => 'none', 'act' => 'none'));
check('rating: change is noted', FeedbackRules::onRating($c, array_merge($row, array('value' => '0', 'acted' => 1)), 1)
    === array('note' => 'changed', 'act' => 'none'));
check('rating: bad action at most once', FeedbackRules::onRating($nc, array_merge($row, array('value' => '1', 'acted' => 1)), 0)
    === array('note' => 'changed', 'act' => 'none'));
check('rating: waiting action cancelled by a good rating', FeedbackRules::onRating($c, array_merge($row, array('value' => '0', 'action_due' => 123)), 1)
    === array('note' => 'changed', 'act' => 'cancel'));
check('rating: waiting action keeps waiting on another bad value', FeedbackRules::onRating($c,
    array('scale' => 'stars', 'value' => '1', 'acted' => 0, 'action_due' => 123), 2) === array('note' => 'changed', 'act' => 'none'));
check('rating: stars threshold from the settings', FeedbackRules::onRating(FeedbackSettings::normalise(array('bad_stars' => '3', 'comment_on' => '0')),
    array('scale' => 'stars', 'value' => null, 'acted' => 0, 'action_due' => null), 3)['act'] === 'now');

check('comment: control characters removed, newlines kept', FeedbackRules::comment("a\x00b\x07c\r\nd\te") === "abc\nd\te");
check('comment: capped at 1000 characters', mb_strlen(FeedbackRules::comment(str_repeat('ä', 1500))) === 1000);
check('comment: non-strings and invalid UTF-8 become empty', FeedbackRules::comment(array('x')) === '' && FeedbackRules::comment("\xff\xfe") === '');
check('comment: emoji survive (stored JSON-escaped)', FeedbackRules::comment('gut 👍') === 'gut 👍');
check('comment: JSON storage is ASCII', preg_match('/[^\x20-\x7e]/', json_encode('gut 👍 ä', JSON_UNESCAPED_SLASHES)) === 0
    && FeedbackStore::decodeComment(json_encode('gut 👍 ä')) === 'gut 👍 ä');
check('comment: decode tolerates junk', FeedbackStore::decodeComment('{"a":1}') === '' && FeedbackStore::decodeComment(null) === '');

// -------------------------------------------------------------------- stats

$t0 = mktime(12, 0, 0, 11, 2, 2026); // Monday of ISO week 45
$rows = array(
    array('created' => $t0, 'scale' => 'thumbs', 'value' => '1', 'bad' => '0'),
    array('created' => $t0 + 3600, 'scale' => 'thumbs', 'value' => '0', 'bad' => '1'),
    array('created' => $t0 + 7200, 'scale' => 'thumbs', 'value' => '1', 'bad' => '0'),
    array('created' => $t0 + 86400, 'scale' => 'thumbs', 'value' => null, 'bad' => '0'),
    array('created' => $t0 - 7 * 86400, 'scale' => 'stars', 'value' => '4', 'bad' => '0'),
    array('created' => $t0 - 7 * 86400, 'scale' => 'stars', 'value' => '1', 'bad' => '1'),
);
$w = FeedbackStats::aggregate($rows, 'week');
check('stats: one group per week and scale, newest first', count($w) === 2 && $w[0]['period'] === '2026-W45' && $w[1]['period'] === '2026-W44');
check('stats: counts', $w[0]['asked'] === 4 && $w[0]['rated'] === 3 && $w[0]['good'] === 2 && $w[0]['bad'] === 1);
check('stats: thumbs score = share of good', $w[0]['score'] == 67);
check('stats: stars average', $w[1]['score'] == 2.5);
$m = FeedbackStats::aggregate($rows, 'month');
check('stats: months', count($m) === 2 && $m[0]['period'] === '2026-11' && $m[1]['period'] === '2026-10');
check('stats: NPS', FeedbackStats::score('nps', array(10, 9, 8, 6, 0)) == 0 && FeedbackStats::score('nps', array(10, 9)) == 100
    && FeedbackStats::score('nps', array(3)) == -100);
check('stats: no ratings, no score', FeedbackStats::score('thumbs', array()) === null && FeedbackStats::scoreText('nps', null) === '–');
check('stats: score texts', FeedbackStats::scoreText('stars', 4.25) === '4,3 / 5' && FeedbackStats::scoreText('nps', 20.0) === 'NPS +20');
$line = FeedbackStats::digestLine(array_slice($rows, 0, 4), 'de');
check('digest: one line', strpos($line, "\n") === false && strpos($line, '4 gefragt, 3 bewertet, 2 gut, 1 schlecht') !== false, $line);

// ------------------------------------------------------------------- result

echo "\n$count checks, $failures failed\n";
exit($failures > 0 ? 1 : 0);
