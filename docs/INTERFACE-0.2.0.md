# Controller interface release 0.2.0

TN Update Controller and AS Update Controller share the revised interface while retaining separate catalogues and PHP 7.4 compatibility.

- Installed plugins have separate Installed and Available columns, linked release versions, a GitHub column and selection-aware update controls. Blocked rows show disabled checkboxes with a reason.
- Catalogue cards start with the plugin name. Repeated TN/AS banners and author labels are removed.
- Setup/readiness notices, persistent success/history panels, the legacy-management footer and settings Check status/Recovery sections are removed.
- One page-level Check for updates action opens a spinner dialog. A successful check refreshes the view in place and closes the dialog.
- Install/update dialogs show the current plugin and real completed-item progress. Results are concise, failures expand on request, and incomplete jobs remain resumable.
- Dialogs use native modal focus containment, restore focus when closed and respect reduced-motion preferences.

## Validation

Tested on a disposable WordPress 7.0 site with PHP 8.1.23 in the browser. PHP 7.4 lint and controller integration/rendering regression checks also pass.

Browser checks covered checking and retry after simulated connection failure; two real GitHub plugin updates; interrupted-batch resume; partial failure details; retrying the failed plugin; AlphaSys catalogue installation without automatic activation; and catalogue/settings layout. Completed operations preserve settings and activation behaviour. Rendering all three tabs for both brands performs zero metadata HTTP.

Progress measures completed plugins rather than download bytes. No customer website was changed. The catalogue publishing workflow still requires GitHub workflow permissions; release catalogues are verified and published manually.
