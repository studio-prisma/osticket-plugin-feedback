<?php

declare(strict_types=1);

/**
 * Agent panel (0.2): a tick "Send satisfaction question" in the forms that
 * can close one ticket (reply, note, status dialog), and "No satisfaction
 * question" / "Allow … again" in the ticket's More menu.
 *
 * The ticket view gets a small script through an output buffer, the way the
 * Board plugin adds its tab: osTicket has no hook for its forms. The forms
 * then post feedback_ask=0|1, which FeedbackTrigger reads at the close. The
 * menu posts to scp/ajax.php/feedback/ticket/<id>/decline|allow with the
 * agent panel's CSRF header (scp.js adds X-CSRFToken to every jQuery call).
 *
 * The tick is a convenience, not a security boundary: feedback_ask can only
 * suppress or confirm a question the rules allow, never widen them.
 */
final class FeedbackUi
{
    /** Starts the buffer on the agent panel's ticket view only. */
    public static function maybeStart(array $s): void
    {
        if (!$s['enabled'] || !$s['agent_decline']) {
            return;
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#/scp/tickets\.php$#', $script) !== 1 || !isset($_GET['id'])) {
            return;
        }
        ob_start(array(__CLASS__, 'inject'));
    }

    /** Output-buffer callback: adds the script to a ticket view (full page or PJAX fragment). */
    public static function inject($buffer)
    {
        try {
            if (!is_string($buffer) || !defined('OSTSCPINC') || strpos($buffer, 'id="reply"') === false
                && strpos($buffer, 'action-dropdown-more') === false) {
                return $buffer;
            }
            $staff = $GLOBALS['thisstaff'] ?? null;
            $ticket = Ticket::lookup((int) ($_GET['id'] ?? 0));
            if (!$staff instanceof Staff || !$ticket || !$ticket->checkStaffPerm($staff)) {
                return $buffer;
            }
            $config = self::config($ticket, FeedbackSettings::get());
            if ($config === null) {
                return $buffer;
            }
            $tag = '<script>' . self::js() . '(' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
                | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ');</script>';
            $pos = strripos($buffer, '</body>');
            return $pos === false ? $buffer . $tag : substr_replace($buffer, $tag, $pos, 0);
        } catch (\Throwable $e) {
            FeedbackTrigger::log('ui', $e);
            return $buffer;
        }
    }

    /** @return array<string,mixed>|null null when the ticket would never be asked */
    public static function config(Ticket $ticket, array $s): ?array
    {
        $facts = FeedbackTrigger::facts($ticket);
        // The closing form may carry the first reply; the status is chosen there.
        $facts['closed'] = true;
        $facts['human_replies'] = max(1, (int) $facts['human_replies']);
        $facts['status'] = 0;
        if (FeedbackRules::skipReason($s, $facts) !== '') {
            return null;
        }
        $state = FeedbackStore::stateOf((int) $ticket->getId());
        $t = FeedbackI18n::strings(FeedbackPlugin::language());
        return array(
            'tid'    => (int) $ticket->getId(),
            'state'  => $state === '' ? 'open' : ($state === 'declined' ? 'declined' : 'asked'),
            'closed' => self::askingStatuses($s),
            'url'    => 'ajax.php/feedback/ticket/' . (int) $ticket->getId() . '/',
            't'      => array('ask' => $t['ui_ask'], 'off' => $t['ui_menu_off'], 'on' => $t['ui_menu_on']),
        );
    }

    /** IDs of closed statuses that would ask. @return int[] */
    private static function askingStatuses(array $s): array
    {
        $out = array();
        $res = db_query(sprintf("SELECT id FROM %s WHERE state='closed'", TICKET_STATUS_TABLE));
        while ($res && ($r = db_fetch_row($res))) {
            if (!in_array((int) $r[0], $s['exclude_statuses'], true)) {
                $out[] = (int) $r[0];
            }
        }
        return $out;
    }

    /** POST scp/ajax.php/feedback/ticket/<id>/decline|allow */
    public static function toggle(int $id, string $what): string
    {
        global $thisstaff, $ost;

        $s = FeedbackSettings::get();
        if (!$thisstaff instanceof Staff) {
            Http::response(403, 'Access denied');
        }
        if (!$ost || !$ost->checkCSRFToken()) {
            Http::response(400, 'Invalid CSRF token');
        }
        $ticket = Ticket::lookup($id);
        if (!$s['enabled'] || !$s['agent_decline'] || !$ticket || !$ticket->checkStaffPerm($thisstaff)) {
            Http::response(403, 'Access denied');
        }
        $staffId = (int) $thisstaff->getId();
        if ($what === 'decline') {
            FeedbackTrigger::decline($ticket, $s, $staffId, 'menu');
        } elseif (FeedbackStore::allow($id)) {
            FeedbackActions::allowedNote($ticket, $staffId);
        }
        $state = FeedbackStore::stateOf($id);
        header('Content-Type: application/json');
        return (string) json_encode(array('state' => $state === '' ? 'open' : $state));
    }

    /** The script; called with the config object. Plain ES5 for the agent panel's jQuery. */
    private static function js(): string
    {
        return <<<'JS'
(function (c) {
  var $ = window.jQuery;
  if (!$ || !c) { return; }
  function esc(s) { return $('<div>').text(s).html(); }
  function asking(v) { return c.closed.indexOf(parseInt(v, 10)) >= 0; }
  // PJAX swaps tickets without a reload: act only on this ticket's forms.
  function mine(form) { return String(form.find('input[name=id]').val()) === String(c.tid); }
  function box(form, selector) {
    if (c.state !== 'open' || form.find('.spf-ask').length || !mine(form)) { return; }
    var field = form.find(selector).first();
    if (!field.length) { return; }
    var w = $('<div class="spf-ask" style="margin:6px 0">'
      + '<input type="hidden" name="feedback_ask" value="0">'
      + '<label><input type="checkbox" name="feedback_ask" value="1" checked> ' + esc(c.t.ask) + '</label></div>');
    field.after(w);
    var update = function () { w.toggle(asking(field.val())); };
    field.on('change', update);
    update();
  }
  function menu() {
    var ul = $('#action-dropdown-more ul');
    if (!ul.length || ul.find('.spf-menu').length || c.state === 'asked'
      || ul.html().indexOf('tickets/' + c.tid + '/') < 0) { return; }
    var off = c.state === 'declined';
    var li = $('<li class="spf-menu"><a href="#"><i class="icon-' + (off ? 'bell' : 'bell-slash') + '"></i> '
      + esc(off ? c.t.on : c.t.off) + '</a></li>');
    li.find('a').on('click', function (e) {
      e.preventDefault();
      $.ajax({ type: 'POST', url: c.url + (off ? 'allow' : 'decline'), dataType: 'json' })
        .always(function () { window.location.reload(); });
    });
    ul.append(li);
  }
  function scan() {
    $('form#reply').each(function () { box($(this), 'select[name=reply_status_id]'); });
    $('form#note').each(function () { box($(this), 'select[name=note_status_id]'); });
    $('form#status').each(function () {
      var form = $(this);
      if ((form.attr('action') || '').indexOf('tickets/' + c.tid + '/status') < 0) { return; }
      // The status dialog has no id field; its action names the ticket.
      if (!form.find('input[name=id]').length) { form.append($('<input type="hidden" name="id">').val(c.tid)); }
      box(form, '[name=status_id]');
    });
    menu();
  }
  var timer = null;
  if (window.MutationObserver) {
    new MutationObserver(function () {
      clearTimeout(timer);
      timer = setTimeout(scan, 50);
    }).observe(document.body, { childList: true, subtree: true });
  }
  $(scan);
})
JS;
    }
}
