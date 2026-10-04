# AS Update Controller

A WordPress plugin library and update coordinator for **AlphaSys-authored plugins from cchatterton on GitHub**. Installable ZIP: [latest release](https://github.com/cchatterton/as-update-controller/releases/latest/download/as-update-controller.zip).

WordPress 6.5+, PHP 7.4+ with the ZIP extension. On multisite, network activate it. Open **Plugins → AlphaSys Plugins** (Network Admin on multisite).

- **Installed:** version comparisons, update-management status, per-plugin checks and selected bulk updates.
- **Catalogue:** searchable cards, release notes, install/update/activate actions. Installation leaves new feature plugins inactive.
- **Settings:** explains fixed manual-only checks. **Check for updates** refreshes available plugins and installed update status together.

Activation removes obsolete controller schedules. It performs no discovery or plugin upgrades. Review updates and choose **Update selected plugins**. Each update has a separate result and an interrupted batch can be resumed. The controller updates itself last. Existing activation and settings are preserved by native WordPress installation APIs. Keep your normal backup and staging process; a batch is not an atomic transaction or a site rollback service.

## Ownership and coexistence

Public stable releases from cchatterton with verified AlphaSys package authorship are discovered by default, regardless of repository prefix. Ordinary new plugins need no registry entry. The registry holds reviewed exceptions: ambiguous authors, explicit includes/excludes, domain restrictions, aliases/supersession, legacy compatibility and readiness overrides. Check for updates builds the local catalogue directly from verified GitHub releases. See [discovery and exceptions](docs/DISCOVERY.md). Both controllers retain separate branded catalogues and share the repository index and scan lock.

## Performance

Both controllers create or reuse the exact table `github-cchatterton` in the WordPress database, without a WordPress prefix. One row per repository stores raw author, canonical brand, released version, local version, local installed state and `alpha_beta`, plus verification metadata. This is shared within that database, including multisite. Use a separate database for independent WordPress installations. Existing verified catalogues seed the table during the first manual check.

Every click first fetches this controller’s latest stable release through the GitHub API. If newer, it verifies and lists only that controller update and stops, without an owner listing or other plugin lookup. Update the controller explicitly, then check again. A failed controller lookup also stops and retains previous records. Only when the controller is current does the check fetch other repository/release metadata. There is no controller cooldown, timed author ignore, stored retry deadline or automatic retry. The database avoids repeating unchanged ZIP inspections, including those from another author. New or changed release assets are inspected before being advertised; same-tag replacement is rejected. Page rendering and update notices read local data only.

For frequent release testing, set a server-side `GITHUB_CCHATTERTON_TOKEN` in wp-config.php with public-metadata read access. Existing `ASUC_GITHUB_TOKEN` / `TNUC_GITHUB_TOKEN` are accepted, in that order after the shared constant. After the single controller-first REST request, authenticated GraphQL fetches up to 100 repositories and their latest stable release/asset metadata per request. Without a token, public REST needs one repository-list call per 100 repos plus one latest-release call per repository. GitHub's anonymous quota is commonly 60 requests/hour per IP; the controller cannot remove GitHub's limits. Tokens are sent only to api.github.com, never browser output or download hosts. No site inventory is sent.

Each browser step performs up to 25 local transitions, with a two-second dispatch budget checked between operations. API requests are limited to 15 seconds/4 MiB; ZIP inspection to 25 seconds, 64 MiB compressed, 256 MiB expanded and 10,000 members. Closing the page stops requests. The next Check starts fresh and reuses verified rows. Concurrent database writes are serialised, without a waiting period between completed checks. GitHub failures retain last-good records and report incomplete results; another click is permitted immediately.

No controller cron, background polling or automatic checker runs. Upgrade migration clears old controller schedules and ignores saved scheduled-mode preferences. Generic WordPress force-check query parameters cannot initiate discovery. Native WordPress auto-update preferences remain WordPress's responsibility.

## Existing plugins

The initial catalogue contains verified public release assets, not a claim that every plugin has migrated. For reviewed legacy updater files, exact SHA-256 matches allow narrowly scoped updater callbacks to be suspended while the controller runs. Unknown file versions remain flagged for review. Deactivating this controller can allow unchanged legacy updater code to resume; migrated client plugins never run a fallback updater.

A complete migration still requires a new release of each feature plugin removing its updater hooks, cron jobs and forced-refresh code, and adding the [client integration](docs/CLIENT-API.md). Site-level MU plugins and custom forced-refresh redirects require separate inspection. Plugin settings are not removed by controller deactivation/deletion. Controller state is retained for recovery.

## Release catalogue publishing

The publisher remains for controller versions through 0.6.x and release audits: run `python3 scripts/publish-catalogue.py`, then commit/push verified `catalogue.json`. `--check` validates without writing. Controllers 0.7.0+ discover new releases directly on an explicit check and do not depend on this publication. No scheduled publishing scans are configured.

## Build and recovery

Run `bash scripts/build-plugin-zip.sh`. The root ZIP and `dist/as-update-controller.zip` contain only the plugin directory. Commit the root ZIP, tag matching header/changelog/readme versions, upload the ZIP to the GitHub release, then run the catalogue publisher. Each controller has a separate release. For recovery use the verified official release ZIP via WordPress Upload Plugin or trusted hosting deployment tools.

The integration helper is source for feature-plugin migrations, not part of the installed controller ZIP. It resolves the official controller ZIP only after an authorised install action. No WordPress.org listing is assumed; readme Contributors is intentionally blank pending a confirmed WordPress.org username.

See [validation](docs/VALIDATION.md) for tested behaviour and limits. See [standards](https://github.com/cchatterton/codex-standards).

## Catalogue readiness

See [beta classification and catalogue grouping](docs/BETA-CATALOGUE.md). Set `beta` explicitly for every registry entry. Approved readiness changes are included in verified catalogue metadata.
