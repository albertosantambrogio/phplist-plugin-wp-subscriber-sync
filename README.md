# WP Subscriber Sync — phpList plugin

phpList 3 plugin that exposes a signed HTTP endpoint. WordPress sites running
[Subscriber Sync for phpList](https://github.com/albertosantambrogio/subscriber-sync-for-phplist)
use it to subscribe their new users to phpList lists, including private lists.

> **Status:** early development. Not ready for production.

- Wire protocol (the contract with the WordPress plugin): [`docs/PROTOCOL.md`](docs/PROTOCOL.md)

## Why a dedicated endpoint

- No phpList admin credentials on WordPress sites: each site has its own key
  and HMAC secret.
- Each key is restricted to specific lists and attributes, with a server-side
  confirm policy. A compromised site cannot write elsewhere or force-confirm.
- Blacklisted (unsubscribed) addresses are never re-subscribed.
- Idempotent requests: client retries never create duplicates.

## Requirements

phpList 3.6+ (tested on 3.7.0), PHP 7.4+.

## Installation

Copy the contents of `plugins/` into the phpList plugin directory
(`lists/admin/plugins/` by default, or the directory set in `PLUGIN_ROOTDIRS`):

```
plugins/WpSubscriberSync.php
plugins/WpSubscriberSync/
```

Then enable the plugin in *Config → Manage plugins* and create a key for each
WordPress site in *Subscribers → WordPress sync keys* (super users only).
The page shows the endpoint URL, the key ID and — once — the secret to paste
into the WordPress plugin.

For each key you choose:

- the lists the site may write to, and the default ones;
- whether new subscribers are **confirmed immediately** (the site collected
  consent) or left unconfirmed;
- the attributes the site may set (text, textarea, hidden, date, select,
  radio, checkbox);
- a rate limit per minute.

The last 100 requests are listed on the same page; retention of the log is set
in *Config → Settings → WP Subscriber Sync*.

### Optional constants (`config/config.php`)

| constant                        | effect                                                        |
|---------------------------------|---------------------------------------------------------------|
| `WPSS_ALLOW_HTTP`               | accept plain HTTP. **Local development only.**                |
| `WPSS_TRUST_FORWARDED_PROTO`    | trust `X-Forwarded-Proto: https` (behind a TLS-terminating proxy) |

## Testing

`tools/wpss-client.php` signs and sends requests like the WordPress plugin
does. Credentials come from the environment, so the secret does not end up in
the shell history:

```sh
export WPSS_URL='https://example.org/lists/?pi=WpSubscriberSync&p=endpoint'
export WPSS_KEY=wpss_xxxxxxxxxxxx
read -s WPSS_SECRET && export WPSS_SECRET
php tools/wpss-client.php ping
php tools/wpss-client.php subscribe someone@example.org --attr="First Name=Mario"
```

**Note for macOS checkouts:** the file system is case-insensitive, so never add
a file whose name differs from an existing one only by case (`endpoint.php` is
the public page; classes live in `WpssEndpoint.php` / `WpssDb.php`).

## License

AGPL-3.0-or-later, like phpList.
