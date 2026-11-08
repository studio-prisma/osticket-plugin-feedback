<?php

/**
 * osTicket plugin manifest: Feedback
 *
 * Tested against osTicket 1.18.4. PluginManager splits the 'plugin' key with
 * explode(':'), see include/class.plugin.php.
 */

return array(
    'id'          => 'studio-prisma:feedback',
    'version'     => '0.1.1',
    'ost_version' => '1.18',
    'name'        => 'Feedback',
    'author'      => 'Studio Prisma',
    'url'         => 'https://github.com/studio-prisma/osticket-plugin-feedback',
    'description' => 'Asks the customer one satisfaction question when a ticket is closed '
                   . '(thumbs, stars or NPS). A bad rating reopens the ticket for the last agent '
                   . 'who replied. Ratings are stored in the help desk\'s own database.',
    'plugin'      => 'class.FeedbackPlugin.php:FeedbackPlugin',
);
