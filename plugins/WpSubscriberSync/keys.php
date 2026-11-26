<?php
/**
 * Admin page: admin/?page=keys&pi=WpSubscriberSync
 * Create and manage the keys used by WordPress sites; show the request log.
 * Super users only.
 *
 * @license AGPL-3.0-or-later
 */

defined('PHPLISTINIT') || exit;

$wpss = isset($GLOBALS['plugins']['WpSubscriberSync']) ? $GLOBALS['plugins']['WpSubscriberSync'] : null;
if (!$wpss) {
    echo '<p>The WP Subscriber Sync plugin is not active.</p>';

    return;
}
if (!isSuperUser()) {
    echo '<p>Only super users can manage WordPress sync keys.</p>';

    return;
}

$wpss->ensureSchema();
$wpss->loadLibrary();
$wpssDb = new WpssDb();
$wpssKeys = $wpss->keyTable();
$wpssSelf = './?page=keys&pi=WpSubscriberSync';
$wpssMsg = '';
$wpssShowSecret = null;

$h = function ($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
};

$wpssLists = $wpssDb->all('select id, name, active from '.$GLOBALS['tables']['list'].' order by name');
$wpssAttrs = $wpssDb->all('select id, name, type from '.$GLOBALS['tables']['attribute'].' order by listorder, name');

// ---------------------------------------------------------------- actions (POST + form token)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wpss_action'])) {
    if (!verifyToken()) {
        echo '<p class="error">Invalid or expired form token, please try again.</p>';
    } else {
        $act = (string) $_POST['wpss_action'];
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        $validLists = array_map(function ($l) {
            return (int) $l['id'];
        }, $wpssLists);
        $validAttrs = array_map(function ($a) {
            return (int) $a['id'];
        }, array_filter($wpssAttrs, function ($a) {
            return in_array($a['type'], WpssEndpoint::WRITABLE_TYPES, true);
        }));
        $pick = function ($field, array $valid) {
            $out = array();
            if (isset($_POST[$field]) && is_array($_POST[$field])) {
                foreach ($_POST[$field] as $v) {
                    if (in_array((int) $v, $valid, true)) {
                        $out[(int) $v] = (int) $v;
                    }
                }
            }
            ksort($out);

            return implode(',', $out);
        };

        if ($act === 'save') {
            $label = isset($_POST['label']) ? trim(substr((string) $_POST['label'], 0, 255)) : '';
            $allowed = $pick('allowed_lists', $validLists);
            // default lists must be a subset of allowed lists
            $default = $pick('default_lists', WpSubscriberSync::idList($allowed));
            $policy = (isset($_POST['confirm_policy']) && $_POST['confirm_policy'] === 'confirmed') ? 'confirmed' : 'unconfirmed';
            $attrs = $pick('allowed_attrs', $validAttrs);
            $rate = isset($_POST['rate_limit']) ? max(1, min(10000, (int) $_POST['rate_limit'])) : 60;

            if ($id > 0) {
                $wpssDb->exec(
                    'update '.$wpssKeys.' set label = ?, allowed_lists = ?, default_lists = ?, confirm_policy = ?, allowed_attrs = ?, rate_limit = ? where id = ?',
                    array($label, $allowed, $default, $policy, $attrs, $rate, $id)
                );
                $wpssMsg = 'Key updated.';
            } else {
                $keyId = WpSubscriberSync::newKeyId();
                $secret = WpSubscriberSync::newSecret();
                $wpssDb->exec(
                    'insert into '.$wpssKeys.' (key_id, secret, label, allowed_lists, default_lists, confirm_policy, allowed_attrs, rate_limit, active, created) values (?, ?, ?, ?, ?, ?, ?, ?, 1, now())',
                    array($keyId, $secret, $label, $allowed, $default, $policy, $attrs, $rate)
                );
                $wpssShowSecret = array('key_id' => $keyId, 'secret' => $secret);
                $wpssMsg = 'Key created.';
            }
        } elseif ($act === 'toggle' && $id > 0) {
            $wpssDb->exec('update '.$wpssKeys.' set active = 1 - active where id = ?', array($id));
            $wpssMsg = 'Key status changed.';
        } elseif ($act === 'regenerate' && $id > 0) {
            $secret = WpSubscriberSync::newSecret();
            $wpssDb->exec('update '.$wpssKeys.' set secret = ? where id = ?', array($secret, $id));
            $row = $wpssDb->one('select key_id from '.$wpssKeys.' where id = ?', array($id));
            if ($row) {
                $wpssShowSecret = array('key_id' => $row['key_id'], 'secret' => $secret);
            }
            $wpssMsg = 'New secret generated: the old one no longer works.';
        } elseif ($act === 'delete' && $id > 0) {
            $wpssDb->exec('delete from '.$wpssKeys.' where id = ?', array($id));
            $wpssMsg = 'Key deleted.';
        }
    }
}

// ---------------------------------------------------------------- view
$wpssEndpointUrl = (isset($GLOBALS['public_scheme']) ? $GLOBALS['public_scheme'] : 'https').'://'
    .getConfig('website').(isset($GLOBALS['pageroot']) ? $GLOBALS['pageroot'] : '/lists').'/?pi=WpSubscriberSync&p=endpoint';

if ($wpssMsg !== '') {
    echo '<p class="information">'.$h($wpssMsg).'</p>';
}

if ($wpssShowSecret) {
    echo '<div class="note" style="border:2px solid #c60;padding:1em;margin:1em 0">'
        .'<p><strong>Copy these values into the WordPress plugin settings now. The secret is not shown again.</strong></p>'
        .'<p>Endpoint URL: <code>'.$h($wpssEndpointUrl).'</code><br>'
        .'Key ID: <code>'.$h($wpssShowSecret['key_id']).'</code><br>'
        .'Secret: <code style="user-select:all">'.$h($wpssShowSecret['secret']).'</code></p></div>';
}

echo '<p>Endpoint URL for the WordPress sites: <code>'.$h($wpssEndpointUrl).'</code></p>';

$wpssRows = $wpssDb->all(
    'select k.*, (select count(*) from '.$wpss->logTable().' l where l.key_id = k.key_id and l.created > now() - interval 1 day) as req_24h
     from '.$wpssKeys.' k order by k.label, k.id'
);

$listName = array();
foreach ($wpssLists as $l) {
    $listName[(int) $l['id']] = $l['name'];
}
$attrName = array();
foreach ($wpssAttrs as $a) {
    $attrName[(int) $a['id']] = $a['name'];
}
$names = function ($csv, array $map) use ($h) {
    $out = array();
    foreach (WpSubscriberSync::idList($csv) as $id) {
        $out[] = $h(isset($map[$id]) ? $map[$id] : '#'.$id.' (missing)');
    }

    return $out ? implode(', ', $out) : '<em>none</em>';
};

echo '<h3>Keys</h3>';
if (!$wpssRows) {
    echo '<p>No keys yet.</p>';
} else {
    echo '<table class="table"><tr><th>Site</th><th>Key ID</th><th>Allowed lists</th><th>Default</th><th>Confirm</th><th>Attributes</th><th>Rate/min</th><th>Requests 24h</th><th>Last used</th><th>Status</th><th></th></tr>';
    foreach ($wpssRows as $r) {
        $form = function ($action, $label, $confirm = '') use ($r, $h) {
            $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(\''.$h($confirm).'\')"' : '';

            return formStart('style="display:inline"'.$onsubmit)
                .'<input type="hidden" name="wpss_action" value="'.$h($action).'">'
                .'<input type="hidden" name="id" value="'.(int) $r['id'].'">'
                .'<input type="submit" class="button" value="'.$h($label).'"></form>';
        };
        echo '<tr>'
            .'<td>'.$h($r['label']).'</td>'
            .'<td><code>'.$h($r['key_id']).'</code></td>'
            .'<td>'.$names($r['allowed_lists'], $listName).'</td>'
            .'<td>'.$names($r['default_lists'], $listName).'</td>'
            .'<td>'.$h($r['confirm_policy']).'</td>'
            .'<td>'.$names($r['allowed_attrs'], $attrName).'</td>'
            .'<td>'.(int) $r['rate_limit'].'</td>'
            .'<td>'.(int) $r['req_24h'].'</td>'
            .'<td>'.$h($r['last_used'] ?: '—').'</td>'
            .'<td>'.((int) $r['active'] ? 'active' : '<strong>disabled</strong>').'</td>'
            .'<td style="white-space:nowrap"><a class="button" href="'.$h($wpssSelf.'&edit='.(int) $r['id']).'">Edit</a> '
            .$form('toggle', (int) $r['active'] ? 'Disable' : 'Enable').' '
            .$form('regenerate', 'New secret', 'The site using this key will stop working until you paste the new secret. Continue?').' '
            .$form('delete', 'Delete', 'Delete this key? The site using it will stop subscribing users.')
            .'</td></tr>';
    }
    echo '</table>';
}

// ---------------------------------------------------------------- create / edit form
$edit = null;
if (isset($_GET['edit'])) {
    $edit = $wpssDb->one('select * from '.$wpssKeys.' where id = ?', array((int) $_GET['edit']));
}
$cur = $edit ?: array('id' => 0, 'label' => '', 'allowed_lists' => '', 'default_lists' => '', 'confirm_policy' => 'unconfirmed', 'allowed_attrs' => '', 'rate_limit' => 60);
$curAllowed = WpSubscriberSync::idList($cur['allowed_lists']);
$curDefault = WpSubscriberSync::idList($cur['default_lists']);
$curAttrs = WpSubscriberSync::idList($cur['allowed_attrs']);

echo '<h3>'.($edit ? 'Edit key '.$h($edit['key_id']) : 'New key').'</h3>';
echo formStart();
echo '<input type="hidden" name="wpss_action" value="save"><input type="hidden" name="id" value="'.(int) $cur['id'].'">';
echo '<p><label>Site (label)<br><input type="text" name="label" size="60" value="'.$h($cur['label']).'" placeholder="https://www.example.org"></label></p>';

echo '<table class="table"><tr><th>List</th><th>Allowed</th><th>Default</th></tr>';
foreach ($wpssLists as $l) {
    $lid = (int) $l['id'];
    echo '<tr><td>'.$h($l['name']).' <small>#'.$lid.($l['active'] ? ' public' : ' private').'</small></td>'
        .'<td><input type="checkbox" name="allowed_lists[]" value="'.$lid.'"'.(in_array($lid, $curAllowed, true) ? ' checked' : '').'></td>'
        .'<td><input type="checkbox" name="default_lists[]" value="'.$lid.'"'.(in_array($lid, $curDefault, true) ? ' checked' : '').'></td></tr>';
}
echo '</table><p><small>Default lists are used when the site does not name a list; they must also be allowed.</small></p>';

echo '<p>New subscribers are<br>'
    .'<label><input type="radio" name="confirm_policy" value="confirmed"'.($cur['confirm_policy'] === 'confirmed' ? ' checked' : '').'> confirmed immediately (consent collected by the site)</label><br>'
    .'<label><input type="radio" name="confirm_policy" value="unconfirmed"'.($cur['confirm_policy'] !== 'confirmed' ? ' checked' : '').'> left unconfirmed</label></p>';

echo '<p>Attributes the site may set:<br>';
$any = false;
foreach ($wpssAttrs as $a) {
    if (!in_array($a['type'], WpssEndpoint::WRITABLE_TYPES, true)) {
        continue;
    }
    $any = true;
    $aid = (int) $a['id'];
    echo '<label><input type="checkbox" name="allowed_attrs[]" value="'.$aid.'"'.(in_array($aid, $curAttrs, true) ? ' checked' : '').'> '
        .$h($a['name']).' <small>('.$h($a['type']).')</small></label><br>';
}
if (!$any) {
    echo '<em>No attribute of a supported type (text, textarea, hidden, date, select, radio, checkbox).</em>';
}
echo '</p>';
echo '<p><label>Max requests per minute <input type="number" name="rate_limit" min="1" max="10000" value="'.(int) $cur['rate_limit'].'"></label></p>';
echo '<p><input type="submit" class="button" value="'.($edit ? 'Save changes' : 'Create key').'">'
    .($edit ? ' <a href="'.$h($wpssSelf).'">Cancel</a>' : '').'</p></form>';

// ---------------------------------------------------------------- log
$log = $wpssDb->all('select * from '.$wpss->logTable().' order by id desc limit 100');
echo '<h3>Last 100 requests</h3>';
if (!$log) {
    echo '<p>No requests yet.</p>';
} else {
    echo '<table class="table"><tr><th>Date</th><th>Key</th><th>Site</th><th>Action</th><th>E-mail</th><th>Outcome</th><th>HTTP</th></tr>';
    foreach ($log as $r) {
        echo '<tr><td>'.$h($r['created']).'</td><td><code>'.$h($r['key_id']).'</code></td><td>'.$h($r['site']).'</td><td>'.$h($r['action']).'</td>'
            .'<td>'.$h($r['email']).'</td><td>'.$h($r['outcome']).'</td><td>'.(int) $r['http_code'].'</td></tr>';
    }
    echo '</table>';
}
