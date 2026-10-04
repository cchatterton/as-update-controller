<?php
if (!defined('ABSPATH')) { exit; }
/** The manual operation completes in one public catalogue request. */
function asuc_begin_scan(bool $full = false) { return asuc_refresh(); }
function asuc_scan_step(string $id) {
    return new WP_Error('scan_retired', 'Repository scanning has been replaced by the published catalogue. Click Check for updates again.');
}

/** Only the verified controller update is listed until that installed version is current. */
function asuc_controller_update_pending(): bool {
    if ((asuc_get('check')['status'] ?? '') !== 'controller_update') { return false; }
    $entry = asuc_catalogue()['plugins']['as-update-controller'] ?? null;
    $installed = asuc_plugins()['as-update-controller/as-update-controller.php']['Version'] ?? ASUC_VERSION;
    return $entry && version_compare($entry['version'], $installed, '>');
}
