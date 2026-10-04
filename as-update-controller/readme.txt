=== AS Update Controller ===
Contributors:
Tags: updates, plugins, catalogue, alphasys
Requires at least: 6.5
Tested up to: 7.1.2
Stable tag: 0.9.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A cached catalogue, manual update checks and guided installation for AlphaSys plugins.

== Description ==

Manage verified AlphaSys-authored plugins from cchatterton's GitHub repositories. Techn plugins are managed separately by TN Update Controller. Both controllers can run together.

Plugins > AlphaSys Plugins provides Installed, Catalogue and Settings tabs. Each check downloads one public catalogue JSON file and updates the shared local github-cchatterton table. No GitHub API or token is required. Ordinary page rendering makes no update-metadata requests, even when the cache is empty. Installations and updates use WordPress's native upgrader and verify package checksums.

Checks are strictly manual. Each check verifies this controller first. If its update is available, only that update is listed and the check stops. Update the controller, then check again. When current, the check refreshes available plugins and installed update status together. Old controller schedules are removed on upgrade. Discovery does not install updates or change WordPress auto-update settings.

Reviewed legacy updater files are held inactive while this controller runs. This is a compatibility bridge, not a claim that all plugin repositories have been migrated. Unknown legacy implementations and site-level forced refresh code require review.

On multisite, network activate this controller and use Network Admin. Inactive recognised plugins can receive updates without being activated.

== Installation ==

1. Upload as-update-controller.zip through Plugins > Add Plugin > Upload Plugin.
2. Activate (network activate on multisite).
3. Open Plugins > AlphaSys Plugins and check the catalogue.
4. Review installed/available versions and choose Update selected plugins when ready.

Installing or activating the controller does not automatically update other plugins. Batch operations preserve active/inactive state. Test migration on staging and verify recovery arrangements before production rollout.

If filesystem credentials are required, use the native WordPress update/upload screen. A failed or interrupted batch can be reviewed and resumed from Installed. Use a verified controller ZIP for manual recovery if the controller cannot run.

== Frequently Asked Questions ==

= Does this require the controller for normal plugin functionality? =
No. Feature plugins continue to operate without it. Legacy updater suppression only applies while the controller is active.

= Why is a GitHub repository absent? =
The published catalogue includes verified public stable same-brand releases. Every plugin release must update and publish its catalogue entry. New plugins need no manual registry registration, but will not appear until that publication succeeds.

= Are checks immediate? =
Each click downloads one public JSON file with a freshness query. No controller cooldown applies. The controller entry is evaluated first; if newer, update it and check again. Otherwise all entries are processed from that same file.

== External services ==

GitHub hosts the public catalogue and release packages. An explicit check makes one HTTPS GET to raw.githubusercontent.com, regardless of plugin count. It sends no GitHub token or site inventory. GitHub receives the server IP and normal connection metadata. Explicit installation/update downloads the selected release ZIP from github.com and approved release-asset hosts. Nothing is checked automatically.

Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

== Changelog ==

= 0.9.1 =
* Close successful update checks automatically and focus the refreshed Updates available tab.
* Keep failed or incomplete checks visible for review.

= 0.9.0 =
* Restore one public JSON catalogue request per manual check; remove WordPress-side repository scans and token requirements.
* Keep controller-first evaluation and shared local version/readiness records.
* Require catalogue publication after every plugin release; add targeted release publishing.

= 0.8.1 =
* Check this controller first; stop and list its update before checking other repositories.
* Continue to other plugins only when the controller is current.

= 0.8.0 =
* Share github-cchatterton rows with author, released/local versions, installed state and alpha/beta.
* Every click checks fresh API data, with no cooldown or stored backoff.
* Batch authenticated checks and reuse unchanged package metadata across controllers.

= 0.7.1 =
* Remember verified authors and ignore other-author/non-plugin repositories for 24 hours.
* Check known stable release versions without per-repository API calls; unchanged releases need no ZIP.
* Keep known-plugin checks working when API limits defer new-repository discovery.
* Batch short steps to reduce WordPress request overhead; add explicit full revalidation.

= 0.7.0 =
* Scan GitHub directly on Check for updates for both new plugins and installed releases.
* Verify released ZIP identities and checksums in bounded, resumable steps.
* Preserve results on interruptions and respect GitHub retry deadlines; no background checks.

= 0.6.0 =
* Discover released same-brand plugins by default; keep registry entries for exceptions.
* Make update discovery strictly manual and remove old schedules and force-check triggers.
* Refresh available plugins and installed updates together, preserving retry and identity safeguards.

= 0.5.7 =
* Clarify active catalogue cards by showing the installed plugin version and the latest catalogue version separately.

= 0.5.6 =
* Read the catalogue through GitHub's contents API and decode the verified catalogue payload so manual checks are not blocked by stale raw-file caches.

= 0.5.5 =
* Refresh the AlphaSys catalogue during WordPress native forced update checks so newly published plugin releases appear in the standard update flow.

= 0.5.4 =
* Recognise audited pre-controller WP Pattern Import installs so they can be updated into the AlphaSys-managed package instead of being shown as an identity conflict.
* Treat active feature-plugin client registration as a valid local identity signal when it matches the approved repository.

= 0.5.3 =
* Add a cache-busting parameter to forced manual catalogue checks so newly published catalogue entries are visible immediately through GitHub raw content.

= 0.5.2 =
* Force an immediate remote catalogue refresh when an authorised user clicks Check for updates, while still respecting in-progress checks and remote retry backoff.
* Keep newly published catalogue items discoverable without requiring a controller package release.
* Identify catalogue requests with the AS Update Controller User-Agent.

= 0.5.1 =
* Bundle WP Pattern Import in the approved AlphaSys registry so refreshed catalogues and installed controller packages recognise the production plugin identity.
* Republish the verified catalogue with WP Pattern Import 0.13.0.

= 0.5.0 =
* Read domain restrictions from plugin release headers via the verified catalogue. Show all catalogue plugins on localhost. Accept new approved same-brand catalogue entries without controller releases; keep executable legacy trust bundled.

= 0.4.5 =
* Support exact localhost allowlist entries alongside domain/subdomain restrictions. Restrict Transcript Themes to alphasys.com.au, its subdomains and localhost.

= 0.4.4 =
* Remove the redundant Update discovery heading and introductory text from Settings.

= 0.4.3 =
* Promote the controller to reviewed Alpha status and remove its Beta chip.


= 0.4.2 =
* Remove the catalogue heading/search toolbar.
* Remove redundant status strips from catalogue cards; group headings convey lifecycle state.


= 0.4.1 =
* Group cards as Active, Installed, Available and Beta, with state-specific actions.
* Refresh through authenticated AJAX so state changes do not reuse cached page HTML.


= 0.4.0 =
* Show only available updates on the first tab. Add catalogue activation, deactivation and deletion.
* Enforce domain availability, network permissions and deletion protection across sites.
* Move smaller Beta chips to the bottom-right of cards.


= 0.3.0 =
* Group the catalogue into Active, Available and Beta plugins.
* Keep active beta plugins in Active with a visible Beta chip.
* Track readiness explicitly in catalogue metadata, with safe handling of older cached catalogues.

= 0.2.0 =
* Simplify installed plugin columns and catalogue cards; remove persistent setup, success and recovery commentary.
* Add accessible checking and update-progress dialogs with concise results, failure details and interruption recovery.

= 0.1.1 =
* Support PHP 7.4 by replacing PHP 8-only return declarations with equivalent PHPDoc types.

= 0.1.0 =
* First release: author-specific catalogue, background/manual checks, native update integration, guided batch updates and audited legacy compatibility.
