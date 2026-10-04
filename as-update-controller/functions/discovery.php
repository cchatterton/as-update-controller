<?php
if (!defined('ABSPATH')) { exit; }
/** Both controllers use the same owner index, with separate branded catalogue projections. */
function asuc_begin_scan(bool $full = false) {
    if (!asuc_authorised() || (defined('DOING_CRON') && DOING_CRON)) { return new WP_Error('manual_only', 'Use an authorised Check for updates action.'); }
    $scan = ghc_v2_begin('as-update-controller', ASUC_VERSION);
    if (!is_wp_error($scan)) { asuc_put('check', ['status'=>'running','last_attempt'=>time(),'job_id'=>$scan['id']]); }
    return $scan;
}
function asuc_scan_step(string $id) {
    if (!asuc_authorised() || (defined('DOING_CRON') && DOING_CRON)) { return new WP_Error('manual_only', 'Use an authorised Check for updates action.'); }
    $scan = ghc_v2_step($id);
    if (!is_wp_error($scan)) {
        $state = (array) asuc_get('check');
        $state['status'] = $scan['status'] === 'complete' ? 'success' : $scan['status'];
        $state['error'] = in_array($scan['status'], ['partial','failed'], true) ? $scan['message'] : '';
        $state['retry_at'] = 0;
        $state['message'] = $scan['message'];
        if ($scan['status'] === 'complete') { $state['last_success'] = time(); }
        asuc_put('check', $state);
    }
    return $scan;
}
/** Local database reads only. Preserve the last verified package when its next inspection fails. */
function asuc_import_shared(): void {
    $catalogue = asuc_catalogue(); $plugins = $catalogue['plugins'] ?? []; $before = $plugins;
    foreach (ghc_v2_rows() as $row) {
        foreach ($plugins as $id=>$entry) {
            if ($entry['repo'] === $row['repo'] && ($row['status'] === 'excluded' || ($row['status'] === 'verified' && $row['brand'] !== 'AlphaSys') || $row['status'] === 'not_plugin')) { unset($plugins[$id]); }
        }
        if ($row['brand'] !== 'AlphaSys' || !in_array($row['status'], ['verified','error'], true)) { continue; }
        $entry = json_decode($row['package_data'] ?? '', true);
        if (!is_array($entry)) { continue; }
        $entry['beta'] = $row['alpha_beta'] === 'beta';
        $candidate = $plugins; $candidate[$entry['id']] = $entry;
        $validated = asuc_validate_catalogue(['schema'=>1,'published_at'=>gmdate('c'),'plugins'=>array_values($candidate)]);
        if (is_wp_error($validated)) { throw new RuntimeException($row['repo'].': '.$validated->get_error_message()); }
        $plugins = $validated['plugins'];
    }
    if ($plugins !== $before) { asuc_store_catalogue(['published_at'=>gmdate('c'),'plugins'=>$plugins]); }
}

/** Only the verified controller update is listed until that installed version is current. */
function asuc_controller_update_pending(): bool {
    if ((asuc_get('check')['status'] ?? '') !== 'controller_update') { return false; }
    $entry = asuc_catalogue()['plugins']['as-update-controller'] ?? null;
    $installed = asuc_plugins()['as-update-controller/as-update-controller.php']['Version'] ?? ASUC_VERSION;
    return $entry && version_compare($entry['version'], $installed, '>');
}
