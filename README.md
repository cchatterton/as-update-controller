# AS Update Controller

A WordPress plugin library and update coordinator for **AlphaSys-authored plugins from cchatterton on GitHub**. Installable ZIP: [latest release](https://github.com/cchatterton/as-update-controller/releases/latest/download/as-update-controller.zip).

WordPress 6.5+, PHP 7.4+. On multisite, network activate it. Open **Plugins → AlphaSys Plugins** (Network Admin on multisite).

- **Installed:** version comparisons, update-management status, per-plugin checks and selected bulk updates.
- **Catalogue:** searchable cards, release notes, install/update/activate actions. Installation leaves new feature plugins inactive.
- **Settings:** explains fixed manual-only checks. **Check for updates** refreshes available plugins and installed update status together.

Activation removes obsolete controller schedules. It performs no discovery or plugin upgrades. Review updates and choose **Update selected plugins**. Each update has a separate result and an interrupted batch can be resumed. The controller updates itself last. Existing activation and settings are preserved by native WordPress installation APIs. Keep your normal backup and staging process; a batch is not an atomic transaction or a site rollback service.

## Ownership and coexistence

Public stable releases from cchatterton with verified AlphaSys package authorship are discovered by default, regardless of repository prefix. Ordinary new plugins need no registry entry. The registry holds reviewed exceptions: ambiguous authors, explicit includes/excludes, domain restrictions, aliases/supersession, legacy compatibility and readiness overrides. Check for updates downloads the public catalogue JSON generated from verified releases. See [discovery and exceptions](docs/DISCOVERY.md). Both controllers retain separate branded catalogues and share the repository index and scan lock.

## Performance

Every explicit Check for updates downloads exactly one public JSON file from `raw.githubusercontent.com`, using a unique freshness query and `Cache-Control: no-cache`. It makes no REST/GraphQL API calls, owner listings, per-plugin lookups or release ZIP inspections. No GitHub token is needed on WordPress sites. Request count stays one as the catalogue grows. Checks are manual only, with no cooldown, automatic retry, cron or page-load HTTP.

The controller evaluates its own entry first. If newer, it shows only that update and stops processing other entries; update it explicitly and check again. When current, the same downloaded JSON refreshes available capabilities and installed update status together. Missing/invalid controller metadata or a failed/invalid feed retains the previous snapshot. Native package downloads and checksum/root checks happen only when installing/updating.

The shared, unprefixed `github-cchatterton` table remains a local mirror of verified catalogue records, including author, released version, local version, installed state and alpha/beta. It is not a repository scanner. Existing other-author/non-plugin rows may remain as history. Independent WordPress installations should use separate databases. Normal rendering and native update projections read local state only.

A check is bounded to ten seconds/1 MiB and one request with redirects disabled. Public file delivery can still fail; that is reported without erasing verified results. Update availability depends on catalogue publication, which is part of every plugin release.

## Existing plugins

The initial catalogue contains verified public release assets, not a claim that every plugin has migrated. For reviewed legacy updater files, exact SHA-256 matches allow narrowly scoped updater callbacks to be suspended while the controller runs. Unknown file versions remain flagged for review. Deactivating this controller can allow unchanged legacy updater code to resume; migrated client plugins never run a fallback updater.

A complete migration still requires a new release of each feature plugin removing its updater hooks, cron jobs and forced-refresh code, and adding the [client integration](docs/CLIENT-API.md). Site-level MU plugins and custom forced-refresh redirects require separate inspection. Plugin settings are not removed by controller deactivation/deletion. Controller state is retained for recovery.

## Release catalogue publishing (required)

Every stable same-brand plugin release, including this controller, must publish its catalogue entry after the GitHub release ZIP is verified. New plugins need no manual registry registration: the publisher verifies ownership, exact package author, identity, requirements, domain/readiness metadata and checksum.

From a current checkout of this controller repository, run:

```sh
python3 scripts/publish-catalogue.py --repo <released-plugin-repo> --expect-version <released-version>
git add catalogue.json
git commit -m "Publish verified <released-plugin-repo> <released-version>"
git push origin main
```

Then fetch the public `https://raw.githubusercontent.com/cchatterton/as-update-controller/main/catalogue.json` with a unique freshness query and verify the plugin's version, tag, basename, asset and SHA-256 against the published ZIP. If it is missing/stale or publication fails, the release is incomplete. Preserve other concurrent release entries; update the checkout and regenerate if a push conflicts, never force-push the catalogue.

`--repo` verifies only that release and preserves other previously verified entries. A full `python3 scripts/publish-catalogue.py` scan is available for an explicit reconciliation; `--check` verifies without writing and does not count as publication. No publishing scans are scheduled. GitHub authentication is used by the release publisher, not customer WordPress sites.

## Build and recovery

Run `bash scripts/build-plugin-zip.sh`. The root ZIP and `dist/as-update-controller.zip` contain only the plugin directory. Commit the root ZIP, tag matching header/changelog/readme versions, upload the ZIP to the GitHub release, then run the catalogue publisher. Each controller has a separate release. For recovery use the verified official release ZIP via WordPress Upload Plugin or trusted hosting deployment tools.

The integration helper is source for feature-plugin migrations, not part of the installed controller ZIP. It resolves the official controller ZIP only after an authorised install action. No WordPress.org listing is assumed; readme Contributors is intentionally blank pending a confirmed WordPress.org username.

See [validation](docs/VALIDATION.md) for tested behaviour and limits. See [standards](https://github.com/cchatterton/codex-standards).

## Catalogue readiness

See [beta classification and catalogue grouping](docs/BETA-CATALOGUE.md). Set `beta` explicitly for every registry entry. Approved readiness changes are included in verified catalogue metadata.
