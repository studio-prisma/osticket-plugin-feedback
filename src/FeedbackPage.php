<?php

declare(strict_types=1);

/**
 * The public click page, ajax.php/feedback/<token>, no login.
 *
 *   GET   never stores anything. With a valid ?v= it shows a confirmation
 *         form that submits itself by JavaScript and has a button for
 *         browsers without it. Link scanners (Safe Links and the like)
 *         fetch the URL but don't submit the form, so they rate nothing.
 *   POST  do=rate stores the rating, do=comment the comment. osTicket's
 *         client.inc.php already rejects POSTs without a valid CSRF token
 *         for this session; the handler checks it again.
 *
 * Every invalid case (malformed, unknown, forged, expired, deleted ticket)
 * answers with the same neutral page. The page shows the ticket number and
 * nothing else of the ticket. Rate limit per IP hash and per token.
 */
final class FeedbackPage
{
    public const LIMIT_IP = 60;
    public const LIMIT_TOKEN = 20;

    public static function show(string $token): string
    {
        self::headers();
        $t = self::strings();
        if (!self::allowed('ip:' . self::ipHash())) {
            return self::limited($t);
        }
        $row = self::resolve($token);
        if (!$row) {
            return self::invalid($t);
        }
        if (!self::allowed('tok:' . $row['nonce'])) {
            return self::limited($t);
        }
        $number = (string) $row['number'];
        $value = FeedbackScale::parse((string) $row['scale'], $_GET['v'] ?? null);
        if ($value === null) {
            return self::render($t['page_title'], '<p>' . self::e(sprintf($t['choose_lead'], $number)) . '</p>'
                . self::choices($token, (string) $row['scale'], $t));
        }
        $body = '<p>' . self::e(sprintf($t['confirm_lead'], $number)) . '</p>'
            . '<p class="spf-value">' . FeedbackScale::label((string) $row['scale'], $value) . '</p>'
            . '<form id="spf-confirm" method="post" action="' . self::e(self::action($token)) . '">'
            . self::csrf()
            . '<input type="hidden" name="do" value="rate"><input type="hidden" name="v" value="' . $value . '">'
            . '<p class="spf-wait" hidden>' . self::e($t['confirm_wait']) . '</p>'
            . '<button type="submit" class="button spf-button">' . self::e($t['confirm_button']) . '</button></form>'
            // Scanners don't run this; people do, and then needn't click again.
            . '<script>(function(){var f=document.getElementById("spf-confirm");if(!f)return;'
            . 'var w=f.querySelector(".spf-wait");if(w)w.hidden=false;f.submit();})();</script>';
        return self::render($t['page_title'], $body);
    }

    public static function post(string $token): string
    {
        global $ost;

        self::headers();
        $t = self::strings();
        if (!self::allowed('ip:' . self::ipHash())) {
            return self::limited($t);
        }
        $row = self::resolve($token);
        if (!$row || !$ost || !$ost->checkCSRFToken()) {
            return self::invalid($t);
        }
        if (!self::allowed('tok:' . $row['nonce'])) {
            return self::limited($t);
        }
        $number = (string) $row['number'];
        $s = FeedbackSettings::get();
        $do = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';

        if ($do === 'comment') {
            $comment = FeedbackRules::comment($_POST['comment'] ?? '');
            if ($row['value'] === null) {
                return self::invalid($t);
            }
            FeedbackActions::comment($row, $comment);
            return self::render($t['thanks'], '<p>' . self::e($t['comment_done']) . '</p>');
        }
        $value = FeedbackScale::parse((string) $row['scale'], $_POST['v'] ?? null);
        if ($do !== 'rate' || $value === null) {
            return self::invalid($t);
        }
        $r = FeedbackActions::rate($row, $value, self::ipHash());
        $body = '<p>' . self::e(sprintf($t['thanks_lead'], $number)) . '</p>'
            . '<p class="spf-value">' . FeedbackScale::label((string) $row['scale'], $value) . '</p>'
            . '<p class="spf-hint">' . self::e(sprintf($t['change_hint'], self::date((int) $row['expires']))) . '</p>';
        if ($s['comment_on'] || $r['waiting']) {
            $body .= '<form method="post" action="' . self::e(self::action($token)) . '" class="spf-comment">'
                . self::csrf() . '<input type="hidden" name="do" value="comment">'
                . '<label for="spf-text">' . self::e($r['bad'] ? $t['comment_bad'] : $t['comment_label']) . '</label>'
                . '<textarea id="spf-text" name="comment" rows="4" maxlength="1000"></textarea>'
                . '<p><button type="submit" class="button spf-button">' . self::e($t['comment_send']) . '</button>'
                . ($r['waiting'] ? ' <button type="submit" name="skip" value="1" class="button spf-plain">' . self::e($t['comment_skip']) . '</button>' : '')
                . '</p></form>';
        }
        return self::render($t['thanks'], $body);
    }

    /**
     * The request row for a token, or null. One neutral answer for every way
     * of being wrong; the MAC is compared in constant time.
     *
     * @return array<string,mixed>|null with 'number' added
     */
    public static function resolve(string $token): ?array
    {
        $key = FeedbackPlugin::key();
        $parts = FeedbackToken::split($token);
        if (!$key || !$parts) {
            return null;
        }
        [$nonce, $mac] = $parts;
        $row = FeedbackStore::byNonce($nonce);
        if (!$row || !FeedbackToken::verify($key, (int) $row['ticket_id'], (int) $row['expires'], $nonce, $mac)) {
            return null;
        }
        if ((int) $row['expires'] <= time()) {
            return null;
        }
        $ticket = Ticket::lookup((int) $row['ticket_id']);
        if (!$ticket) {
            return null;
        }
        $row['number'] = $ticket->getNumber();
        return $row;
    }

    private static function choices(string $token, string $scale, array $t): string
    {
        $out = '<div class="spf-choices">';
        foreach (FeedbackScale::values($scale) as $v) {
            $out .= '<form method="post" action="' . self::e(self::action($token)) . '">' . self::csrf()
                . '<input type="hidden" name="do" value="rate"><input type="hidden" name="v" value="' . $v . '">'
                . '<button type="submit" class="button spf-button">' . FeedbackScale::button($scale, $v, $t) . '</button></form>';
        }
        return $out . '</div>';
    }

    private static function allowed(string $bucket): bool
    {
        $limit = strpos($bucket, 'ip:') === 0 ? self::LIMIT_IP : self::LIMIT_TOKEN;
        return FeedbackStore::hit($bucket) <= $limit;
    }

    private static function ipHash(): string
    {
        $key = FeedbackPlugin::key() ?: 'no-key';
        $ip = class_exists('osTicket') ? (string) osTicket::get_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return FeedbackToken::ipHash($key, $ip);
    }

    private static function invalid(array $t): string
    {
        http_response_code(404);
        return self::render($t['error_title'], '<p>' . self::e($t['error_lead']) . '</p>');
    }

    private static function limited(array $t): string
    {
        http_response_code(429);
        header('Retry-After: 600');
        return self::render($t['limit_title'], '<p>' . self::e($t['limit_lead']) . '</p>');
    }

    private static function headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        // The client header sets its own CSP (frame-ancestors 'self'), which would replace one sent here.
        header('X-Frame-Options: DENY');
        // The token is in the URL; it must not travel on to other sites.
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
    }

    /** Absolute path below the configured help desk URL, never from the Host header. */
    private static function action(string $token): string
    {
        return FeedbackPlugin::basePath() . FeedbackPlugin::CLIENT_BASE . '/' . $token;
    }

    private static function csrf(): string
    {
        global $ost;
        // csrf_token() echoes instead of returning; getFormInput() returns.
        return $ost && $ost->getCSRF() ? (string) $ost->getCSRF()->getFormInput() : '';
    }

    private static function date(int $ts): string
    {
        return date('d.m.Y', $ts);
    }

    private static function render(string $heading, string $body): string
    {
        // The core header and footer read these from the including scope.
        global $ost, $cfg, $thisclient, $nav, $errors, $msg, $warn, $info;

        $accent = FeedbackSettings::get()['accent'];
        if ($ost) {
            $ost->setPageTitle($heading . ' - ' . FeedbackPlugin::desk());
        }
        ob_start();
        require CLIENTINC_DIR . 'header.inc.php';
        echo '<style>.spf-page{margin:8px 0 32px}.spf-page h1{margin:0 0 16px}.spf-value{font-size:28px;margin:8px 0 16px}'
            . '.spf-choices{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0}.spf-choices form{margin:0}'
            . '.spf-button{background:' . $accent . ';border-color:' . $accent . ';color:#fff}'
            . '.spf-plain{background:transparent;color:inherit}.spf-hint{color:#777;font-size:13px}'
            . '.spf-comment label{display:block;margin:16px 0 6px}.spf-comment textarea{width:100%;max-width:560px;box-sizing:border-box}</style>';
        echo '<div class="spf-page"><h1>' . self::e($heading) . '</h1>' . $body . '</div>';
        require CLIENTINC_DIR . 'footer.inc.php';
        return (string) ob_get_clean();
    }

    /** @return array<string,string> */
    private static function strings(): array
    {
        return FeedbackI18n::strings(FeedbackPlugin::language());
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
