# AS Update Controller

A WordPress plugin library and update coordinator for **AlphaSys-authored plugins from cchatterton on GitHub**. Installable ZIP: [latest release](https://github.com/cchatterton/as-update-controller/releases/latest/download/as-update-controller.zip).

WordPress 6.5+, PHP 7.4+. On multisite, network activate it. Open **Plugins → AlphaSys Plugins** (Network Admin on multisite).

- **Installed:** version comparisons, update-management status, per-plugin checks and selected bulk updates.
- **Catalogue:** searchable cards, release notes, install/update/activate actions. Installation leaves new feature plugins inactive.
- **Settings:** automatic discovery every 6, 12 or 24 hours, or manual checks only. Default: six hours.

Activation schedules discovery; it never upgrades other plugins automatically. Review updates and choose **Update selected plugins**. Each update has a separate result and an interrupted batch can be resumed. The controller updates itself last. Existing activation and settings are preserved by native WordPress installation APIs. Keep your normal backup and staging process; a batch is not an atomic transaction or a site rollback service.

## Ownership and coexistence

Only exact approved repository identities with AlphaSys authorship enter this catalogue. A committed registry fixes each owner, repo, author, plugin basename and asset name. New identities require a controller release. Techn has its own independent TN Update Controller; both may run on the same site with separate hooks, data, schedules and locks. Unrelated plugins retain their own providers.

## Performance

Page rendering, update transient filters, notices and plugin details read local state only, even with missing or expired caches. One explicit or scheduled check fetches one aggregate JSON document; it does not interrogate every GitHub repository. No tokens or site inventory are sent. Successful checks have a 60-second cooldown. Failed requests preserve the last good result and use exponential backoff with jitter and GitHub retry deadlines. Discovery timeout: eight seconds; maximum response: 1 MiB. Downloads run only on explicit installation/update operations, with SHA-256 validation, HTTPS host restrictions, at most four hops, 30 seconds per hop and a 64 MiB limit.

WP-Cron must be run by traffic or your host scheduler. Settings displays the scheduled time and disabled/overdue status. Manual checks remain available. Native WordPress plugin auto-update preferences remain WordPress's responsibility; scheduling metadata discovery does not opt plugins into auto-updates.

## Existing plugins

The initial catalogue contains verified public release assets, not a claim that every plugin has migrated. For reviewed legacy updater files, exact SHA-256 matches allow narrowly scoped updater callbacks to be suspended while the controller runs. Unknown file versions remain flagged for review. Deactivating this controller can allow unchanged legacy updater code to resume; migrated client plugins never run a fallback updater.

A complete migration still requires a new release of each feature plugin removing its updater hooks, cron jobs and forced-refresh code, and adding the [client integration](docs/CLIENT-API.md). Site-level MU plugins and custom forced-refresh redirects require separate inspection. Plugin settings are not removed by controller deactivation/deletion. Controller state is retained for recovery.

## Release catalogue publishing

The ready-to-enable GitHub workflow template runs every six hours and supports manual dispatch after feature-plugin releases. **Automatic catalogue publishing is not enabled in this initial handoff:** the publishing credential lacked GitHub workflow scope. Until enabled, run the publisher manually after releases. See [workflow setup](docs/WORKFLOW-SETUP.md). `python3 scripts/publish-catalogue.py` uses authenticated GitHub CLI to download the **published stable release asset**, verify its package root, main file, author, Update URI, header/tag versions and size, and compute its checksum. All registered releases must verify before the catalogue changes. It never trusts repository source as a substitute for a missing release asset and never expands the legacy compatibility allowlist. Site checks consume this static document without a GitHub API token. A newly published release appears after catalogue publication plus the next site check.

## Build and recovery

Run `bash scripts/build-plugin-zip.sh`. The root ZIP and `dist/as-update-controller.zip` contain only the plugin directory. Commit the root ZIP, tag matching header/changelog/readme versions, upload the ZIP to the GitHub release, then run the catalogue publisher. Each controller has a separate release. For recovery use the verified official release ZIP via WordPress Upload Plugin or trusted hosting deployment tools.

The integration helper is source for feature-plugin migrations, not part of the installed controller ZIP. It resolves the official controller ZIP only after an authorised install action. No WordPress.org listing is assumed; readme Contributors is intentionally blank pending a confirmed WordPress.org username.

See [validation](docs/VALIDATION.md) for tested behaviour and limits. See [standards](https://github.com/cchatterton/codex-standards).

## Catalogue readiness

See [beta classification and catalogue grouping](docs/BETA-CATALOGUE.md). Set `beta` explicitly for every registry entry. Approved readiness changes are included in verified catalogue metadata.
