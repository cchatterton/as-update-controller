# Beta catalogue and AS Local CSS migration

Controller release 0.3.0 groups cards into Active, Available (non-beta and not active), then Beta (not active). Empty groups are hidden. Active beta plugins appear only in Active and retain a Beta chip. The installed list also shows the chip. Search spans groups and hides empty search results.

Readiness is explicit boolean `beta` metadata in the trusted registry and published catalogue. It is independent of activation and GitHub prerelease channels. Missing fields in older cached catalogues fall back to the bundled registry; malformed values are rejected. Promotion requires reviewed standards compliance, not just a version or API-header change.

Exactly the ten previously migrated Techn plugins are non-beta. AS Local CSS is the sole non-beta AlphaSys entry. All remaining entries, including both controllers, are beta.

AS Local CSS 0.4.3 removes its independent updater, integrates with AS Update Controller API 1, aligns AlphaSys metadata and declares WordPress 7.0 / PHP 7.4. It adds the GPL-3.0 licence while preserving upstream attribution and third-party notices. Its basename, snippet storage, generated upload files and feature/editor code are preserved.

## Validation

- PHP 7.4 syntax checks and controller integration/rendering checks.
- Readiness tests cover explicit classifications, old catalogue fallback, malformed values, activation precedence, uniqueness, ordered groups and approved promotions.
- Browser checks on WordPress 7.0/PHP 8.1.23 cover grouped cards, Beta chips, search/no-results, activation of a beta plugin and inactive/non-beta placement.
- AS Local CSS 0.4.2 to 0.4.3 candidate upgrade through the controller preserved activation, snippets, settings, tree options and generated CSS files.
- Fresh-request CSS output stayed identical; block-editor CSS loading and authored CSS remained intact. Repeated metadata reads made zero HTTP calls.

No customer site has been changed. Optional third-party integration combinations were not exhaustively tested. Catalogue automation still needs GitHub workflow permissions; these catalogues are manually verified and published.

## Published-release verification

The actual GitHub AS Local CSS 0.4.3 ZIP upgraded 0.4.2 successfully and preserved activation, settings, snippets and generated files. Fresh-request rendering/editor checks passed on PHP 7.4.30. Both controllers upgraded from 0.2.0 to their published 0.3.0 ZIPs, preserving activation and state. The Techn catalogue was pinned to the verified published commit for this last test because the main-branch CDN cache briefly returned the previous document; production cache behaviour was not changed. All three installed release packages loaded successfully on PHP 7.4.30.
