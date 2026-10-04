# Validation — 0.1.0

Run on 26 September 2026 in an isolated local WordPress 7.1.2 installation with both controllers, PHP 8.5.7 and MySQL 8.0.35. All packaged PHP also passes PHP 8.1.29 syntax checks.

## Executed checks

- Cold, warm and failed caches: repeated native update-transient reads, View details and all six controller screens made zero update-metadata HTTP calls.
- One aggregate HTTP request per brand check; cooldown, atomic lock, independent brand state, 429 / Retry-After backoff, malformed metadata and last-good cache retention.
- Strict author/repository identity, duplicate IDs, third-party provider preservation, unauthorised install/check rejection.
- Native official release ZIP installation and old-to-current updates for Menubot and AS QS Relay; checksum and root/version verification; newly installed plugins remain inactive; update preserves activation and an existing settings option.
- Tampered package and unexpected archive root rejection; PHP/dependency requirements; disabled file modifications; partial-batch results and safe interrupted-request recovery.
- Audited legacy hooks removed for Menubot and AS QS Relay; active client/controller metadata produces one GitHub link and one controller check link.
- Absent/inactive controller bootstrap: correct Install/Activate actions, repeated helper inclusion without duplicate handlers, and zero HTTP.
- Multisite: network-scoped state and locks, main-site-only scheduling, no subsite schedule duplication, subsite-admin install denial and rejection of a merely site-active controller as a network provider.
- Both controllers updated themselves from their live published GitHub ZIPs on the disposable multisite, preserving network activation and saved controller state. Live published catalogues include the controller itself.
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

## PHP 7.4 correction

See [PHP compatibility validation](PHP-COMPATIBILITY.md) for WordPress 7.0 runtime testing on PHP 7.4.30, 8.1.23 and 8.5.7. This supersedes the earlier PHP minimum and syntax-only compatibility notes above.

## Interface 0.2.0

See [interface validation](INTERFACE-0.2.0.md) for modal checking, bulk progress, failure and interruption recovery, catalogue installation and PHP 7.4 rendering checks.

## Manual discovery 0.6.0 — 4 October 2026

Executed in a new disposable WordPress 7.0 installation and then converted to multisite, with an isolated MySQL 8.0.35 database. No customer site changed.

- Both publishers: 12 offline tests each; live paginated discovery and published ZIP verification for both brands. New unregistered matching releases are found; cross-brand packages are excluded.
- PHP 7.4.30: syntax validation of all packaged PHP and the manual-discovery runtime suite on multisite. PHP 8.1.23: single-site integration, manual-discovery, security/recovery, beta grouping and admin UI assertions; multisite scope/lock/permission and manual-discovery suites.
- Both standalone domain-metadata suites pass, including cold caches, hostname boundaries and localhost policy.
- Native WordPress installation and old-to-current upgrade of Menubot and AS QS Relay pass with checksum verification, activation and settings preservation. Tampered downloads and wrong package roots are rejected.
- Manual regression tests verify legacy schedule removal, ignored scheduled preferences, no HTTP for cron/generic force-check/background calls, one request for both new capability discovery and installed updates, cooldown, denied users, and withdrawal cleanup.
- Existing integration tests cover rate-limit backoff, invalid metadata, last-good preservation, atomic locks, cold/warm cache reads and third-party update preservation.
- Author Branded interfaces retain existing AlphaSys/Techn styling and accessible modal labels/status markup. All three tabs render without discovery HTTP. The only settings UI change replaces scheduling controls with a fixed manual-only explanation.

Browser follow-up: after correcting the disposable site URL and replacing a stale browser tab, both Author Branded settings screens were visually checked at 1280 × 720 and 390 × 844. Version watermark, manual-only explanation and controls remain readable without overlap. Keyboard activation of Check for updates showed the labelled progress dialog, refreshed status and returned focus to the button. These checks apply the existing Branding and UX standard; no broader redesign was made.

Limitations: no full screen-reader audit or exhaustive viewport matrix was performed. Older fixture-dependent feature/action tests require a larger preinstalled plugin set and were not completed in this fresh runtime. All feature-plugin behaviour, unknown legacy updater code, credentials-based filesystem transports and production rollout remain outside this validation. New releases require explicit feed publication before a site manual check can discover them. Existing historical validation notes above describe earlier versions, including their now-removed scheduling behaviour.

Release delivery verified: both published v0.6.0 ZIPs match the committed root ZIPs byte-for-byte. Remote feeds match the generated snapshots and controller package checksums. Both controllers self-updated disposable old-version copies to 0.6.0 through the live feed and native WordPress upgrader, preserving network activation and stored state. The final feeds contain 17 AlphaSys and 22 Techn entries; IA GPT is newly discovered relative to the previous Techn feed.


## 0.7.0 — direct manual GitHub discovery (4 October 2026)

Validated in the isolated local WordPress multisite with both controllers network-active. No customer site was modified.

- Both live scans completed across 58 public repositories using an ephemeral authenticated test API connection. Brand catalogues remained separate (17 AlphaSys, 22 Techn). Techn discovered and verified Release Management 0.11.9, including its published SHA-256, without reading the static feed.
- Direct-scan regression fixtures exercise new unregistered identities, installed 0.11.7 to released 0.11.9 projection, cold rendering with zero HTTP, old cron removal, successful-check cooldown, quota pause, explicit cursor resume, permission rejection, brand/identity/tag/checksum rejection and policy withdrawal.
- Public API checks can hit the hosting IP's quota. This was reproduced in the browser: an HTTP 403 paused progress, displayed a retry deadline, and retained verified results. Test authentication was supplied only to the disposable server process, never bundled or persisted in source.
- PHP ZIP inspection requires ZipArchive; missing support is reported before starting. Initial uncached scans can take several minutes. Scans stop when the browser stops requesting steps and resume only after a fresh authorised check. Without JavaScript, row/form actions advance one bounded step per click.
- Static catalogue and publisher support remain for older controllers. Historical validation sections above describe those earlier releases; the direct-scan behaviour replaces the one-feed-request transport on the current Check for updates path.

- Final cold-start live scans also completed with empty catalogues (22 Techn, 17 AlphaSys), including the legacy Help Guides folder exception. A regression now ensures bundled identity pins survive incremental cold discovery.
- Existing integration, package security, admin rendering and beta classification suites passed after making their fixtures independent of active client registrations and multisite activation scope. Direct-scan tests also passed on PHP 7.4. Packaged PHP passed PHP 7.4 syntax checks, JavaScript passed syntax checks, and both release ZIPs passed root/version/content verification.
- Browser checks confirmed progress, quota-paused feedback, native plugin-row continuation and Release Management 0.11.9 in the library. No customer-site deployment or customer-specific proxy/filesystem checks were performed.

- Published v0.7.0 assets were downloaded and compared byte-for-byte with the committed builds. Both compatibility feeds advertise the verified 0.7.0 packages. Actual official 0.6.0 installations then self-updated through the published feed and official release ZIPs to 0.7.0, preserving network activation and saved state. Set `CONTROLLER_TEST_FROM=0.6.0` to reproduce with released copies.


## 0.7.1 — remembered identities and lightweight version checks (4 October 2026)

- On the isolated multisite, live Techn repeat-check engine time was 1.57 seconds (22 public version HEAD requests, one repository-list API request, zero ZIP downloads, five browser-step batches). AlphaSys was 1.31 seconds (17 HEAD requests, one listing, zero ZIPs, four batches). These are local engine timings; customer network and WordPress bootstrap time vary.
- The initial learning passes took 32.19 seconds for Techn and 23.69 seconds for AlphaSys. Those passes classified previously unknown repositories. Known plugin updates ran before new discovery. Techn verified Release Management 0.11.10 during the live check.
- Tests simulate fifteen fresh releases with short-lived check caches expired between each check: fifteen repository-list API calls, zero release API calls, and fifteen changed ZIP inspections per brand. Other-author repositories stayed skipped from durable WordPress state. No new custom database table is needed.
- Quota fixtures verify that HTTP 403 defers new repository discovery while a known plugin still advances to its new version. Backoff is preserved. Also covered: learned-author exclusions, rule-change invalidation, cold-render zero HTTP, explicit resume, permission rejection, immutable-tag byte replacement rejection and PHP 7.4 execution.
- Existing integration, package security, admin rendering and beta grouping suites passed. PHP 7.4 and JavaScript syntax checks passed. No customer site was modified.

Published release tags and ZIP bytes must be immutable. Normal checks intentionally reuse the previous verified package when the stable tag is unchanged. Settings → Recheck all repositories performs the slower asset audit, including revisiting ignored repositories; replacement bytes under an existing tag are rejected. New discovery still depends on GitHub API availability, independently of known-plugin updates.

- The browser completed the normal Techn check with the success dialog. Existing 0.7.0 package-inspection caches, including other-author results, are reused while building the new durable ignore index.

- Published 0.7.1 ZIPs were downloaded and matched byte-for-byte with the builds; live compatibility feeds resolve to their checksums. Official 0.7.0 copies upgraded to 0.7.1 through WordPress, preserving network activation and state. An anonymous live Techn check also completed (one API listing, 22 public version checks, and one newly changed controller package verified).

## 0.8.0 — shared owner table and fresh API checks (2026-10-04)

Disposable multisite checks passed under PHP 7.4.30 and PHP 8.5.7: table creation/reuse, one row per repo, separate branded projections, saved other-author metadata, alpha/beta, released/local versions, inactive/network-active transitions, new repository discovery after a warm check, fifteen immediate changed releases, unchanged ZIP reuse, 403 immediate retry, previous-data retention, writer lock and permission rejection. REST and authenticated GraphQL fixtures both passed. Integration, security, admin UI, beta grouping and multisite suites passed; domain policy and both 12-test publisher suites passed. Both plugin load orders were verified without duplicate functions or HTTP. All packaged PHP passed PHP 7.4 syntax checks; JavaScript syntax and git whitespace checks passed.

Live authenticated checks enumerated 58 repositories into the shared table, yielding 22 Techn and 17 AlphaSys capabilities and Release Management 0.11.10. The initial upgrade check took 4.23 seconds (one API request, two ZIP downloads with redirects); immediate repeat checks took 2.21 and 2.40 seconds, each using one API request and zero downloads. These are local CLI timings with a public-metadata token, not a promise for every host or anonymous API.

The exact unprefixed `github-cchatterton` table is shared within one database. Local state aggregates multisite activation and retains rows after controller deletion. GitHub still enforces its own quotas. No customer WordPress installation or credentials were changed. Historical sections above describe previous versions, including now-removed cooldown/backoff behaviour.

Post-publication: installed the official 0.7.1 ZIPs into disposable copies, then upgraded both through the native controller batch flow using the published 0.8.0 assets and compatibility catalogues. Both completed, verified installed 0.8.0 headers, and preserved network activation and saved controller state. All published catalogue packages were independently verified before feed publication.

## 0.8.1 — controller-first manual checks (2026-10-04)

PHP 7.4.30 and 8.5.7: new controller-first tests passed for both brands. A newer verified controller performs only its own release API request and ZIP inspection, projects a native update, lists only the controller in Updates available, and stops without an owner listing or other-repository lookup. It does not mark a full check successful. A failed initial lookup preserves the verified update and stops; a current controller continues to discovery on the next immediate click. REST/GraphQL shared discovery, fifteen rapid releases, integration, security and admin UI suites also passed. All packaged PHP and both JavaScript files passed syntax checks.

Mixed-version checks loaded the official 0.8.0 AlphaSys controller before the new Techn controller, then reversed the brands. In both cases the newer controller retained its own initial release check, independent of the older shared engine. Both versions share the writer lock and repository table.

Post-publication live checks used the published 0.8.1 releases with 0.8.0 as the comparison version: each returned controller_update after exactly one controller API request and zero other repository requests. Official 0.8.0 disposable copies then upgraded through the native batch flow to the published 0.8.1 ZIPs, preserving network activation and saved state. Published asset digests match the built ZIPs.
