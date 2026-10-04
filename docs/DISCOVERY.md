# Released plugins and manual checks

The publisher enumerates all public repositories owned by `cchatterton`, without relying on a name prefix. For each stable published release it inspects ZIP assets and selects a single WordPress plugin with the controller's exact author (case-insensitive). Owner plus released package authorship is the trust boundary. If present, Update URI must match that repository. New matching releases require no registry entry. Drafts, prereleases, non-plugin packages and other authors are excluded. Forks/archived repositories need an explicit include exception.

Run `python3 scripts/publish-catalogue.py` explicitly after releases, review its result, then commit/push catalogue.json. `--check` verifies without writing. The generated feed remains an aggregate verified snapshot, so sites need one bounded request and no GitHub credentials. There is no scheduled publishing scan. Until publication, a new release will not appear in a site check.

The main package file supplies name, version, description, requirements and domain policy. Packages must have one safe top-level directory, one identifiable main plugin file and a ZIP named for the directory. Nonstandard/ambiguous identities require a reviewed exception. Verification checks version/tag consistency, archive paths, duplicate members, compressed/expanded size budgets and SHA-256. Identity collisions and changes to previously published identity pins fail publication. A previously published plugin disappearing unexpectedly also fails publication instead of silently dropping it. All entries must verify before the feed is atomically replaced.

## Exceptions

`<controller>/data/registry.json` is an exceptions map. Existing full entries are retained for audited legacy callback/identity handling, reviewed readiness, nonstandard package paths and controller bootstrap identity. They are not a list which ordinary new plugins must join. Newly discovered plugins default to beta; reviewed readiness can be overridden. The publisher never adds legacy callback hashes automatically.

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

Full exceptions can pin `id`, `owner`, `repo`, `file`, `slug`, `asset`, and `author`, with `author_header` for reviewed alternate author text. `id` may be a stable alias for a repository, but existing published IDs/package identities cannot be silently renamed. Supersession withdraws the old package; it never overwrites an installed plugin with a different package. Exceptions cannot change the configured owner or mix the two brands. Domain overrides are published to the feed; installed header fallback remains available before a successful check. Public release assets remain public: domain policy is availability control, not authentication.

## WordPress behaviour

Check for updates (page or recognised row) refreshes available capabilities and all installed same-brand update status together. Ordinary page loads, details, update transient filters, activation, old cron callbacks and generic force-check URLs make zero controller discovery HTTP requests. A one-time upgrade migration clears the obsolete controller event on the network main site and ignores saved scheduling preferences. No background checker is registered. Recent-success cooldown is 60 seconds; concurrent checks and failure retry deadlines are respected.

Successful feed replacement removes withdrawn capabilities and only their controller-owned native update metadata. HTTP/schema/identity failures keep the last successful snapshot. Brand state, locks, permissions, nonce checks, domain restrictions and package verification remain separate. Existing third-party and unknown legacy updaters are outside this controller's guarantee; legacy code suppression remains limited to audited bundled hashes.

## Validation

Run `python3 tests/discovery.py` for offline publisher tests. On disposable WordPress with both controllers active, run `wp eval-file tests/manual-discovery.php` and the existing integration/security/UI/domain tests. Never run mutating tests on customer sites.
