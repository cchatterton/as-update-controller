# Validation — 0.1.0

Run on 26 September 2026 in an isolated local WordPress 7.1.2 installation with both controllers, PHP 8.5.7 and MySQL 8.0.35. All packaged PHP also passes PHP 8.1.29 syntax checks.

## Executed checks

- Cold, warm and failed caches: repeated native update-transient reads, View details and all six controller screens made zero update-metadata HTTP calls.
- One aggregate HTTP request per brand check; cooldown, atomic lock, independent brand state, 429 / Retry-After backoff, malformed metadata and last-good cache retention.
- Strict author/repository identity, duplicate IDs, third-party provider preservation, unauthorised install/check rejection.
- Native official release ZIP installation and old-to-current updates for Menubot and AS QS Relay; checksum and root/version verification; newly installed plugins remain inactive; update preserves activation and an existing settings option.
- Tampered package and unexpected archive root rejection; PHP/dependency requirements; disabled file modifications; partial-batch results and safe interrupted-request recovery.
- Audited legacy hooks removed for Menubot and AS QS Relay; active client/controller metadata produces one GitHub link and one controller check link.
- Multisite: network-scoped state and locks, main-site-only scheduling, no subsite schedule duplication, subsite-admin install denial and rejection of a merely site-active controller as a network provider.
- Browser: desktop cards, 390-pixel layout, live catalogue search, manual-mode settings save, and independent AlphaSys catalogue.

## Reproduction

Use a disposable installation with both source repositories linked into wp-content/plugins and both controllers active. These tests intentionally change test options and install official plugin assets. Never run them on a live customer site.

```sh
wp eval-file tests/integration.php
wp eval-file tests/install.php
wp eval-file tests/security.php
# On a disposable multisite with both controllers network-active and blog ID 2:
wp eval-file tests/multisite.php
```

Tests use the adjacent repository catalogue fixtures. `install.php` requires outbound access to the public GitHub release assets. The publisher independently verifies every catalogue ZIP before publication.

## Limits

WordPress 6.5 is the declared minimum; full runtime tests used WordPress 7.1.2. PHP 8.1 was syntax-checked. Feature behaviour of all catalogue plugins, every historical legacy updater version, hosting credential-based filesystem transports, host-specific MU code and every possible feature-plugin activation scope were not exhaustively exercised. The guided installer supports direct filesystem writes; native WordPress flows remain the recovery route on credential-based filesystems. The controller is not a backup or rollback service.

Initial feature-plugin catalogue releases still use legacy integration. Their migration status is explicit. Reviewed hashes apply only to the exact inspected updater files. A future plugin release that changes those files needs migration to the client API or a new audited controller registry release. No customer site was changed during these tests.
