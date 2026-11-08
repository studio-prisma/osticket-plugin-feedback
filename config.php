<?php

declare(strict_types=1);

/**
 * Settings of the Feedback plugin. Off until switched on: it sends mail to
 * customers, so the exceptions should be checked first (docs/INSTALL.md).
 *
 * Every value is normalised by FeedbackSettings, never read raw.
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class FeedbackConfig extends PluginConfig
{
    public function getOptions()
    {
        return array(

            'sec_main' => new SectionBreakField(array(
                'label' => 'Satisfaction question',
            )),

            'enabled' => new BooleanField(array(
                'label'         => 'Ask customers',
                'default'       => false,
                'configuration' => array(
                    'desc' => 'When a ticket is closed after a reply by a human agent, the customer is asked once. '
                            . 'Evaluation: Applications → Feedback.',
                ),
            )),

            'mode' => new ChoiceField(array(
                'label'   => 'When to ask',
                'default' => 'mail',
                'choices' => array(
                    'mail' => 'Own mail when the ticket is closed',
                    'line' => 'Line under the reply that closes the ticket',
                ),
                'hint'    => 'Line: no extra mail; closing without a reply then asks nobody.',
            )),

            'scale' => new ChoiceField(array(
                'label'   => 'Scale',
                'default' => 'thumbs',
                'choices' => array(
                    'thumbs' => 'Thumbs (good / not good)',
                    'stars'  => 'Stars (1–5)',
                    'nps'    => 'NPS (0–10)',
                ),
            )),

            'on_bad' => new ChoiceField(array(
                'label'   => 'On a bad rating',
                'default' => 'reopen',
                'choices' => array(
                    'reopen' => 'Reopen and assign to the last agent who replied',
                    'notify' => 'Only a note and a mail to the responsible agent',
                ),
                'hint'    => 'Reopen uses osTicket\'s assignment alert; rating and comment are in %{comments}.',
            )),

            'bad_stars' => new TextboxField(array(
                'label'         => 'Bad means: stars up to',
                'default'       => '2',
                'configuration' => array('size' => 4, 'length' => 1, 'validator' => 'number'),
            )),

            'bad_nps' => new TextboxField(array(
                'label'         => 'Bad means: NPS up to',
                'default'       => '6',
                'configuration' => array('size' => 4, 'length' => 1, 'validator' => 'number'),
            )),

            'valid_days' => new TextboxField(array(
                'label'         => 'Link valid for (days)',
                'default'       => '14',
                'hint'          => '1 to 90. Within this time the customer can change the rating.',
                'configuration' => array('size' => 4, 'length' => 2, 'validator' => 'number'),
            )),

            'comment_on' => new BooleanField(array(
                'label'         => 'Comment field after the click',
                'default'       => true,
                'configuration' => array(
                    'desc' => 'A bad rating then waits up to 10 minutes for the comment before the ticket is reopened '
                            . '(needs osTicket\'s cron).',
                ),
            )),

            'note_sent' => new ChoiceField(array(
                'label'   => 'Note at the ticket when asked',
                'default' => 'short',
                'choices' => array(
                    'off'   => 'No note',
                    'short' => 'Short note: recipient, scale, valid until',
                    'full'  => 'Short note plus the mail as sent, with its rating links',
                ),
                'hint'    => 'Own mail only. A failed send always gets a note. With the mail, every agent who '
                           . 'opens the ticket sees the customer\'s rating links.',
            )),

            'sec_except' => new SectionBreakField(array(
                'label' => 'Never ask for',
            )),

            'exclude_depts' => new ChoiceField(array(
                'label'         => 'Departments',
                'default'       => self::defaultDepts(),
                'choices'       => self::depts(),
                'hint'          => 'E.g. alerts and logs. Preselected: departments named like "Alert" or "Protokoll".',
                'configuration' => array('multiselect' => true, 'prompt' => 'None'),
            )),

            'exclude_topics' => new ChoiceField(array(
                'label'         => 'Help topics',
                'default'       => '',
                'choices'       => self::topics(),
                'configuration' => array('multiselect' => true, 'prompt' => 'None'),
            )),

            'exclude_statuses' => new ChoiceField(array(
                'label'         => 'Closing statuses',
                'default'       => self::defaultStatuses(),
                'choices'       => self::closedStatuses(),
                'hint'          => 'Closing with one of these never asks, e.g. duplicates or spam. Preselected: closed '
                                 . 'statuses named like "Duplicate", "Spam", "Rejected" or "Hinfällig".',
                'configuration' => array('multiselect' => true, 'prompt' => 'None'),
            )),

            'exclude_domains' => new TextareaField(array(
                'label'         => 'Sender domains',
                'default'       => '',
                'hint'          => 'One per line; subdomains included.',
                'configuration' => array('rows' => 3, 'cols' => 40, 'html' => false, 'length' => 2000),
            )),

            'exclude_own' => new BooleanField(array(
                'label'         => 'Own domains',
                'default'       => true,
                'configuration' => array(
                    'desc' => 'Domains of the help desk\'s own email addresses (Admin → Emails).',
                ),
            )),

            'sec_text' => new SectionBreakField(array(
                'label' => 'Texts (empty: built-in text in the help desk language)',
            )),

            'language' => new ChoiceField(array(
                'label'   => 'Language of the built-in texts',
                'default' => 'auto',
                'choices' => array('auto' => 'Help desk language', 'de' => 'Deutsch (du)', 'en' => 'English'),
            )),

            'subject' => new TextboxField(array(
                'label'         => 'Mail subject',
                'default'       => '',
                'hint'          => 'osTicket variables work, e.g. %{ticket.number}.',
                'configuration' => array('size' => 60, 'length' => 200),
            )),

            'text' => new TextareaField(array(
                'label'         => 'Mail text',
                'default'       => '',
                'hint'          => 'Plain text, a blank line starts a paragraph. Variables like %{recipient.name.first} work.',
                'configuration' => array('rows' => 5, 'cols' => 60, 'html' => false, 'length' => 2000),
            )),

            'question' => new TextboxField(array(
                'label'         => 'Question above the buttons',
                'default'       => '',
                'configuration' => array('size' => 60, 'length' => 300),
            )),

            'line' => new TextboxField(array(
                'label'         => 'Line under the closing reply',
                'default'       => '',
                'configuration' => array('size' => 60, 'length' => 300),
            )),

            'foot_on' => new BooleanField(array(
                'label'         => 'Footer with AI notice',
                'default'       => true,
                'configuration' => array('desc' => 'Grey footer of the mail: ticket number, validity, AI notice.'),
            )),

            'foot' => new TextboxField(array(
                'label'         => 'AI notice',
                'default'       => '',
                'configuration' => array('size' => 60, 'length' => 500),
            )),

            'accent' => new TextboxField(array(
                'label'         => 'Accent colour',
                'default'       => '#330066',
                'hint'          => 'Brand line and buttons, #rrggbb.',
                'configuration' => array('size' => 8, 'length' => 7),
            )),
        );
    }

    /** @return array<int,string> */
    private static function depts(): array
    {
        $out = array();
        if (!function_exists('db_query') || !defined('DEPT_TABLE')) {
            return $out;
        }
        $res = db_query(sprintf('SELECT id, name FROM %s ORDER BY name', DEPT_TABLE));
        while ($res && ($r = db_fetch_row($res))) {
            $out[(int) $r[0]] = (string) $r[1];
        }
        return $out;
    }

    /** @return array<int,string> */
    private static function topics(): array
    {
        $out = array();
        if (!function_exists('db_query') || !defined('TOPIC_TABLE')) {
            return $out;
        }
        $res = db_query(sprintf('SELECT topic_id, topic FROM %s ORDER BY topic', TOPIC_TABLE));
        while ($res && ($r = db_fetch_row($res))) {
            $out[(int) $r[0]] = (string) $r[1];
        }
        return $out;
    }

    /** Statuses whose state is "closed". @return array<int,string> */
    private static function closedStatuses(): array
    {
        $out = array();
        if (!function_exists('db_query') || !defined('TICKET_STATUS_TABLE')) {
            return $out;
        }
        $res = db_query(sprintf("SELECT id, name FROM %s WHERE state='closed' ORDER BY sort, name", TICKET_STATUS_TABLE));
        while ($res && ($r = db_fetch_row($res))) {
            $out[(int) $r[0]] = (string) $r[1];
        }
        return $out;
    }

    /** Closing statuses that mean "nothing was done", by name. @return array<int,string> */
    private static function defaultStatuses(): array
    {
        return array_filter(self::closedStatuses(), static function (string $name): bool {
            return preg_match('/duplicate|dublette|spam|reject|abgelehnt|hinf(ä|ae)llig|obsolete|invalid/iu', $name) === 1;
        });
    }

    /** Departments for alerts and logs, by name. @return array<int,string> */
    private static function defaultDepts(): array
    {
        return array_filter(self::depts(), static function (string $name): bool {
            return preg_match('/alert|protokoll|monitoring/i', $name) === 1;
        });
    }
}
