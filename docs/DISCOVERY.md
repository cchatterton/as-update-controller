# Released plugins and manual checks (0.8.1)

## Controller first

Every Check for updates, including a recognised plugin row check, first fetches the initiating controller’s stable release via REST. No owner listing, batch query or other repository request precedes it. Its package is verified using the shared records and existing package checks. A newer version is projected into native updates, displayed alone in Updates available, and ends the scan with `controller_update` status. This does not mark a full catalogue check successful or remove other cached package records. It never installs automatically. Update the controller explicitly, then click Check for updates again.

If the controller is current (including an installed development version newer than the release), the same manual check continues to the owner listing and other repositories. Skip the already-verified controller in subsequent repository processing. A missing/invalid/unavailable controller release stops the check, retaining prior metadata. An error must not be interpreted as “controller current”. The shared implementation is versioned so a newer controller keeps this ordering even when the other installed controller still runs 0.8.0.

## Shared repository records

Both controllers create/reuse the literal SQL table `github-cchatterton` on an explicit check. There is no WordPress prefix: the table is shared within one database, including multisite. Independent WordPress installations should use separate databases. It persists after controller deactivation/deletion; no uninstall purges it.

Each repository has one row keyed by `repo`. User-facing metadata columns are `author` (released plugin header), `version` (latest inspected/reported release), `local_version`, `local_installed_state` (`not_installed`, `inactive`, `active` on any site, or `network_active`) and `alpha_beta`. `alpha` maps to the existing reviewed non-beta state; new plugins default to `beta`. This readiness is independent of GitHub prerelease status. Raw author and canonical `brand` are separate. Non-plugin repositories have no plugin author. Internal fields retain plugin basename, release tag, asset/rule fingerprint, last checked/error, API release metadata and verified package JSON.

The first check seeds already verified catalogue records, lists every public owner repository and learns new packages. Each subsequent click lists repositories and validates release versions through the API again. Unchanged asset/rule fingerprints reuse verified package/author data; changed releases are inspected again, including other-author repositories. There is no timed author skip, success cooldown, controller-imposed retry deadline or separate full-audit control. A new click starts a new scan; verified records survive interruption. Native activation/deactivation, upgrade and deletion actions refresh local state without discovery HTTP.

## API and package checks

Set `GITHUB_CCHATTERTON_TOKEN` in wp-config.php for frequent release testing, using public-metadata read access. Existing ASUC_GITHUB_TOKEN and TNUC_GITHUB_TOKEN are accepted after that shared constant. After the initial controller REST lookup, GraphQL retrieves up to 100 public repositories and their latest stable releases/assets per request, following cursors. Without a token, REST paginates the owner list and requests each repository's latest release. No site inventory is sent. Tokens are restricted to api.github.com with redirects disabled and never sent to release download hosts or output to the browser.

GitHub enforces its own quotas: anonymous REST is normally 60 requests/hour per hosting IP. A shared table cannot remove that limit. API errors preserve last-good data, report incomplete results and permit another explicit click immediately. There are no automatic retries or alternative-endpoint retry chains. See [GitHub rate limits](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api).

Each API response is bounded to 15 seconds/4 MiB. A browser step processes up to 25 transitions, checking a two-second budget between operations. ZIP inspection is bounded to 25 seconds, four trusted HTTPS delivery hops, 64 MiB compressed, 256 MiB expanded and 10,000 files. PHP ZipArchive is required. Inspection reads bytes without extraction or execution. Checks validate header author/version, repository identity, domain policy, paths, file collisions and checksum. Published tags/assets remain immutable. Failure retains the previous verified package. No ordinary page load, native update hook, activation or old cron callback performs discovery HTTP.

Only public stable released WordPress packages from cchatterton with exact AlphaSys or Techn header authorship (case-insensitive), or a reviewed authorship exception, enter the respective branded catalogue. Repository prefixes alone prove nothing. Update URI, when supplied, must match the owner/repo. Forks and archived repositories require explicit include exceptions. Both controllers can populate shared rows, but catalogue projections remain brand-separated. Explicit exclusions/supersession withdraw only that controller's owned update notices.

## Exceptions

`<controller>/data/registry.json` is an exceptions map. Existing full entries are retained for audited legacy callback/identity handling, reviewed readiness, nonstandard package paths and controller bootstrap identity. They are not a list which ordinary new plugins must join. Newly discovered plugins default to beta; reviewed readiness can be overridden. Discovery never adds legacy callback hashes automatically.

Supported rules, keyed by repository unless an explicit `repo` is supplied:

```json
{
  "withdrawn-plugin": {"exclude": true, "reason": "Withdrawn release"},
  "old-plugin": {"superseded_by": "replacement-plugin", "reason": "Keep old installation; offer replacement separately"},
  "reviewed-fork": {"include": true, "reason": "Reviewed branded release"},
  "ambiguous-plugin": {"author_header": "Original Author", "reason": "Verified brand ownership"},
  "restricted-plugin": {"allowed_domains": ["example.org"], "include_subdomains": false, "reason": "Domain-exclusive capability"},
  "ready-plugin": {"beta": false, "reason": "Standards migration validated"}
}
```

Full exceptions can pin `id`, `owner`, `repo`, `file`, `slug`, `asset`, and `author`, with `author_header` for reviewed alternate author text. `id` may be a stable alias for a repository, but existing published IDs/package identities cannot be silently renamed. Supersession withdraws the old package; it never overwrites an installed plugin with a different package. Exceptions cannot change the configured owner or mix the two brands. Domain overrides are applied during verification; installed header fallback remains available before a successful check. Public release assets remain public: domain policy is availability control, not authentication.

## Validation and publishing

Run `python3 tests/discovery.py` for publisher fixtures. On disposable WordPress with both controllers active, run `wp eval-file tests/manual-discovery.php`; repeat with `TEST_GRAPHQL=1` for the batched API fixtures. Run integration/security/UI/domain tests too. Never run mutating tests on customer sites.

`scripts/publish-catalogue.py` remains an explicit release/audit tool for older controllers; its `--check` mode validates without writing. Direct checks do not depend on that feed. No scans are scheduled.
