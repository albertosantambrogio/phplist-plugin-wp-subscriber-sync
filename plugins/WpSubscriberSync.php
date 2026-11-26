<?php
/**
 * WP Subscriber Sync — phpList 3 plugin.
 *
 * Exposes a signed endpoint that WordPress sites (plugin "Subscriber Sync for
 * phpList") use to subscribe their new users to phpList lists.
 * Protocol: docs/PROTOCOL.md
 *
 * @license AGPL-3.0-or-later
 */

defined('PHPLISTINIT') || exit;

class WpSubscriberSync extends phplistPlugin
{
    public $name = 'WP Subscriber Sync';
    public $version = '0.1.0-dev';
    public $authors = 'Alberto Santambrogio';
    public $description = 'Signed endpoint to subscribe WordPress users to phpList lists.';
    public $enabled = 1;

    /** Pages reachable without admin login: ?pi=WpSubscriberSync&p=endpoint */
    public $publicPages = array('endpoint');

    /** Admin pages (key management, log). */
    public $topMenuLinks = array(
        'keys' => array('category' => 'subscribers'),
    );
    public $pageTitles = array(
        'keys' => 'WordPress sync keys',
    );

    public function __construct()
    {
        $this->coderoot = dirname(__FILE__) . '/WpSubscriberSync/';
        parent::__construct();
    }

    // Implementation pending: see docs/PROTOCOL.md.
}
