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
WordPress site in *Subscribers → WordPress sync keys*.

## License

AGPL-3.0-or-later, like phpList.
