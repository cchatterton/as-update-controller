# Client integration contract v1

Controller basename: `as-update-controller/as-update-controller.php`. Runtime capability: `defined('ASUC_API_VERSION') && ASUC_API_VERSION >= 1 && function_exists('asuc_available') && asuc_available()`.

1. Preserve the existing plugin directory and main filename. Publish a correctly authored stable release ZIP. A manual controller check discovers its identity by default; add a registry exception only for ambiguous/legacy identity or an explicit override.
2. Remove independent updater code, hooks, schedules, transient invalidation and forced-check redirects. Old scheduled events should be cleared by a versioned local migration.
3. Use `Author: AlphaSys`, `Author URI: https://alphasys.com.au`, and `Update URI: https://github.com/cchatterton/EXACT-REPOSITORY`. Omit `Plugin URI`.
4. Add `AlphaSys Controller API: 1` to the main plugin header.
5. Copy `integration/controller-client.php` into the plugin (for example `functions/controller-client.php`). From its main file:

```php
require_once __DIR__ . '/functions/controller-client.php';
asuc_client_register(__FILE__, 'EXACT-REPOSITORY');
```

The guarded shared helper collects registrations from multiple plugins independently of load order. It adds GitHub plus Install/Activate/Update AlphaSys Update Controller as appropriate. With a compatible active controller it yields; the controller supplies GitHub and Check for updates to recognised active and inactive plugins. `asuc_check_url($plugin_basename)` returns a nonce-protected URL for explicit discovery. Although the action originates from one plugin, the explicit scan refreshes all same-brand capabilities and installed update status directly from GitHub. The row action redirects once to the controller progress view; ordinary visits never start a scan.

No controller is needed for feature operation. Inactive clients cannot execute bootstrap code; install the controller directly from its official release ZIP. Install and activation are separate explicit actions. On multisite use Network Admin and network activation. Install/update authority is checked separately from feature access.

Techn clients must use the corresponding TN Update Controller helper, `tnuc_` API, `TNUC_API_VERSION` and `Techn Controller API` header instead. Do not include both helpers in a single-brand plugin. Review standardised row labels and author headers as part of each client migration.
