<?php
/**
 * Command-line client for testing the endpoint (protocol v1).
 *
 *   php tools/wpss-client.php ping
 *   php tools/wpss-client.php subscribe someone@example.org [--list=12] [--attr="First Name=Mario"]...
 *   php tools/wpss-client.php raw '{"protocol":1,...}'   sign and send a hand-made body
 *
 * Connection settings come from the environment, so the secret never ends up
 * in the shell history:
 *   WPSS_URL     endpoint URL
 *   WPSS_KEY     key ID
 *   WPSS_SECRET  secret
 * Optional: WPSS_REQUEST_ID (reuse to test idempotency), WPSS_SITE, WPSS_TS_OFFSET (seconds).
 *
 * @license AGPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$url = getenv('WPSS_URL');
$key = getenv('WPSS_KEY');
$secret = getenv('WPSS_SECRET');
if (!$url || !$key || !$secret) {
    fwrite(STDERR, "Set WPSS_URL, WPSS_KEY and WPSS_SECRET.\n");
    exit(2);
}

$args = array_slice($argv, 1);
$action = array_shift($args);
$uuid = function () {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
};
$body = array(
    'protocol' => 1,
    'action' => $action,
    'request_id' => getenv('WPSS_REQUEST_ID') ?: $uuid(),
    'site' => getenv('WPSS_SITE') ?: 'cli-test',
);

if ($action === 'subscribe') {
    $body['email'] = (string) array_shift($args);
    foreach ($args as $a) {
        if (strpos($a, '--list=') === 0) {
            $body['lists'][] = (int) substr($a, 7);
        } elseif (strpos($a, '--attr=') === 0) {
            list($n, $v) = array_pad(explode('=', substr($a, 7), 2), 2, '');
            $body['attributes'][$n] = $v;
        }
    }
    $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} elseif ($action === 'ping') {
    $raw = json_encode($body, JSON_UNESCAPED_SLASHES);
} elseif ($action === 'raw') {
    $raw = (string) array_shift($args);
} else {
    fwrite(STDERR, "Usage: ping | subscribe <email> [--list=N] [--attr=Name=Value] | raw <json>\n");
    exit(2);
}

$ts = (string) (time() + (int) getenv('WPSS_TS_OFFSET'));
$sig = hash_hmac('sha256', $ts."\n".$raw, $secret);

$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $raw,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        'X-WPSS-Key: '.$key,
        'X-WPSS-Timestamp: '.$ts,
        'X-WPSS-Signature: '.$sig,
    ),
));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($resp === false) {
    fwrite(STDERR, 'curl: '.curl_error($ch)."\n");
    exit(3);
}
echo $code."\n".$resp."\n";
exit($code >= 200 && $code < 300 ? 0 : 1);
