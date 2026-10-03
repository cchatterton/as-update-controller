# Changelog

## 0.5.6 - 2026-10-03

- Read the catalogue through GitHub's contents API and decode the verified catalogue payload so manual checks are not blocked by stale raw-file caches.

## 0.5.5 - 2026-10-03

- Refresh the AlphaSys catalogue during WordPress native forced update checks so newly published plugin releases appear in the standard update flow.

## 0.5.4 - 2026-10-01

- Recognise audited pre-controller WP Pattern Import installs so they can be updated into the AlphaSys-managed package instead of being shown as an identity conflict.
- Treat active feature-plugin client registration as a valid local identity signal when it matches the approved repository.

## 0.5.3 - 2026-10-01

- Add a cache-busting parameter to forced manual catalogue checks so newly published catalogue entries are visible immediately through GitHub raw content.

## 0.5.2 - 2026-10-01

- Force an immediate remote catalogue refresh when an authorised user clicks Check for updates, while still respecting in-progress checks and remote retry backoff.
- Keep newly published catalogue items discoverable without requiring a controller package release.
- Identify catalogue requests with the AS Update Controller User-Agent.

## 0.5.1 - 2026-10-01

- Bundle WP Pattern Import in the approved AlphaSys registry so refreshed catalogues and installed controller packages recognise the production plugin identity.
- Republish the verified catalogue with WP Pattern Import 0.13.0.

## 0.5.0 - 2026-09-26

- Read domain restrictions from plugin release headers via the verified catalogue. Show all catalogue plugins on localhost. Accept new approved same-brand catalogue entries without controller releases; keep executable legacy trust bundled.

## 0.4.5 - 2026-09-26

- Support exact localhost allowlist entries alongside domain/subdomain restrictions. Restrict Transcript Themes to alphasys.com.au, its subdomains and localhost.

## 0.4.4 - 2026-09-26

- Remove the redundant Update discovery heading and introductory text from Settings.

## 0.4.3 - 2026-09-26

- Promote the controller to Alpha following standards and single-site/multisite verification.
- Set bundled catalogue readiness to non-beta; retain the 0.4.2 card cleanup and 0.4.1 lifecycle grouping fixes.

## 0.4.2 - 2026-09-26

- Remove the catalogue heading/search toolbar.
- Remove redundant catalogue card status strips, including Not installed, Active and Installed/inactive. Group headings and state-specific actions already convey lifecycle state.
- Retain version requirements, Beta chips and compatibility warnings.

## 0.4.1 - 2026-09-26

- Add Installed between Active and Available; keep beta installed plugins in Installed and retain their chip.
- Active cards offer Deactivate; Installed cards offer Activate and Delete; uninstalled Available/Beta cards offer Install. Updates remain on Updates available.
- Render refreshed state through authenticated AJAX instead of fetching potentially cached page HTML. Preserve successful action feedback if the refresh fails.

## 0.4.0 - 2026-09-26

- Replace the Installed tab with Updates available; retain the full catalogue.
- Add card activation/deactivation and confirmed deletion, with network scope and active-site deletion protection.
- Enforce configured domain allowlists for catalogue visibility and managed downloads.
- Remove Manage plugin and place small Beta chips at the bottom-right of cards.

## 0.3.0 - 2026-09-26

- Group catalogue cards into Active plugins, inactive/non-installed Available plugins, and inactive/non-installed Beta plugins.
- Keep active beta plugins in Active and show a Beta chip in cards and the installed list.
- Add explicit boolean beta metadata, validate published values and use bundled defaults for older cached catalogues.
- Mark the ten migrated Techn plugins non-beta; all other Techn entries and all AlphaSys entries except the migrated AS Local CSS remain beta until reviewed against the new standards.
- Keep search working across groups and hide sections with no matching cards.
- Preserve PHP 7.4 compatibility and existing installation/update behaviour.

## 0.2.0 - 2026-09-26

- Split installed and available versions into separate columns; link available versions to release notes and add a GitHub column.
- Remove per-row checks, update-management text, setup notices, persistent success/history panels and settings recovery/status sections.
- Simplify catalogue cards by removing repeated brand banners and author labels.
- Add modal checking feedback and animated, per-plugin update progress with concise completion results and expandable failures.
- Refresh lists in place; retain interruption recovery and compact access to unresolved results.
- Add keyboard focus handling, reduced-motion styling, disabled selection explanations and selection-aware update controls.
- Preserve PHP 7.4 compatibility, separate brand catalogues and network-free metadata rendering.

## 0.1.1 - 2026-09-26

- Support PHP 7.4 to match the minimum PHP version for WordPress 7.0.
- Replace PHP 8-only union return declarations with equivalent PHPDoc types without changing updater behaviour.
- Align the client installation contract with PHP 7.4 compatibility.

## 0.1.0 - 2026-09-26

- Add a AlphaSys-only verified plugin catalogue with Installed, Catalogue and Settings views.
- Add background/manual discovery with durable metadata, atomic locking and retry backoff.
- Integrate native WordPress updates, inactive plugins, checksummed packages and resumable batches.
- Add audited legacy-updater suppression and migration status without replacing feature plugins on activation.
- Publish the version 1 client bootstrap contract and controller recovery instructions.
