# AS Update Controller

A WordPress plugin library and update coordinator for **AlphaSys-authored plugins from cchatterton on GitHub**. Installable ZIP: [latest release](https://github.com/cchatterton/as-update-controller/releases/latest/download/as-update-controller.zip).

WordPress 6.5+, PHP 7.4+. On multisite, network activate it. Open **Plugins → AlphaSys Plugins** (Network Admin on multisite).

- **Installed:** version comparisons, update-management status, per-plugin checks and selected bulk updates.
- **Catalogue:** searchable cards, release notes, install/update/activate actions. Installation leaves new feature plugins inactive.
- **Settings:** explains fixed manual-only checks. **Check for updates** refreshes available plugins and installed update status together.

Activation removes obsolete controller schedules. It performs no discovery or plugin upgrades. Review updates and choose **Update selected plugins**. Each update has a separate result and an interrupted batch can be resumed. The controller updates itself last. Existing activation and settings are preserved by native WordPress installation APIs. Keep your normal backup and staging process; a batch is not an atomic transaction or a site rollback service.

## Ownership and coexistence

Public stable releases from cchatterton with verified AlphaSys package authorship are discovered by default, regardless of repository prefix. Ordinary new plugins need no registry entry. The registry holds reviewed exceptions: ambiguous authors, explicit includes/excludes, domain restrictions, aliases/supersession, legacy compatibility and readiness overrides. The generated catalogue is the verified feed, not a manually maintained registration list. See [discovery and exceptions](docs/DISCOVERY.md). Both controllers retain separate brand identities, namespaces and locks.

## Performance

Page rendering, update transient filters, notices and plugin details read local state only, even with missing or expired caches. One explicit manual check fetches one aggregate JSON document; it does not interrogate every GitHub repository. No tokens or site inventory are sent. Successful checks have a 60-second cooldown. Failed requests preserve the last good result and use exponential backoff with jitter and GitHub retry deadlines. Discovery timeout: eight seconds; maximum response: 1 MiB. Downloads run only on explicit installation/update operations, with SHA-256 validation, HTTPS host restrictions, at most four hops, 30 seconds per hop and a 64 MiB limit.

No controller cron, background polling or automatic checker runs. Upgrade migration clears old controller schedules and ignores saved scheduled-mode preferences. Generic WordPress force-check query parameters cannot initiate discovery. Native WordPress auto-update preferences remain WordPress's responsibility.

## Existing plugins

The initial catalogue contains verified public release assets, not a claim that every plugin has migrated. For reviewed legacy updater files, exact SHA-256 matches allow narrowly scoped updater callbacks to be suspended while the controller runs. Unknown file versions remain flagged for review. Deactivating this controller can allow unchanged legacy updater code to resume; migrated client plugins never run a fallback updater.

A complete migration still requires a new release of each feature plugin removing its updater hooks, cron jobs and forced-refresh code, and adding the [client integration](docs/CLIENT-API.md). Site-level MU plugins and custom forced-refresh redirects require separate inspection. Plugin settings are not removed by controller deactivation/deletion. Controller state is retained for recovery.

## Release catalogue publishing

Run `python3 scripts/publish-catalogue.py` explicitly after releases, then commit and push the verified `catalogue.json`. The publisher enumerates all public owner repositories, verifies stable release ZIPs and applies exceptions. It validates package roots, main files, author, Update URI, versions, requirements, domain metadata and checksums before replacing the snapshot atomically. Failures retain the previous feed. `--check` performs the same verification without writing. The optional workflow template is manual-dispatch only; no periodic publishing scans are configured. Site checks read the latest published snapshot. A release becomes discoverable after publication plus the next manual site check.

## Build and recovery

Run `bash scripts/build-plugin-zip.sh`. The root ZIP and `dist/as-update-controller.zip` contain only the plugin directory. Commit the root ZIP, tag matching header/changelog/readme versions, upload the ZIP to the GitHub release, then run the catalogue publisher. Each controller has a separate release. For recovery use the verified official release ZIP via WordPress Upload Plugin or trusted hosting deployment tools.

The integration helper is source for feature-plugin migrations, not part of the installed controller ZIP. It resolves the official controller ZIP only after an authorised install action. No WordPress.org listing is assumed; readme Contributors is intentionally blank pending a confirmed WordPress.org username.

See [validation](docs/VALIDATION.md) for tested behaviour and limits. See [standards](https://github.com/cchatterton/codex-standards).

## Catalogue readiness

See [beta classification and catalogue grouping](docs/BETA-CATALOGUE.md). Set `beta` explicitly for every registry entry. Approved readiness changes are included in verified catalogue metadata.
