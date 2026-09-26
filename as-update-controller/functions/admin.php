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
    wp_localize_script('asuc-admin', 'asucAdmin', ['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('asuc_action')]);
}
function asuc_setup_notice(): void {
    if (!asuc_authorised() || !asuc_get('setup_pending', false) || (is_multisite() && !is_network_admin())) { return; }
    echo '<div class="notice notice-info"><p><strong>AlphaSys Update Controller is ready.</strong> Review your installed plugins and check the catalogue. Activation does not update feature plugins. <a href="' . esc_url(asuc_url()) . '">Open setup</a></p></div>';
}
function asuc_check_summary(): string {
    $s = asuc_get('check');
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
    $registry = asuc_registry(); $releases = asuc_catalogue()['plugins'] ?? []; $plugins = asuc_plugins();
    $installed = array_filter($registry, static fn($e) => isset($plugins[$e['file']]));
    $updates = array_filter($releases, static fn($e) => asuc_match($e, $plugins) && version_compare($e['version'], $plugins[$e['file']]['Version'], '>'));
    echo '<div class="wrap asuc-wrap"><h1>AlphaSys Plugins</h1>';
    $notice = get_transient('asuc_notice_' . get_current_user_id());
    if ($notice) { delete_transient('asuc_notice_' . get_current_user_id()); echo '<div class="notice ' . ($notice['error'] ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($notice['message']) . '</p></div>'; }
    echo '<header class="asuc-header"><span class="asuc-version" aria-label="Version ' . esc_attr(ASUC_VERSION) . '">v' . esc_html(ASUC_VERSION) . '</span><p class="asuc-eyebrow">AlphaSys / Plugin library</p><h2>Your plugins. One place.</h2><p>Discover, check and update your AlphaSys plugins.</p><div class="asuc-header-bottom"><span>' . count($installed) . ' installed · ' . count($updates) . ' updates available</span><button class="button asuc-primary" data-check="">Check all registered plugins</button></div></header>';
    echo '<div class="asuc-status"><span>' . esc_html(asuc_check_summary()) . '</span><span>' . (asuc_settings()['mode'] === 'manual' ? 'Manual checks only' : 'Background checks every ' . (int) asuc_settings()['hours'] . ' hours') . '</span></div>';
    echo '<nav class="nav-tab-wrapper" aria-label="Plugin library">';
    foreach (['installed'=>'Installed','catalogue'=>'Catalogue','settings'=>'Settings'] as $key=>$label) { echo '<a class="nav-tab ' . ($key === $tab ? 'nav-tab-active' : '') . '" href="' . esc_url(asuc_url($key)) . '">' . esc_html($label) . '</a>'; }
    echo '</nav><div id="asuc-feedback" role="status" aria-live="polite"></div>';
    $batch = asuc_get('batch');
    if (!empty($batch['id'])) {
        echo '<section class="asuc-batch"><h2>Latest operation</h2><p>' . esc_html(ucfirst($batch['status'])) . '. Each plugin has its own result; a batch is not an all-or-nothing transaction.</p><ul>';
        foreach ($batch['items'] as $item) { echo '<li>' . esc_html(($registry[$item['id']]['name'] ?? $item['id']) . ': ' . $item['status'] . '. ' . ($item['message'] ?? '')) . '</li>'; }
        echo '</ul>';
        if ($batch['status'] === 'running') { echo '<button class="button" data-resume="' . esc_attr($batch['id']) . '">Resume selected updates</button>'; }
        echo '</section>';
    }
    if ($tab === 'settings') { asuc_render_settings(); }
    elseif ($tab === 'catalogue') { asuc_render_catalogue($registry, $releases, $plugins); }
    else {
        if (asuc_get('setup_pending', false)) {
            echo '<section class="asuc-intro"><h2>Set up managed updates</h2><p>Check the catalogue, review the available versions, then select the plugins you want to update. Existing plugins stay active or inactive as they are. Test on staging and confirm a backup is available before a production migration.</p><p>Some releases still contain their old updater. The status below distinguishes an audited compatibility bridge from a completed migration. Unrecognised updater or site-level forced-refresh code needs separate review.</p>';
            asuc_form_start('dismiss'); echo '<button class="button-link">Dismiss setup reminder</button></form></section>';
        }
        echo '<div class="asuc-toolbar"><h2>Installed plugins</h2><button class="button button-primary" id="asuc-update-selected">Update selected plugins</button></div><div class="asuc-table-scroll"><table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" id="asuc-select-all" aria-label="Select all eligible updates"></td><th scope="col">Plugin</th><th scope="col">Installed / available</th><th scope="col">Update management</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach ($installed as $id => $e) {
            $release = $releases[$id] ?? null; $match = asuc_match($e, $plugins); $issue = !$match ? 'Identity conflict: review before updating.' : ($release ? asuc_compatibility($release) : '');
            $can_update = isset($updates[$id]) && !$issue;
            echo '<tr><th class="check-column">';
            if ($can_update) { echo '<input type="checkbox" name="asuc-selected" value="' . esc_attr($id) . '" aria-label="Update ' . esc_attr($e['name']) . '">'; }
            echo '</th><td><strong>' . esc_html($e['name']) . '</strong><br><span class="description">' . esc_html($e['author']) . ' · ' . (is_plugin_active($e['file']) || is_plugin_active_for_network($e['file']) ? 'Active' : 'Inactive') . '</span></td><td>' . esc_html($plugins[$e['file']]['Version'] . ' → ' . ($release['version'] ?? 'Not checked')) . ($issue ? '<p class="asuc-warning">' . esc_html($issue) . '</p>' : '') . '</td><td>' . esc_html(asuc_migration_status($e)) . '</td><td><button class="button" data-check="' . esc_attr($id) . '">Check for updates</button> ';
            if ($release) { asuc_details_link($release); }
            echo '</td></tr>';
        }
        echo '</tbody></table></div><p class="description">A legacy compatibility bridge suppresses reviewed updater callbacks while this controller is active. A new controller-integrated release is still needed to finish migration.</p>';
    }
    echo '<noscript><p>Use the native Plugins screen to install updates. Checking remains available below without JavaScript.</p>'; asuc_form_start('check'); echo '<button class="button">Check catalogue</button></form></noscript></div>';
}
function asuc_details_link(array $entry): void { echo '<a href="' . esc_url(asuc_release_url($entry)) . '" target="_blank" rel="noopener noreferrer">Release notes<span class="screen-reader-text"> (opens in a new tab)</span></a>'; }
function asuc_render_catalogue(array $registry, array $releases, array $plugins): void {
    echo '<div class="asuc-toolbar"><h2>Plugin catalogue</h2><label>Find a plugin <input type="search" id="asuc-search" placeholder="Search name or description"></label><button class="button" data-check="">Refresh catalogue</button></div>';
    if (!$releases) { echo '<p class="asuc-intro">This library lists the plugins recognised by this controller. Refresh the catalogue to load verified releases and enable installation.</p>'; }
    echo '<div class="asuc-grid">';
    foreach ($registry as $id=>$identity) {
        $e = $releases[$id] ?? $identity; $has = isset($plugins[$e['file']]); $active = $has && (is_plugin_active($e['file']) || is_plugin_active_for_network($e['file']));
        $conflict = $has && !asuc_match($e, $plugins); $issue = $conflict ? 'Installed plugin identity needs review.' : (isset($releases[$id]) ? asuc_compatibility($e) : 'Check the catalogue to load this release.');
        $update = $has && isset($releases[$id]) && version_compare($e['version'], $plugins[$e['file']]['Version'], '>');
        echo '<article class="asuc-card" data-search="' . esc_attr(strtolower($e['name'] . ' ' . $e['description'] . ' ' . $e['author'])) . '"><div class="asuc-card-mark" aria-hidden="true">' . ($e['author'] === 'AlphaSys' ? 'AS' : 'AS') . '</div><div class="asuc-card-content"><p class="asuc-card-author">' . esc_html($e['author']) . '</p><h3>' . esc_html($e['name']) . '</h3><p class="asuc-card-description">' . esc_html($e['description']) . '</p><p class="asuc-card-state">' . ($update ? 'Update available' : ($active ? 'Active' : ($has ? 'Installed · inactive' : 'Not installed'))) . '</p><p class="description">' . (isset($releases[$id]) ? esc_html('Version ' . $e['version'] . ' · WordPress ' . $e['requires'] . '+ · PHP ' . $e['requires_php'] . '+') : 'Release not checked') . '</p>';
        if ($issue) { echo '<p class="asuc-warning">' . esc_html($issue) . '</p>'; }
        echo '<div class="asuc-card-actions">';
        if (!$issue && (!$has || $update)) { echo '<button class="button button-primary" data-install="' . esc_attr($id) . '" data-kind="' . ($has ? 'update' : 'install') . '"' . (!asuc_authorised($has ? 'update_plugins' : 'install_plugins') ? ' disabled' : '') . '>' . ($has ? 'Update' : 'Install') . '</button>'; }
        elseif (!$issue && $has && !$active && current_user_can('activate_plugin', $e['file'])) {
            $activate = wp_nonce_url(add_query_arg(['action'=>'activate','plugin'=>$e['file'],'networkwide'=>is_multisite() ? 1 : 0], network_admin_url('plugins.php')), 'activate-plugin_' . $e['file']);
            echo '<a class="button" href="' . esc_url($activate) . '">' . (is_multisite() ? 'Network activate' : 'Activate') . '</a>';
        } elseif (!$issue && $active) { echo '<a class="button" href="' . esc_url(network_admin_url('plugins.php')) . '">Manage plugin</a>'; }
        if (isset($releases[$id])) { asuc_details_link($e); }
        echo '</div></div></article>';
    }
    echo '</div><p id="asuc-no-results" hidden>No matching plugins.</p>';
}
function asuc_render_settings(): void {
    $settings = asuc_settings(); $check = asuc_get('check');
    asuc_form_start('settings');
    echo '<h2>Update discovery</h2><p>Checks only discover releases. They never install updates or change WordPress auto-update preferences.</p><table class="form-table"><tr><th scope="row"><label for="asuc-mode">Check mode</label></th><td><select id="asuc-mode" name="mode"><option value="scheduled"' . selected($settings['mode'], 'scheduled', false) . '>Scheduled background checks</option><option value="manual"' . selected($settings['mode'], 'manual', false) . '>Manual checks only</option></select><p class="description">Manual mode discovers new releases only when an administrator checks.</p></td></tr><tr><th scope="row"><label for="asuc-hours">Check interval</label></th><td><select id="asuc-hours" name="hours">';
    foreach ([6,12,24] as $hours) { echo '<option value="' . $hours . '"' . selected($settings['hours'], $hours, false) . '>Every ' . $hours . ' hours</option>'; }
    echo '</select></td></tr></table>'; submit_button('Save settings'); echo '</form><h2>Check status</h2><p>' . esc_html(asuc_check_summary()) . '</p>';
    $next = asuc_on_main(static fn() => wp_next_scheduled('asuc_scheduled_check'));
    echo '<p>Next scheduled check: ' . esc_html($next ? wp_date('j M Y, H:i', $next) : 'None') . '</p>';
    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) { echo '<p class="asuc-warning">Page-triggered cron is disabled. Confirm your host runs scheduled WordPress tasks. Manual checks work independently.</p>'; }
    if ($next && $next < time() - HOUR_IN_SECONDS) { echo '<p class="asuc-warning">The scheduled check is overdue. Check your host’s cron configuration or use Check now.</p>'; }
    if (!empty($check['retry_at']) && $check['retry_at'] > time()) { echo '<p>Remote retry allowed after ' . esc_html(wp_date('j M Y, H:i', $check['retry_at'])) . '.</p>'; }
    if (!empty($check['error'])) { echo '<p class="asuc-warning">' . esc_html($check['error']) . '</p>'; }
    echo '<button class="button" data-check="">Check now</button><h2>Recovery</h2><p>Use the native WordPress Plugins screen for standard updates and activation. If the controller cannot run, upload its verified release ZIP through Plugins → Add Plugin → Upload Plugin. The other plugins continue to work without this controller.</p>';
}
