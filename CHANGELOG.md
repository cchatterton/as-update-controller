# Changelog

## 0.2.0 - 2026-09-26

- Split installed and available versions into separate columns; link available versions to release notes and add a GitHub column.
- Remove per-row checks, update-management text, setup notices, persistent success/history panels and settings recovery/status sections.
- Simplify catalogue cards by removing repeated brand banners and author labels.
- Add modal checking feedback and animated, per-plugin update progress with concise completion results and expandable failures.
- Refresh lists in place; retain interruption recovery and compact access to unresolved results.
- Add keyboard focus handling, reduced-motion styling, disabled selection explanations and selection-aware update controls.
- Preserve PHP 7.4 compatibility, separate brand catalogues and network-free metadata rendering.

## 0.1.1 - 2026-09-26

- Support PHP 7.4 to match the minimum PHP version for WordPress 7.0.
- Replace PHP 8-only union return declarations with equivalent PHPDoc types without changing updater behaviour.
- Align the client installation contract with PHP 7.4 compatibility.

## 0.1.0 - 2026-09-26

- Add a AlphaSys-only verified plugin catalogue with Installed, Catalogue and Settings views.
- Add background/manual discovery with durable metadata, atomic locking and retry backoff.
- Integrate native WordPress updates, inactive plugins, checksummed packages and resumable batches.
- Add audited legacy-updater suppression and migration status without replacing feature plugins on activation.
- Publish the version 1 client bootstrap contract and controller recovery instructions.
