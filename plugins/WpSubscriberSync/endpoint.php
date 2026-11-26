<?php
/**
 * Public entry point: <phplist>/lists/?pi=WpSubscriberSync&p=endpoint
 *
 * phpList's index.php has already sent its page header into the output
 * buffer: discard it and answer with JSON only.
 *
 * @license AGPL-3.0-or-later
 */

defined('PHPLISTINIT') || exit;

while (ob_get_level() > 0) {
    ob_end_clean();
}

$wpssPlugin = isset($GLOBALS['plugins']['WpSubscriberSync']) ? $GLOBALS['plugins']['WpSubscriberSync'] : null;

$wpssHeaders = array();
foreach (array('x-wpss-key', 'x-wpss-timestamp', 'x-wpss-signature') as $wpssName) {
    $wpssServerKey = 'HTTP_'.strtoupper(str_replace('-', '_', $wpssName));
    if (isset($_SERVER[$wpssServerKey])) {
        $wpssHeaders[$wpssName] = (string) $_SERVER[$wpssServerKey];
    }
}

$wpssSecure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    || (defined('WPSS_TRUST_FORWARDED_PROTO') && WPSS_TRUST_FORWARDED_PROTO
        && isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

if (!$wpssPlugin) {
    $wpssResult = array(503, array('status' => 'error', 'error' => 'server_error', 'message' => 'Plugin not active'), array());
} else {
    $wpssPlugin->ensureSchema();
    $wpssPlugin->loadLibrary();
    try {
        $wpssEndpoint = new WpssEndpoint(new WpssDb(), $wpssPlugin, $GLOBALS['tables'], $GLOBALS['table_prefix']);
        $wpssResult = $wpssEndpoint->handle(
            isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET',
            $wpssHeaders,
            (string) file_get_contents('php://input', false, null, 0, WpssEndpoint::MAX_BODY + 1),
            $wpssSecure
        );
    } catch (Throwable $e) {
        error_log('WpSubscriberSync: '.$e->getMessage());
        $wpssResult = array(500, array('status' => 'error', 'error' => 'server_error', 'message' => 'Unexpected error'), array());
    }
}

// The session phpList opened for this request is useless here.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

http_response_code($wpssResult[0]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header_remove('Access-Control-Allow-Origin');
foreach ($wpssResult[2] as $wpssH => $wpssV) {
    header($wpssH.': '.$wpssV);
}
echo json_encode($wpssResult[1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
