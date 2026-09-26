# PHP 7.4 compatibility correction

The ten migrated Techn plugins now require WordPress 7.0+ and PHP 7.4+, matching WordPress 7.0’s PHP minimum. TN Scroll Depth remains excluded. TN Update Controller 0.1.2 and AS Update Controller 0.1.1 now support PHP 7.4; their existing WordPress 6.5 minimum is unchanged.

The controllers’ PHP 8-only union return declarations were replaced with equivalent PHPDoc declarations. Feature-plugin bootstrap guards now permit the PHP 7.4-compatible controller. Settings, catalogue ownership and the controller’s network-free page-rendering design are unchanged.

## Corrected releases

| Plugin | Release | Minimum PHP |
|---|---|---|
| Help Guides (Wiki-style) | [1.2.3](https://github.com/cchatterton/help-guides/releases/tag/v1.2.3) | 7.4 |
| Menubot | [1.10.2](https://github.com/cchatterton/menubot/releases/tag/v1.10.2) | 7.4 |
| Persona26 | [0.7.6](https://github.com/cchatterton/persona26/releases/tag/v0.7.6) | 7.4 |
| TN Authenticator | [1.6.2](https://github.com/cchatterton/tn-authenticator/releases/tag/v1.6.2) | 7.4 |
| TN Content Planner | [0.3.34](https://github.com/cchatterton/tn-content-planner/releases/tag/v0.3.34) | 7.4 |
| TN Environments | [1.10.2](https://github.com/cchatterton/tn-environments/releases/tag/v1.10.2) | 7.4 |
| TN Pallet | [0.1.14](https://github.com/cchatterton/tn-pallet/releases/tag/v0.1.14) | 7.4 |
| TN QR Codes | [1.5.2](https://github.com/cchatterton/tn-qrcodes/releases/tag/v1.5.2) | 7.4 |
| TN User Management | [1.32.2](https://github.com/cchatterton/tn-user-management/releases/tag/v1.32.2) | 7.4 |
| WP Migrate - Release Management | [0.11.5](https://github.com/cchatterton/tn-wp-migrate-code-diff/releases/tag/v0.11.5) | 7.4 |
| tn-update-controller | [0.1.2](https://github.com/cchatterton/tn-update-controller/releases/tag/v0.1.2) | 7.4 |
| as-update-controller | [0.1.1](https://github.com/cchatterton/as-update-controller/releases/tag/v0.1.1) | 7.4 |

## Validation

- All 125 distributed PHP files passed PHP 7.4 syntax checks.
- WordPress 7.0 integration runs passed on PHP 7.4.30, PHP 8.1.23 (the fleet version), and PHP 8.5.7: both controllers, Persona26 and Content Planner.
- Ten-plugin bulk installation passed on WordPress 7.0/PHP 7.4 using both verified candidate packages and the actual published GitHub ZIPs. Saved settings and activation state were preserved, and completed batches remained idempotent.
- Both controllers updated from their previous published releases to the corrected releases on WordPress 7.0/PHP 8.1.23 using live GitHub downloads; activation and controller settings were preserved.
- Controller integration tests cover both brand catalogues, cold/warm cache reads with zero metadata HTTP, rate-limit backoff, locks, capability checks and scheduling.
- All eight WP Migrate standalone suites passed on PHP 7.4; QR image generation passed on PHP 7.4 and PHP 8.1.23.

These checks establish the exercised compatibility paths; every feature and optional third-party integration has not been exhaustively tested. WordPress 6.5 controller compatibility was not separately rerun. No customer site or hosting PHP version was changed.

The development standard now distinguishes minimum compatibility from recommended hosting versions, requires testing the minimum and deployed fleet version, and requires package/header/catalogue consistency. Existing tags and release assets are preserved; corrections use new patch releases.

Automatic catalogue publishing still needs GitHub workflow permissions; catalogues are verified and published manually for this release.
