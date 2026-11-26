<?php
/**
 * Request handling for the WP Subscriber Sync endpoint (protocol v1).
 *
 * handle() takes the raw request and returns [http code, response array,
 * extra headers]; it never prints. See docs/PROTOCOL.md.
 *
 * @license AGPL-3.0-or-later
 */

defined('PHPLISTINIT') || exit;

class WpssEndpoint
{
    const MAX_BODY = 65536;
    const CLOCK_SKEW = 300;
    const IDEMPOTENCY_HOURS = 24;
    const MAX_ATTR_LENGTH = 1000;

    /** phpList attribute types we can write from a plain string. */
    const WRITABLE_TYPES = array('textline', 'textarea', 'hidden', 'date', 'select', 'radio', 'checkbox');

    /** @var WpssDb */
    private $db;
    /** @var WpSubscriberSync */
    private $plugin;
    /** @var array phpList $tables */
    private $tables;
    private $tablePrefix;

    /** @var array|null the authenticated key row */
    private $key = null;
    private $site = '';
    private $requestId = '';
    private $action = '';
    private $email = '';

    public function __construct(WpssDb $db, $plugin, array $tables, $tablePrefix)
    {
        $this->db = $db;
        $this->plugin = $plugin;
        $this->tables = $tables;
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * @param string $method  HTTP method
     * @param array  $headers lower-cased header name => value
     * @param string $raw     raw request body
     * @param bool   $secure  request arrived over HTTPS
     *
     * @return array [int code, array body, array headers]
     */
    public function handle($method, array $headers, $raw, $secure)
    {
        try {
            $result = $this->dispatch($method, $headers, $raw, $secure);
        } catch (WpssDbException $e) {
            error_log('WpSubscriberSync: database error: '.$e->getMessage());
            $result = $this->error(500, 'server_error', 'Database error');
        } catch (Throwable $e) {
            error_log('WpSubscriberSync: '.get_class($e).': '.$e->getMessage());
            $result = $this->error(500, 'server_error', 'Unexpected error');
        }
        $this->log($result[0], $result[1]);

        return $result;
    }

    private function dispatch($method, array $headers, $raw, $secure)
    {
        if ($method !== 'POST') {
            return $this->error(405, 'method_not_allowed', 'Use POST', array('Allow' => 'POST'));
        }
        if (!$secure && !(defined('WPSS_ALLOW_HTTP') && WPSS_ALLOW_HTTP)) {
            return $this->error(403, 'https_required', 'HTTPS is required');
        }
        if (strlen($raw) > self::MAX_BODY) {
            return $this->error(400, 'invalid_request', 'Body too large');
        }

        // ---- authentication
        $keyId = isset($headers['x-wpss-key']) ? trim($headers['x-wpss-key']) : '';
        $ts = isset($headers['x-wpss-timestamp']) ? trim($headers['x-wpss-timestamp']) : '';
        $sig = isset($headers['x-wpss-signature']) ? strtolower(trim($headers['x-wpss-signature'])) : '';
        if (!preg_match('/^[a-z0-9_]{1,32}$/', $keyId) || !ctype_digit($ts) || !preg_match('/^[0-9a-f]{64}$/', $sig)) {
            return $this->error(401, 'unauthorized', 'Missing or malformed authentication headers');
        }
        $key = $this->db->one('select * from '.$this->plugin->keyTable().' where key_id = ?', array($keyId));
        if (!$key || !(int) $key['active']) {
            return $this->error(401, 'unauthorized', 'Unknown or inactive key');
        }
        if (abs(time() - (int) $ts) > self::CLOCK_SKEW) {
            return $this->error(401, 'unauthorized', 'Timestamp outside the allowed window; check the clock');
        }
        $expected = hash_hmac('sha256', $ts."\n".$raw, $key['secret']);
        if (!hash_equals($expected, $sig)) {
            return $this->error(401, 'unauthorized', 'Bad signature');
        }
        $this->key = $key;

        // ---- envelope
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return $this->error(400, 'invalid_request', 'Body is not a JSON object');
        }
        $this->site = isset($body['site']) && is_string($body['site']) ? substr($body['site'], 0, 255) : '';
        $this->action = isset($body['action']) && is_string($body['action']) ? substr($body['action'], 0, 16) : '';
        $rid = isset($body['request_id']) && is_string($body['request_id']) ? $body['request_id'] : '';
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $rid)) {
            return $this->error(400, 'invalid_request', 'request_id is missing or malformed');
        }
        $this->requestId = $rid;
        if (!isset($body['protocol']) || (int) $body['protocol'] !== WpSubscriberSync::PROTOCOL) {
            return $this->error(400, 'invalid_request', 'Unsupported protocol version');
        }

        // ---- rate limit: authenticated requests of this key in the last minute
        $recent = $this->db->one(
            'select count(*) as n from '.$this->plugin->logTable().' where key_id = ? and created > now() - interval 60 second',
            array($keyId)
        );
        if ($recent && (int) $recent['n'] >= max(1, (int) $key['rate_limit'])) {
            return $this->error(429, 'rate_limited', 'Too many requests', array('Retry-After' => '60'));
        }

        // ---- idempotency
        $hash = hash('sha256', $raw);
        $this->db->exec(
            'delete from '.$this->plugin->requestTable().' where created < now() - interval '.(int) self::IDEMPOTENCY_HOURS.' hour'
        );
        $seen = $this->db->one(
            'select body_hash, http_code, response from '.$this->plugin->requestTable().' where key_id = ? and request_id = ?',
            array($keyId, $rid)
        );
        if ($seen) {
            if (!hash_equals($seen['body_hash'], $hash)) {
                return $this->error(409, 'request_id_reused', 'request_id already used with a different body');
            }
            $stored = json_decode($seen['response'], true);
            $stored = is_array($stored) ? $stored : array('status' => 'error', 'error' => 'server_error');
            $stored['replayed'] = true;

            return array((int) $seen['http_code'], $stored, array());
        }

        switch ($this->action) {
            case 'ping':
                $result = $this->ping();
                break;
            case 'subscribe':
                $result = $this->subscribe($body);
                break;
            default:
                return $this->error(400, 'invalid_request', 'Unknown action');
        }

        // Only definitive outcomes are stored: a retry after a 5xx must run again.
        if ($result[0] < 500) {
            $this->db->exec(
                'insert ignore into '.$this->plugin->requestTable().' (key_id, request_id, body_hash, http_code, response, created) values (?, ?, ?, ?, ?, now())',
                array($keyId, $rid, $hash, (int) $result[0], json_encode($result[1]))
            );
        }
        $this->db->exec('update '.$this->plugin->keyTable().' set last_used = now() where id = ?', array((int) $key['id']));

        return $result;
    }

    // ------------------------------------------------------------ actions

    private function ping()
    {
        $defaults = WpSubscriberSync::idList($this->key['default_lists']);
        $lists = array();
        foreach ($this->listsByIds(WpSubscriberSync::idList($this->key['allowed_lists'])) as $l) {
            $lists[] = array('id' => (int) $l['id'], 'name' => $l['name'], 'default' => in_array((int) $l['id'], $defaults, true));
        }
        $attrs = array();
        foreach ($this->allowedAttributes() as $a) {
            $attrs[] = array('name' => $a['name'], 'type' => $a['type']);
        }

        return array(200, array(
            'status' => 'ok',
            'protocol' => WpSubscriberSync::PROTOCOL,
            'server' => 'phpList '.(defined('VERSION') ? VERSION : '?').' / WpSubscriberSync '.WpSubscriberSync::PLUGIN_VERSION,
            'confirm_policy' => $this->key['confirm_policy'] === 'confirmed' ? 'confirmed' : 'unconfirmed',
            'lists' => $lists,
            'attributes' => $attrs,
        ), array());
    }

    private function subscribe(array $body)
    {
        // ---- e-mail
        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/["\\\\\s<>]/', $email)) {
            return $this->error(400, 'invalid_request', 'Invalid e-mail address');
        }
        $email = strtolower($email);
        $this->email = $email;

        // ---- lists
        $allowed = WpSubscriberSync::idList($this->key['allowed_lists']);
        $requested = array();
        if (isset($body['lists'])) {
            if (!is_array($body['lists'])) {
                return $this->error(400, 'invalid_request', 'lists must be an array of list IDs');
            }
            foreach ($body['lists'] as $id) {
                if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                    return $this->error(400, 'invalid_request', 'lists must be an array of list IDs');
                }
                $requested[(int) $id] = (int) $id;
            }
            $requested = array_values($requested);
        }
        if (!$requested) {
            $requested = WpSubscriberSync::idList($this->key['default_lists']);
        }
        if (!$requested) {
            return $this->error(400, 'invalid_request', 'No list requested and the key has no default list');
        }
        foreach ($requested as $id) {
            if (!in_array($id, $allowed, true)) {
                return $this->error(403, 'list_not_allowed', 'List '.$id.' is not allowed for this key');
            }
        }
        $existing = array();
        foreach ($this->listsByIds($requested) as $l) {
            $existing[] = (int) $l['id'];
        }
        if (count($existing) !== count($requested)) {
            return $this->error(403, 'list_not_allowed', 'List '.implode(',', array_diff($requested, $existing)).' does not exist');
        }

        // ---- attributes, validated before any write
        $attrValues = array();
        $ignored = array();
        if (isset($body['attributes'])) {
            if (!is_array($body['attributes'])) {
                return $this->error(400, 'invalid_request', 'attributes must be an object');
            }
            $byName = array();
            foreach ($this->allowedAttributes() as $a) {
                $byName[strtolower($a['name'])] = $a;
            }
            foreach ($body['attributes'] as $name => $value) {
                $name = (string) $name;
                $lname = strtolower($name);
                if (!isset($byName[$lname]) || !(is_string($value) || is_int($value) || is_float($value) || is_bool($value))) {
                    $ignored[] = $name;
                    continue;
                }
                $value = is_bool($value) ? ($value ? 'on' : '') : trim((string) $value);
                if ($value === '') {
                    continue; // empty values never wipe existing data
                }
                $stored = $this->attributeStorageValue($byName[$lname], $value);
                if ($stored === null) {
                    $ignored[] = $name;
                    continue;
                }
                $attrValues[(int) $byName[$lname]['id']] = $stored;
            }
        }

        // ---- blacklist: an explicit unsubscribe always wins
        $user = $this->db->one('select id, confirmed, blacklisted from '.$this->tables['user'].' where email = ?', array($email));
        if (($user && (int) $user['blacklisted']) || $this->isBlacklisted($email)) {
            return array(200, array('status' => 'blacklisted', 'subscriber_id' => $user ? (int) $user['id'] : null), array());
        }

        $confirm = $this->key['confirm_policy'] === 'confirmed';
        $created = false;
        if (!$user) {
            $this->db->exec(
                'insert into '.$this->tables['user'].' (email, confirmed, blacklisted, optedin, entered, uniqid, uuid, htmlemail, subscribepage, password, passwordchanged, disabled)
                 values (?, ?, 0, 0, now(), ?, ?, 1, 0, ?, now(), 0)',
                array($email, $confirm ? 1 : 0, $this->newUniqid(), $this->newUuid(), '')
            );
            $userId = $this->db->insertId();
            $created = true;
            $confirmed = $confirm;
        } else {
            $userId = (int) $user['id'];
            $confirmed = (bool) (int) $user['confirmed'];
            if ($confirm && !$confirmed) {
                // never downgrade: only unconfirmed → confirmed
                $this->db->exec('update '.$this->tables['user'].' set confirmed = 1 where id = ?', array($userId));
                $confirmed = true;
            }
        }

        foreach ($attrValues as $attId => $value) {
            $this->db->exec(
                'replace into '.$this->tables['user_attribute'].' (attributeid, userid, value) values (?, ?, ?)',
                array((int) $attId, $userId, $value)
            );
        }

        $added = array();
        foreach ($requested as $listId) {
            if ($this->db->exec('insert ignore into '.$this->tables['listuser'].' (userid, listid, entered) values (?, ?, now())', array($userId, $listId)) > 0) {
                $added[] = $listId;
            }
        }

        $this->history($email, $created, $added);

        $response = array(
            'status' => 'subscribed',
            'subscriber_id' => $userId,
            'created' => $created,
            'confirmed' => $confirmed,
            'lists' => $requested,
            'lists_added' => $added,
        );
        if ($ignored) {
            $response['ignored_attributes'] = array_values(array_unique($ignored));
        }

        return array(200, $response, array());
    }

    // ------------------------------------------------------------ phpList data

    private function listsByIds(array $ids)
    {
        if (!$ids) {
            return array();
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->all('select id, name from '.$this->tables['list'].' where id in ('.$marks.') order by name', array_map('intval', $ids));
    }

    private function allowedAttributes()
    {
        $ids = WpSubscriberSync::idList($this->key['allowed_attrs']);
        if (!$ids) {
            return array();
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->all('select id, name, type, tablename from '.$this->tables['attribute'].' where id in ('.$marks.') order by listorder, name', $ids);

        return array_values(array_filter($rows, function ($a) {
            return in_array($a['type'], WpssEndpoint::WRITABLE_TYPES, true);
        }));
    }

    /** Value as phpList stores it, or null when it cannot be stored. */
    private function attributeStorageValue(array $att, $value)
    {
        $value = function_exists('mb_substr') ? mb_substr($value, 0, self::MAX_ATTR_LENGTH, 'UTF-8') : substr($value, 0, self::MAX_ATTR_LENGTH);
        switch ($att['type']) {
            case 'textline':
            case 'textarea':
            case 'hidden':
                return $value;
            case 'date':
                $d = DateTime::createFromFormat('!Y-m-d', $value);

                return ($d && $d->format('Y-m-d') === $value) ? $value : null;
            case 'checkbox':
                return in_array(strtolower($value), array('1', 'on', 'yes', 'true', 'y', 'si', 'sì'), true) ? 'on' : null;
            case 'select':
            case 'radio':
                // The value is the option label; phpList stores the option id. Never create new options.
                if (!preg_match('/^[a-z0-9_]+$/i', (string) $att['tablename'])) {
                    return null;
                }
                $row = $this->db->one('select id from '.$this->tablePrefix.'listattr_'.$att['tablename'].' where name = ?', array($value));

                return $row ? (string) (int) $row['id'] : null;
        }

        return null;
    }

    private function isBlacklisted($email)
    {
        return function_exists('isBlackListed') ? (bool) isBlackListed($email) : false;
    }

    private function newUniqid()
    {
        return function_exists('getUniqid') ? getUniqid() : md5(uniqid((string) mt_rand(), true));
    }

    private function newUuid()
    {
        if (class_exists('uuid')) {
            return (string) uuid::generate(4);
        }
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function history($email, $created, array $added)
    {
        if (!function_exists('addUserHistory')) {
            return;
        }
        $detail = sprintf(
            "%s via WP Subscriber Sync\nKey: %s (%s)\nSite: %s\nRequest: %s\nLists added: %s",
            $created ? 'Subscriber created' : 'Subscriber updated',
            $this->key['key_id'],
            $this->key['label'],
            $this->site,
            $this->requestId,
            $added ? implode(', ', $added) : 'none (already a member)'
        );
        addUserHistory($email, 'WP Subscriber Sync', $detail);
    }

    // ------------------------------------------------------------ output

    private function error($code, $error, $message, array $headers = array())
    {
        return array($code, array('status' => 'error', 'error' => $error, 'message' => $message), $headers);
    }

    /** One row per request that passed authentication; never the body or the signature. */
    private function log($code, array $body)
    {
        if (!$this->key) {
            return;
        }
        $outcome = isset($body['error']) ? $body['error'] : (isset($body['status']) ? $body['status'] : '');
        if (!empty($body['replayed'])) {
            $outcome = 'replayed';
        }
        try {
            $this->db->exec(
                'insert into '.$this->plugin->logTable().' (key_id, site, request_id, action, email, outcome, http_code, created) values (?, ?, ?, ?, ?, ?, ?, now())',
                array($this->key['key_id'], $this->site, $this->requestId, $this->action, $this->email, substr($outcome, 0, 32), (int) $code)
            );
            if (mt_rand(1, 100) === 1) {
                $days = function_exists('getConfig') ? (int) getConfig('wpss_log_retention_days') : 0;
                $days = $days > 0 ? $days : 90;
                $this->db->exec('delete from '.$this->plugin->logTable().' where created < now() - interval '.$days.' day');
            }
        } catch (Throwable $e) {
            error_log('WpSubscriberSync: cannot write log: '.$e->getMessage());
        }
    }
}
