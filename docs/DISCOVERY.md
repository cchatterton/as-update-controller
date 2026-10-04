# Released plugins and manual checks

## Fast repeat checks (0.7.1)

Authorship and identity are established once and stored with the verified version in durable network-scoped WordPress site options. Known repositories first use GitHub’s canonical HTTPS `/releases/latest` redirect to compare the stable tag. No REST API call or ZIP inspection is needed for an unchanged verified version. A new tag downloads only the expected package and verifies its headers, identity, policy and SHA-256 before advertising it. Signed delivery URLs are never persisted. Tags and published assets must be immutable; use Settings → Recheck all repositories to audit replacement assets under an existing tag.

The repository listing still runs on a normal explicit check, after known plugins. Other-author and non-plugin results are durably remembered for 24 hours and skipped without release lookups or per-repository browser steps. Exception changes invalidate learned ignores; the full recheck bypasses them. New repository identities still need published-package inspection. API limits defer this new-discovery portion while preserving results and allowing known-plugin checks to finish.

Up to five transitions share one browser request. A two-second loop budget is checked between operations, so one in-flight bounded HTTP/ZIP operation may finish after that budget. This reduces WordPress bootstrap overhead without adding a worker, cron, polling or page-load discovery.

## Initial discovery and package inspection

An explicit Check for updates scan enumerates all public repositories owned by `cchatterton`, without relying on a name prefix. For each stable published release it inspects ZIP assets and selects a single WordPress plugin with the controller's exact author (case-insensitive). Owner plus released package authorship is the trust boundary. If present, Update URI must match that repository. New matching releases require no registry entry. Drafts, prereleases, non-plugin packages and other authors are excluded. Forks/archived repositories need an explicit include exception.

No feed publication is required for 0.7.0+ controllers. The browser advances a durable scan in bounded batches only after an authorised click. A normal page visit never starts or resumes it. Native row checks use a nonce-checked action and a one-time continuation into the progress view. Without JavaScript, each Check action advances one bounded batch and reports progress. Closing the page stops further requests; the next explicit check resumes the saved cursor (up to one day old).

Installed known plugins are checked first, followed by the remaining known library. The public owner list is then paginated (100 repositories per page, up to 1,000) for new discovery. Each API response is limited to 1 MiB/8 seconds. Known-version requests use HEAD with redirects disabled and accept only the exact trusted repository tag URL. Each release ZIP is limited to 64 MiB compressed, 256 MiB expanded and 25 seconds across up to four trusted HTTPS delivery hops. PHP ZipArchive is required. ZIP bytes are inspected without extraction or execution. API metadata caches for 60 seconds; package inspections cache for one day keyed by release/asset identity, modification time, digest and exception rules.

GitHub may rate-limit anonymous checks, particularly on shared hosting. The scan records a retry deadline and retains verified results. Click again after that deadline; no timer retries for you. Optional server-side `ASUC_GITHUB_TOKEN` increases the applicable API quota. Use a public-metadata read-only token in wp-config.php, never in source control. Authentication is restricted to api.github.com with redirects disabled. See [GitHub rate limits](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api).

`scripts/publish-catalogue.py` remains an explicit release/audit tool and supplies the compatibility feed for older controllers. Its `--check` mode validates without writing.

The main package file supplies name, version, description, requirements and domain policy. Packages must have one safe top-level directory, one identifiable main plugin file and a ZIP named for the directory. Nonstandard/ambiguous identities require a reviewed exception. Verification checks version/tag consistency, archive paths, duplicate members, compressed/expanded size budgets and SHA-256. Identity collisions and changes to existing identity pins are rejected. Each verified package immediately refreshes library and installed update metadata. Missing, invalid or unvisited packages retain their previous entries and the scan reports incomplete results. Explicit exclude/superseded rules withdraw entries and only their owned native update notices.

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

## WordPress behaviour

Check for updates (page or recognised row) refreshes available capabilities and all installed same-brand update status together. Ordinary page loads, details, update transient filters, activation, old cron callbacks and generic force-check URLs make zero controller discovery HTTP requests. A one-time upgrade migration clears the obsolete controller event on the network main site and ignores saved scheduling preferences. No background checker is registered. Recent-success cooldown is 60 seconds; concurrent checks and failure retry deadlines are respected.

Explicit exclusions remove capabilities and only their controller-owned native update metadata. HTTP/schema/identity failures keep previous verified entries; incomplete scans never advance the last-success timestamp. Brand state, locks, permissions, nonce checks, domain restrictions and package verification remain separate. Existing third-party and unknown legacy updaters are outside this controller's guarantee; legacy code suppression remains limited to audited bundled hashes.

## Validation

Run `python3 tests/discovery.py` for offline publisher tests. On disposable WordPress with both controllers active, run `wp eval-file tests/manual-discovery.php` and the existing integration/security/UI/domain tests. Never run mutating tests on customer sites.
