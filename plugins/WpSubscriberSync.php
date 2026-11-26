<?php
/**
 * WP Subscriber Sync — phpList 3 plugin.
 *
 * Exposes a signed endpoint that WordPress sites (plugin "Subscriber Sync for
 * phpList") use to subscribe their new users to phpList lists.
 *
 * Endpoint:  <phplist>/lists/?pi=WpSubscriberSync&p=endpoint   (POST, JSON)
 * Admin:     Subscribers → WordPress sync keys
 * Protocol:  docs/PROTOCOL.md
 *
 * Compatible with phpList 3.6 – 3.7, PHP 7.4 – 8.4 (mysqli + mysqlnd).
 *
 * @license AGPL-3.0-or-later
 */

defined('PHPLISTINIT') || exit;

class WpSubscriberSync extends phplistPlugin
{
    const PLUGIN_VERSION = '0.1.0';
    const PROTOCOL = 1;
    /** Bump when tables change: missing tables are then created on the next request. */
    const SCHEMA_VERSION = 1;

    public $name = 'WP Subscriber Sync';
    public $version = self::PLUGIN_VERSION;
    public $authors = 'Alberto Santambrogio';
    public $description = 'Signed endpoint to subscribe WordPress users to phpList lists.';
    public $documentationUrl = 'https://github.com/albertosantambrogio/phplist-plugin-wp-subscriber-sync';
    public $enabled = 1;
    public $coderoot = '';
    public $settings = array();
    public $tables = array();

    /** Pages reachable without admin login: ?pi=WpSubscriberSync&p=endpoint */
    public $publicPages = array('endpoint');

    public $topMenuLinks = array(
        'keys' => array('category' => 'subscribers'),
    );
    public $pageTitles = array(
        'keys' => 'WordPress sync keys',
    );

    public function __construct()
    {
        $this->coderoot = dirname(__FILE__).'/WpSubscriberSync/';
        $this->settings = array(
            'wpss_log_retention_days' => array(
                'description' => 'WP Subscriber Sync: days to keep the request log',
                'type' => 'integer',
                'value' => 90,
                'allowempty' => false,
                'min' => 1,
                'max' => 3650,
                'category' => 'WP Subscriber Sync',
            ),
        );
        parent::__construct();
    }

    public function adminmenu()
    {
        return array('keys' => 'WordPress sync keys');
    }

    // ------------------------------------------------------------ tables

    public function keyTable()
    {
        return $GLOBALS['table_prefix'].__CLASS__.'_key';
    }

    public function requestTable()
    {
        return $GLOBALS['table_prefix'].__CLASS__.'_request';
    }

    public function logTable()
    {
        return $GLOBALS['table_prefix'].__CLASS__.'_log';
    }

    public function initialise()
    {
        $this->createTables();
        parent::initialise();
    }

    /** InnoDB tables (Sql_Create_Table in core does not set the engine). */
    public function createTables()
    {
        Sql_Query('CREATE TABLE IF NOT EXISTS '.$this->keyTable().' (
            id integer unsigned not null auto_increment,
            key_id varchar(32) character set ascii collate ascii_bin not null,
            secret varchar(128) character set ascii collate ascii_bin not null,
            label varchar(255) not null default "",
            allowed_lists varchar(255) character set ascii not null default "",
            default_lists varchar(255) character set ascii not null default "",
            confirm_policy varchar(16) character set ascii not null default "unconfirmed",
            allowed_attrs varchar(255) character set ascii not null default "",
            rate_limit integer unsigned not null default 60,
            active tinyint not null default 1,
            created datetime not null,
            last_used datetime null,
            primary key (id),
            unique key keyidx (key_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8', 1);
        Sql_Query('CREATE TABLE IF NOT EXISTS '.$this->requestTable().' (
            key_id varchar(32) character set ascii collate ascii_bin not null,
            request_id varchar(64) character set ascii collate ascii_bin not null,
            body_hash char(64) character set ascii not null,
            http_code smallint unsigned not null,
            response text not null,
            created datetime not null,
            primary key (key_id, request_id),
            key createdidx (created)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8', 1);
        Sql_Query('CREATE TABLE IF NOT EXISTS '.$this->logTable().' (
            id bigint unsigned not null auto_increment,
            key_id varchar(32) character set ascii collate ascii_bin not null default "",
            site varchar(255) not null default "",
            request_id varchar(64) character set ascii not null default "",
            action varchar(16) character set ascii not null default "",
            email varchar(255) not null default "",
            outcome varchar(32) character set ascii not null default "",
            http_code smallint unsigned not null default 0,
            created datetime not null,
            primary key (id),
            key keycreatedidx (key_id, created),
            key createdidx (created)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8', 1);
        SaveConfig('wpss_schema', self::SCHEMA_VERSION, 0);
    }

    /** For installations upgraded from an older schema: create missing tables once. */
    public function ensureSchema()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        if ((int) getConfig('wpss_schema') < self::SCHEMA_VERSION) {
            $this->createTables();
        }
    }

    // ------------------------------------------------------------ helpers shared by pages

    public function loadLibrary()
    {
        require_once $this->coderoot.'WpssDb.php';
        require_once $this->coderoot.'WpssEndpoint.php';
    }

    /** Parse "1, 2,3" → [1,2,3] (positive ints, unique, sorted). */
    public static function idList($csv)
    {
        $out = array();
        foreach (preg_split('/[\s,]+/', (string) $csv) as $part) {
            if (ctype_digit($part) && (int) $part > 0) {
                $out[(int) $part] = (int) $part;
            }
        }
        ksort($out);

        return array_values($out);
    }

    public static function newKeyId()
    {
        return 'wpss_'.bin2hex(random_bytes(6));
    }

    public static function newSecret()
    {
        return bin2hex(random_bytes(32));
    }
}
