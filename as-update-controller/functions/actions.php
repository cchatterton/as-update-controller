<?php
if (!defined('ABSPATH')) { exit; }
/** Validate published/header policy without any remote lookups. */
function asuc_valid_domain_policy(array $rule): bool {
    if (!is_array($rule['allowed_domains'] ?? null) || !is_bool($rule['include_subdomains'] ?? null)) { return false; }
    foreach ($rule['allowed_domains'] as $domain) {
        if (!is_string($domain) || strlen($domain) > 253 || !preg_match('/^(?:localhost|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+)$/D', $domain)) { return false; }
    }
    return true;
}
/** Restrictions come from release headers in the catalogue, or installed headers with a cold cache. */
function asuc_domain_allowed(array $entry): bool {
    $host = strtolower(rtrim((string) wp_parse_url(is_multisite() ? network_home_url('/') : home_url('/'), PHP_URL_HOST), '.'));
    if ($host === 'localhost') { return true; }
    $rule = asuc_catalogue()['plugins'][$entry['id'] ?? ''] ?? null;
    if (!$rule) {
        $bundled = asuc_bundled_registry()[$entry['id'] ?? ''] ?? [];
        if (array_key_exists('allowed_domains', $bundled)) { $rule = $bundled + ['include_subdomains' => false]; }
    }
    if (!$rule) {
        $path = WP_PLUGIN_DIR . '/' . ($entry['file'] ?? '');
        if (!is_file($path)) { return false; } // Load release metadata before advertising uninstalled plugins.
        $headers = get_file_data($path, ['domains' => 'Allowed Domains', 'subdomains' => 'Allow Subdomains']);
        $rule = ['allowed_domains' => $headers['domains'] === '' ? [] : array_map('trim', explode(',', strtolower($headers['domains']))), 'include_subdomains' => strtolower($headers['subdomains']) === 'true'];
        if (!in_array(strtolower($headers['subdomains']), ['', 'true', 'false'], true)) { return false; }
    }
    $rule += ['allowed_domains' => [], 'include_subdomains' => false];
    if (!asuc_valid_domain_policy($rule)) { return false; }
    if (!$rule['allowed_domains']) { return true; }
    foreach ($rule['allowed_domains'] as $domain) {
        if ($host === $domain || ($domain !== 'localhost' && $rule['include_subdomains'] && substr($host, -strlen('.' . $domain)) === '.' . $domain)) { return true; }
    }
    return false;
}
function asuc_network_active(array $entry): bool { return is_plugin_active_for_network($entry['file']); }
/** A network deletion must never remove a plugin still active on a member site. */
function asuc_active_anywhere(string $file): bool {
    if (is_plugin_active_for_network($file) || is_plugin_active($file)) { return true; }
    if (!is_multisite()) { return false; }
    $offset = 0;
    do {
        $ids = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset]);
        foreach ($ids as $blog_id) {
            if (in_array($file, (array) get_blog_option($blog_id, 'active_plugins', []), true)) { return true; }
        }
        $offset += 100;
    } while (count($ids) === 100);
    return false;
}
function asuc_plugin_action(string $id, string $operation) {
    $entry = asuc_registry()[$id] ?? null;
    if (!$entry || !asuc_domain_allowed($entry)) { return new WP_Error('unavailable', 'This plugin is unavailable for this domain.'); }
    if (!in_array($operation, ['activate', 'deactivate', 'delete'], true)) { return new WP_Error('operation', 'Unknown plugin action.'); }
    $file = $entry['file']; $plugins = asuc_plugins();
    if (!asuc_match($entry, $plugins)) { return new WP_Error('identity', 'The installed plugin identity could not be verified.'); }
    $cap = $operation === 'delete' ? 'delete_plugins' : ($operation === 'activate' ? 'activate_plugin' : 'deactivate_plugin');
    if (!current_user_can($cap, $file) || (is_multisite() && !current_user_can('manage_network_plugins'))) { return new WP_Error('permission', 'You cannot perform this plugin action.'); }
    if ($file === plugin_basename(ASUC_FILE)) { return new WP_Error('controller', 'Use the WordPress Plugins screen to deactivate or delete this controller.'); }
    $lock = asuc_lock('batch', 300);
    if (!$lock) { return new WP_Error('busy', 'Another plugin operation is running.'); }
    try {
        if ((asuc_get('batch')['status'] ?? '') === 'running') { return new WP_Error('busy', 'Finish the current update batch first.'); }
        if ($operation === 'activate') {
            $compatible = validate_plugin_requirements($file);
            if (is_wp_error($compatible)) { return $compatible; }
            if (class_exists('WP_Plugin_Dependencies')) {
                WP_Plugin_Dependencies::initialize();
                if (WP_Plugin_Dependencies::has_unmet_dependencies($file)) { return new WP_Error('dependencies', 'Activate the required plugins first.'); }
            }
            $result = activate_plugin($file, '', is_multisite());
            if (is_wp_error($result)) { return $result; }
        } elseif ($operation === 'deactivate') {
            if (class_exists('WP_Plugin_Dependencies')) {
                WP_Plugin_Dependencies::initialize();
                if (WP_Plugin_Dependencies::has_active_dependents($file)) { return new WP_Error('dependents', 'Deactivate dependent plugins first.'); }
            }
            deactivate_plugins($file, false, is_multisite());
        } else {
            if (!wp_is_file_mod_allowed('asuc')) { return new WP_Error('files_disabled', 'File changes are disabled on this site.'); }
            if (asuc_active_anywhere($file)) { return new WP_Error('active', 'Deactivate this plugin on every site before deleting it.'); }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            if (get_filesystem_method() !== 'direct') { return new WP_Error('filesystem', 'Use the WordPress Plugins screen to provide filesystem credentials.'); }
            $result = delete_plugins([$file]);
            if (is_wp_error($result)) { return $result; }
            if (!$result) { return new WP_Error('delete', 'WordPress could not delete this plugin.'); }
        }
        $active = is_multisite() ? is_plugin_active_for_network($file) : is_plugin_active($file);
        if (($operation === 'activate' && !$active) || ($operation === 'deactivate' && $active) || ($operation === 'delete' && file_exists(WP_PLUGIN_DIR . '/' . $file))) { return new WP_Error('verification', 'The requested plugin state could not be verified.'); }
        wp_clean_plugins_cache(false);
        return ['message' => $entry['name'] . ': ' . ['activate' => 'activated.', 'deactivate' => 'deactivated.', 'delete' => 'deleted.'][$operation]];
    } finally { asuc_unlock('batch', $lock); }
}
function asuc_card_actions(array $entry, bool $installed, bool $conflict): void {
    if (!$installed || $conflict) { return; }
    if ($entry['file'] === plugin_basename(ASUC_FILE)) {
        if (current_user_can('deactivate_plugin', $entry['file'])) {
            $url = wp_nonce_url(add_query_arg(['action' => 'deactivate', 'plugin' => $entry['file'], 'networkwide' => is_multisite() ? 1 : 0], network_admin_url('plugins.php')), 'deactivate-plugin_' . $entry['file']);
            echo '<a class="button" href="' . esc_url($url) . '">' . (is_multisite() ? 'Network Deactivate' : 'Deactivate') . '</a>';
        }
        return;
    }
    $active = is_multisite() ? is_plugin_active_for_network($entry['file']) : is_plugin_active($entry['file']);
    $operation = $active ? 'deactivate' : 'activate';
    if (current_user_can($active ? 'deactivate_plugin' : 'activate_plugin', $entry['file'])) {
        echo '<button type="button" class="button" data-plugin-action="' . esc_attr($operation) . '" data-plugin-id="' . esc_attr($entry['id']) . '">' . (is_multisite() ? 'Network ' : '') . ($active ? 'Deactivate' : 'Activate') . '</button>';
    }
    if (!$active && current_user_can('delete_plugins') && wp_is_file_mod_allowed('asuc')) {
        echo '<button type="button" class="button-link-delete" data-plugin-action="delete" data-plugin-id="' . esc_attr($entry['id']) . '">Delete</button>';
    }
}
