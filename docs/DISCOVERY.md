# Published catalogue checks (0.9.0)

## WordPress: one file per click

The matching controller downloads its public `catalogue.json` from `raw.githubusercontent.com/cchatterton/as-update-controller/main/catalogue.json`. Every explicit click uses a distinct freshness query and no-cache request header. The only discovery HTTP request is this one JSON download (ten seconds, 1 MiB, redirects disabled). No GitHub API, token, owner listing, release lookup, GraphQL, ZIP inspection, timed ignore, cooldown or background checker is used on WordPress.

Validate the controller entry first. A newer controller produces `controller_update`, projects only its update, keeps other cached records untouched, and ends the check. The Updates available screen lists only that controller until it is installed. A current or newer installed controller proceeds to validate the remaining catalogue entries from the same JSON. Missing/duplicate/invalid controller entries fail the check; failure preserves the prior snapshot. Package download, SHA-256 and source verification remain part of explicit installation/update operations.

The shared `github-cchatterton` SQL table is reused for local author, canonical brand, released/local versions, local installed state and alpha/beta. Author brand catalogues remain separate. New entries and explicit withdrawals come from the published feed. The local table never initiates a scan or overrides a newer feed. Local state refreshes during checks and native plugin actions. Historical non-plugin rows can remain without network activity. Use separate databases for independent WordPress installations.

Old paused repository scans are retired on upgrade; an explicit click runs the catalogue check. Old schedule callbacks, page rendering, details and native update-transient hooks perform zero controller discovery HTTP.

## Publishing: required for every release

The release publisher verifies public cchatterton ownership plus exact AlphaSys authorship, or an explicit reviewed exception. Normal matching plugins need no registry registration. It inspects the published stable ZIP without executing plugin code, verifies path/version/identity/checksum/requirements/domain metadata, and preserves identity pins. Published tags/assets are immutable. Drafts, prereleases, private repositories, other authors and unreviewed forks/archives are excluded. The controller itself must be present.

After verifying a stable release, run `python3 scripts/publish-catalogue.py --repo <repo> --expect-version <version>` in an up-to-date controller checkout. Commit/push the changed catalogue, then verify that the public raw JSON contains that exact version, tag, basename, asset and SHA-256. If this fails, report release delivery as incomplete. This step applies to every feature-plugin and controller release; it is not optional compatibility maintenance. See the release procedure in README and the shared WordPress GitHub update standard.

Targeted publication downloads/inspects only the released plugin and retains the other verified entries. The default publisher performs a full explicit reconciliation. `--check` never publishes. Preserve concurrent releases by updating and regenerating after a push conflict, never force-pushing an outdated snapshot. No publishing cron is added.

## Exceptions

`as-update-controller/data/registry.json` contains reviewed exceptions only: ambiguous authors, explicit include/exclude, aliases/supersession, legacy identity/callback hashes, readiness and domain overrides. New identities default to beta; `beta: false` maps to alpha, independently of GitHub prerelease state. Domain rules control availability, not authentication. Discovery never expands executable callback trust.

Full exceptions pin id/owner/repo/file/slug/asset/author. Sparse exceptions can specify `author_header`, `include`, `exclude`, `superseded_by`, `beta`, `allowed_domains` and `include_subdomains`, with a documented reason. Existing identity pins and brand separation remain enforced.

## Validation

`python3 tests/discovery.py` covers publisher ownership, paths, identity pins, targeted publication and immutable assets. On disposable WordPress with both controllers active, run `wp eval-file tests/manual-discovery.php`, `tests/controller-first.php`, integration, security and admin UI tests. Do not run mutating tests on customer sites.
