<?php

declare(strict_types=1);

/**
 * Feedback for osTicket 1.18.x: one satisfaction question when a ticket is
 * closed.
 *
 * Hooks, all without core patches:
 *
 *   model.updated        a ticket changes to a closed status
 *   threadentry.created  the reply that may be closing it ("line" mode)
 *   cron                 queued mails, waiting bad-rating actions, retention
 *   ajax.client          the public click page, ajax.php/feedback/<token>
 *   ajax.scp             the evaluation, scp/ajax.php/feedback/admin
 *
 * bootstrap() runs inside osTicket::start(), before $cfg is loaded: nothing
 * in here may read the help desk config.
 *
 * @see docs/INSTALL.md
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.app.php';
require_once INCLUDE_DIR . 'class.dispatcher.php';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/FeedbackScale.php';
require_once __DIR__ . '/src/FeedbackToken.php';
require_once __DIR__ . '/src/FeedbackSettings.php';
require_once __DIR__ . '/src/FeedbackI18n.php';
require_once __DIR__ . '/src/FeedbackMail.php';
require_once __DIR__ . '/src/FeedbackRules.php';
require_once __DIR__ . '/src/FeedbackStats.php';
require_once __DIR__ . '/src/FeedbackStore.php';
require_once __DIR__ . '/src/FeedbackTrigger.php';
require_once __DIR__ . '/src/FeedbackActions.php';
require_once __DIR__ . '/src/FeedbackPage.php';
require_once __DIR__ . '/src/FeedbackAdmin.php';

class FeedbackPlugin extends Plugin
{
    /** Mirrors plugin.php. */
    public const VERSION = '0.1.1';

    /** Route bases, relative to the help desk root. */
    public const CLIENT_BASE = 'ajax.php/feedback';
    public const STAFF_BASE = 'scp/ajax.php/feedback';

    /** Signal a weekly summary can send to collect one line, see FeedbackStats::digestSignal(). */
    public const DIGEST_SIGNAL = 'feedback.digest';

    var $config_class = 'FeedbackConfig';

    /** @var bool */
    private static $booted = false;

    /** One instance per installation: two would ask twice. */
    function isMultiInstance()
    {
        return false;
    }

    public function bootstrap(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $config = $this->getConfig();
        if (!$config) {
            return;
        }
        $raw = array();
        foreach (array('enabled', 'mode', 'scale', 'on_bad', 'bad_stars', 'bad_nps', 'valid_days', 'exclude_depts',
                     'exclude_topics', 'exclude_statuses', 'exclude_domains', 'exclude_own', 'comment_on', 'note_sent',
                     'language', 'subject', 'text',
                     'question', 'line', 'foot_on', 'foot', 'accent') as $k) {
            $raw[$k] = $config->get($k);
        }
        FeedbackSettings::configure($raw);

        // The click page and the evaluation stay reachable when the plugin is
        // switched off: links already sent keep working.
        Signal::connect('ajax.client', array(__CLASS__, 'clientRoutes'));
        Signal::connect('ajax.scp', array(__CLASS__, 'staffRoutes'));
        // registerStaffApp() isn't static in 1.18. Every agent sees the
        // entry; the page itself is for admins.
        (new Application())->registerStaffApp('Feedback', ROOT_PATH . self::STAFF_BASE . '/admin', array(
            'iconclass' => 'Ticket',
            'title'     => 'Feedback: customer ratings',
            'class'     => 'no-pjax',
        ));
        Signal::connect('cron', static function () {
            try {
                FeedbackTrigger::flush();
                FeedbackActions::cron();
                if (FeedbackStore::once('purge:' . intdiv(time(), 86400))) {
                    FeedbackStore::purge();
                }
            } catch (\Throwable $e) {
                FeedbackTrigger::log('cron', $e);
            }
        });
        Signal::connect(self::DIGEST_SIGNAL, array('FeedbackStats', 'digestSignal'));
        if (!FeedbackSettings::get()['enabled']) {
            return;
        }
        Signal::connect('threadentry.created', array('FeedbackTrigger', 'onEntry'));
        Signal::connect('model.updated', array('FeedbackTrigger', 'onUpdated'));
    }

    // Handlers are closures on purpose: given array('Class', 'method'), the
    // core dispatcher instantiates the class and requires an access() method
    // (include/class.dispatcher.php, UrlMatcher::dispatch).

    public static function clientRoutes($dispatcher): void
    {
        $token = '([A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{22})';
        $dispatcher->append(url_get('^/feedback/' . $token . '$', static function ($t) {
            return FeedbackPage::show((string) $t);
        }));
        $dispatcher->append(url_post('^/feedback/' . $token . '$', static function ($t) {
            return FeedbackPage::post((string) $t);
        }));
    }

    public static function staffRoutes($dispatcher): void
    {
        $dispatcher->append(url_get('^/feedback/admin$', static function () {
            return FeedbackAdmin::page();
        }));
    }

    /** Key material from SECRET_SALT, or null: then nobody is asked and no link is valid. */
    public static function key(): ?string
    {
        return FeedbackToken::key(defined('SECRET_SALT') ? (string) SECRET_SALT : null);
    }

    public static function language(): string
    {
        global $cfg;
        $primary = $cfg && method_exists($cfg, 'getPrimaryLanguage') ? (string) $cfg->getPrimaryLanguage() : '';
        return FeedbackI18n::language(FeedbackSettings::get()['language'], $primary);
    }

    /** The configured help desk URL without trailing slash; links never come from the Host header. */
    public static function baseUrl(): string
    {
        global $cfg;
        return rtrim($cfg ? (string) $cfg->getBaseUrl() : '', '/');
    }

    /** Path part of the help desk URL, with trailing slash, e.g. "/" or "/support/". */
    public static function basePath(): string
    {
        $path = (string) parse_url(self::baseUrl(), PHP_URL_PATH);
        return rtrim($path, '/') . '/';
    }

    public static function desk(): string
    {
        global $cfg;
        $title = $cfg ? trim((string) $cfg->getTitle()) : '';
        return $title !== '' ? $title : 'Service Desk';
    }
}
