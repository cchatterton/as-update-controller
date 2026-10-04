<?php
if (!defined('ABSPATH')) { exit; }
function asuc_menu(): void {
    if (is_multisite() && !is_network_admin()) { return; }
    add_submenu_page('plugins.php', 'AlphaSys Plugins', 'AlphaSys Plugins', 'update_plugins', 'asuc', 'asuc_render_admin');
}
function asuc_settings_link(array $links): array { array_unshift($links, '<a href="' . esc_url(asuc_url()) . '">AlphaSys Plugins</a>'); return $links; }
function asuc_assets(string $hook): void {
    if ($hook !== 'plugins_page_asuc') { return; }
    wp_enqueue_style('asuc-admin', plugins_url('styles/admin.css', ASUC_FILE), [], ASUC_VERSION);
    wp_enqueue_script('asuc-admin', plugins_url('scripts/admin.js', ASUC_FILE), [], ASUC_VERSION, true);
    // Consume only the one-time continuation created by the authorised row/form action.
    $continuation = get_transient('asuc_continue_' . get_current_user_id());
    delete_transient('asuc_continue_' . get_current_user_id());
    wp_localize_script('asuc-admin', 'asucAdmin', ['continueScan' => $continuation ?: '', 'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('asuc_action'), 'names' => array_map(static fn($entry) => $entry['name'], asuc_registry())]);
}
function asuc_check_summary(): string {
    $s = asuc_get('check');
    if (($s['status'] ?? '') === 'controller_update') { return asuc_controller_update_pending() ? ($s['message'] ?? 'Update the controller first, then check again.') : 'Controller updated. Check for updates to refresh the other plugins.'; }
    if (($s['status'] ?? '') === 'partial') { return (!empty($s['known_checked']) ? 'Known plugins checked. ' : 'Check incomplete. ') . ($s['error'] ?? 'Verified results are retained. Check again to resume.'); }
    if (($s['status'] ?? '') === 'failed') { return 'Last check failed. ' . (!empty($s['last_success']) ? 'Showing results from ' . wp_date('j M Y, H:i', $s['last_success']) . '.' : 'No successful check yet.'); }
    if (($s['status'] ?? '') === 'running') { return ($s['last_attempt'] ?? 0) < time() - 60 ? 'Previous check was interrupted. Check again to recover.' : 'A catalogue check is running.'; }
    return !empty($s['last_success']) ? 'Last checked ' . wp_date('j M Y, H:i', $s['last_success']) : 'Never checked. Check the catalogue to discover available releases.';
}
function asuc_form_start(string $operation): void {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="asuc_action"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">'; wp_nonce_field('asuc_action');
}
function asuc_render_admin(): void {
    if (!asuc_authorised()) { wp_die('You cannot manage plugin updates.'); }
    $tab = sanitize_key($_GET['tab'] ?? 'installed'); if (!in_array($tab, ['installed','catalogue','settings'], true)) { $tab = 'installed'; }
    $registry = array_filter(asuc_registry(), 'asuc_domain_allowed'); $releases = asuc_catalogue()['plugins'] ?? []; $plugins = asuc_plugins();
    $installed = array_filter($registry, static fn($e) => isset($plugins[$e['file']]));
    $updates = array_filter($releases, static fn($e) => asuc_domain_allowed($e) && asuc_match($e, $plugins) && version_compare($e['version'], $plugins[$e['file']]['Version'], '>'));
    if (asuc_controller_update_pending()) { $updates = array_intersect_key($updates, ['as-update-controller'=>true]); }
    echo '<div class="wrap asuc-wrap"><h1>AlphaSys Plugins</h1><div id="asuc-view">';
    $notice = get_transient('asuc_notice_' . get_current_user_id());
    if ($notice) { delete_transient('asuc_notice_' . get_current_user_id()); }
    if ($notice && $notice['error']) { echo '<div class="notice ' . ($notice['error'] ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($notice['message']) . '</p></div>'; }
    echo '<header class="asuc-header"><span class="asuc-version" aria-label="Version ' . esc_attr(ASUC_VERSION) . '">v' . esc_html(ASUC_VERSION) . '</span><p class="asuc-eyebrow">AlphaSys / Plugin library</p><h2>Your plugins. One place.</h2><p>Discover released AlphaSys plugins from the published catalogue.</p><div class="asuc-header-bottom"><span>' . count($installed) . ' installed · ' . count($updates) . ' updates available</span><button class="button asuc-primary" data-check="">Check for updates</button></div></header>';
    echo '<div class="asuc-status"><span>' . esc_html(asuc_check_summary()) . '</span><span>' . 'Manual checks only' . '</span></div>';
    echo '<nav class="nav-tab-wrapper" aria-label="Plugin library">';
    foreach (['installed'=>'Updates available','catalogue'=>'Catalogue','settings'=>'Settings'] as $key=>$label) { echo '<a class="nav-tab ' . ($key === $tab ? 'nav-tab-active' : '') . '" href="' . esc_url(asuc_url($key)) . '">' . esc_html($label) . '</a>'; }
    echo '</nav><div id="asuc-feedback" role="status" aria-live="polite"></div>';
    $batch = asuc_get('batch');
    if (($batch['status'] ?? '') === 'running') {
        echo '<p class="asuc-resume"><button class="button" data-resume="' . esc_attr($batch['id']) . '">Resume updates</button></p>';
    } elseif (($batch['status'] ?? '') === 'partial') {
        echo '<p class="asuc-resume">Some plugins could not be updated. <button class="button-link" data-results="">View results</button></p>';
    }
    if ($tab === 'settings') { asuc_render_settings(); }
    elseif ($tab === 'catalogue') { asuc_render_catalogue($registry, $releases, $plugins); }
    else {
        echo '<div class="asuc-toolbar"><h2>Updates available</h2><button class="button button-primary" id="asuc-update-selected" disabled>Update selected plugins</button></div><div class="asuc-table-scroll"><table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" id="asuc-select-all" aria-label="Select all eligible updates"></td><th scope="col">Plugin</th><th scope="col">Installed</th><th scope="col">Available</th><th scope="col">GitHub</th></tr></thead><tbody>';
        foreach ($installed as $id => $e) {
            if (!isset($updates[$id])) { continue; }
            $release = $releases[$id] ?? null;
            $issue = !asuc_match($e, $plugins) ? 'Identity conflict: review before updating.' : ($release ? asuc_compatibility($release) : '');
            $can_update = isset($updates[$id]) && !$issue && asuc_authorised() && wp_is_file_mod_allowed('asuc');
            $reason = $issue ?: (!$release ? 'Not checked' : (!isset($updates[$id]) ? 'Up to date' : (!$can_update ? 'Updates are disabled on this site.' : '')));
            echo '<tr><th class="check-column"><input type="checkbox" name="asuc-selected" value="' . esc_attr($id) . '" aria-label="Update ' . esc_attr($e['name']) . '"' . (!$can_update ? ' disabled aria-describedby="asuc-reason-' . esc_attr($id) . '"' : '') . '></th><td><strong>' . esc_html($e['name']) . '</strong> ' . asuc_beta_badge($release ?? $e) . '<br><span class="description">' . (is_plugin_active($e['file']) || is_plugin_active_for_network($e['file']) ? 'Active' : 'Inactive') . '</span></td><td>' . esc_html($plugins[$e['file']]['Version']) . '</td><td>';
            if ($release) {
                echo '<a href="' . esc_url(asuc_release_url($release)) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr($e['name'] . ' ' . $release['version'] . ' release notes (opens in a new tab)') . '">' . esc_html($release['version']) . '</a>';
            } else { echo '—'; }
            if ($reason) { echo '<p id="asuc-reason-' . esc_attr($id) . '" class="' . ($issue ? 'asuc-warning' : 'description') . '">' . esc_html($reason) . '</p>'; }
            echo '</td><td><a href="' . esc_url('https://github.com/' . $e['owner'] . '/' . $e['repo']) . '" target="_blank" rel="noopener noreferrer">GitHub<span class="screen-reader-text"> (opens in a new tab)</span></a></td></tr>';
        }
        echo '</tbody></table></div>';
        if (!$updates) { echo '<p>No updates available in the cached catalogue.</p>'; }
    }
    echo '<noscript><p>Use the native Plugins screen to install updates. Checking remains available below without JavaScript.</p>'; asuc_form_start('check'); echo '<button class="button">Check catalogue</button></form></noscript></div>';
    asuc_render_dialog();
    echo '</div>';
}
function asuc_render_dialog(): void {
    echo '<dialog id="asuc-dialog" class="asuc-dialog" aria-labelledby="asuc-dialog-title" aria-describedby="asuc-dialog-message"><h2 id="asuc-dialog-title" tabindex="-1">Checking for updates</h2><div class="asuc-activity"><span class="spinner is-active" aria-hidden="true"></span><p id="asuc-dialog-message" role="status" aria-live="polite"></p></div><div id="asuc-progress-area" hidden><progress id="asuc-progress" max="1" value="0" aria-label="Plugins processed"></progress><p id="asuc-current"></p></div><details id="asuc-failures" hidden><summary>Failed plugins</summary><ul></ul></details><div class="asuc-dialog-actions"><button type="button" class="button" id="asuc-retry" hidden>Retry</button><button type="button" class="button button-primary" id="asuc-close" disabled>Close</button></div></dialog>';
}
function asuc_details_link(array $entry): void { echo '<a href="' . esc_url(asuc_release_url($entry)) . '" target="_blank" rel="noopener noreferrer">Release notes<span class="screen-reader-text"> (opens in a new tab)</span></a>'; }
function asuc_catalogue_version_label(array $entry, array $plugins, bool $installed): string {
    $requirements = 'WordPress ' . $entry['requires'] . '+ · PHP ' . $entry['requires_php'] . '+';
    if (!$installed || !isset($plugins[$entry['file']]['Version'])) {
        return 'Version ' . $entry['version'] . ' · ' . $requirements;
    }
    $installed_version = (string) $plugins[$entry['file']]['Version'];
    if (version_compare($entry['version'], $installed_version, '>')) {
        return 'Installed ' . $installed_version . ' · Latest ' . $entry['version'] . ' · ' . $requirements;
    }
    return 'Installed ' . $installed_version . ' · Latest ' . $entry['version'] . ' · ' . $requirements;
}
function asuc_render_catalogue(array $registry, array $releases, array $plugins): void {
    if (!$releases) { echo '<p class="asuc-intro">This library lists the plugins recognised by this controller. Refresh the catalogue to load verified releases and enable installation.</p>'; }
    $labels = ['active' => 'Active', 'installed' => 'Installed', 'available' => 'Available', 'beta' => 'Beta'];
    foreach (asuc_catalogue_groups($registry, $releases, $plugins) as $group => $entries) {
    echo '<section class="asuc-catalogue-group" data-catalogue-group="' . esc_attr($group) . '" aria-labelledby="asuc-group-' . esc_attr($group) . '"' . (!$entries ? ' hidden' : '') . '><h2 id="asuc-group-' . esc_attr($group) . '">' . esc_html($labels[$group]) . '</h2><div class="asuc-grid">';
    foreach ($entries as $id=>$identity) {
        $e = $releases[$id] ?? $identity; $has = isset($plugins[$e['file']]);
        $conflict = $has && !asuc_match($e, $plugins); $issue = $conflict ? 'Installed plugin identity needs review.' : (isset($releases[$id]) ? asuc_compatibility($e) : 'Check the catalogue to load this release.');
        echo '<article class="asuc-card" data-search="' . esc_attr(strtolower($e['name'] . ' ' . $e['description'])) . '"><div class="asuc-card-content"><h3>' . esc_html($e['name']) . '</h3><p class="asuc-card-description">' . esc_html($e['description']) . '</p><p class="description">' . (isset($releases[$id]) ? esc_html(asuc_catalogue_version_label($e, $plugins, $has)) : 'Release not checked') . '</p>';
        if ($issue) { echo '<p class="asuc-warning">' . esc_html($issue) . '</p>'; }
        echo '<div class="asuc-card-actions">';
        if (!$has) { echo '<button class="button button-primary" data-install="' . esc_attr($id) . '" data-kind="install"' . ($issue || !asuc_authorised('install_plugins') || !wp_is_file_mod_allowed('asuc') ? ' disabled' : '') . '>Install</button>'; }
        asuc_card_actions($e, $has, $conflict);
        if (isset($releases[$id])) { asuc_details_link($e); }
        echo '</div>' . asuc_beta_badge($e) . '</div></article>';
    }
    echo '</div></section>';
    }
}
function asuc_render_settings(): void {
    echo '<p>Each Check for updates downloads one published catalogue JSON file. No GitHub API token or per-repository scan is needed. If this controller has an update, only that update is listed; update it, then check again.</p>';
    echo '<h2>Manual checks only</h2><p>Checks run only when requested. There are no scheduled checks or controller waiting periods. Plugin ZIPs are downloaded only when installing or updating. The shared github-cchatterton table retains released/local versions, installation state and alpha/beta.</p>';
    echo '<p>New plugins and versions become available when their release publishes the updated catalogue.</p>';

}
