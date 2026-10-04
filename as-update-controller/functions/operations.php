<?php
if (!defined('ABSPATH')) { exit; }
/** @return array|WP_Error */
function asuc_start_batch(array $ids, string $kind) {
    if (!in_array($kind, ['update', 'install'], true) || !asuc_authorised($kind === 'install' ? 'install_plugins' : 'update_plugins')) { return new WP_Error('permission', 'You cannot perform this operation.'); }
    if (!wp_is_file_mod_allowed('asuc')) { return new WP_Error('files_disabled', 'File changes are disabled on this site.'); }
    if (!$ids || count($ids) > 100) { return new WP_Error('selection', 'Select at least one plugin.'); }
    $lock = asuc_lock('batch', 300); if (!$lock) { return new WP_Error('busy', 'An installation step is already running.'); }
    try {
        $old = asuc_get('batch');
        if (($old['status'] ?? '') === 'running' && ($old['updated_at'] ?? 0) > time() - HOUR_IN_SECONDS) { return new WP_Error('batch_exists', 'Resume the existing batch before starting another.'); }
        $catalogue = asuc_catalogue()['plugins'] ?? []; $installed = asuc_plugins(); $items = [];
        foreach (array_unique($ids) as $id) {
            if (!isset($catalogue[$id])) { return new WP_Error('unknown', 'Check the catalogue before selecting plugins.'); }
            $e = $catalogue[$id]; $problem = asuc_compatibility($e);
            if ($problem) { return new WP_Error('incompatible', $e['name'] . ': ' . $problem); }
            if ($kind === 'update' && (!asuc_match($e, $installed) || !version_compare($e['version'], $installed[$e['file']]['Version'], '>'))) { return new WP_Error('not_update', $e['name'] . ' does not have an eligible update.'); }
            if ($kind === 'install' && (isset($installed[$e['file']]) || is_dir(WP_PLUGIN_DIR . '/' . dirname($e['file'])))) { return new WP_Error('exists', 'That plugin directory already exists. Use its update action.'); }
            $items[] = ['id' => $id, 'version' => $e['version'], 'sha256' => $e['sha256'], 'status' => 'pending'];
        }
        // Keep the controller last: remaining requests should never require old controller code.
        usort($items, static fn($a, $b) => ($a['id'] === 'as-update-controller') <=> ($b['id'] === 'as-update-controller'));
        $job = ['id' => wp_generate_uuid4(), 'owner' => get_current_user_id(), 'kind' => $kind, 'items' => $items, 'status' => 'running', 'updated_at' => time()];
        asuc_put('batch', $job); return $job;
    } finally { asuc_unlock('batch', $lock); }
}
/** @return array|WP_Error */
function asuc_step_batch(string $id) {
    $lock = asuc_lock('batch', 300); if (!$lock) { return new WP_Error('busy', 'An installation step is already running.'); }
    try {
        $job = asuc_get('batch');
        if (($job['id'] ?? '') !== $id || !asuc_authorised(($job['kind'] ?? '') === 'install' ? 'install_plugins' : 'update_plugins')) { return new WP_Error('batch_missing', 'This batch is unavailable.'); }
        if (!wp_is_file_mod_allowed('asuc')) { return new WP_Error('files_disabled', 'File changes are disabled.'); }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if (get_filesystem_method() !== 'direct') { return new WP_Error('filesystem_credentials', 'This site requires filesystem credentials. Use WordPress Plugins > Installed Plugins for native updates or Upload Plugin for installation.'); }
        foreach ($job['items'] as $index => $item) {
            if (!in_array($item['status'], ['pending', 'working'], true)) { continue; }
            $entry = asuc_catalogue()['plugins'][$item['id']] ?? null;
            $error = (!$entry || $entry['version'] !== $item['version'] || $entry['sha256'] !== $item['sha256']) ? 'The catalogue changed. Start a new selection for this plugin.' : asuc_compatibility($entry);
            $plugins = asuc_plugins();
            $file = $entry['file'] ?? '';
            // An interrupted request may have finished installing before it recorded the result.
            if (!$error && asuc_match($entry, $plugins) && version_compare($plugins[$file]['Version'], $item['version'], '>=')) {
                $job['items'][$index]['status'] = 'success'; $job['items'][$index]['message'] = 'Installed version verified.';
            } elseif (!$error) {
                if ($job['kind'] === 'update' && !asuc_match($entry, $plugins)) { $error = 'Installed plugin identity changed.'; }
                if ($job['kind'] === 'install' && is_dir(WP_PLUGIN_DIR . '/' . dirname($file))) { $error = 'The destination already exists.'; }
                if (!$error) {
                    $job['items'][$index]['status'] = 'working'; $job['updated_at'] = time(); asuc_put('batch', $job);
                    $skin = new Automatic_Upgrader_Skin(); $upgrader = new Plugin_Upgrader($skin);
                    if ($job['kind'] === 'update') {
                        set_site_transient('update_plugins', asuc_project_updates(get_site_transient('update_plugins')));
                        $result = $upgrader->bulk_upgrade([$file], ['clear_update_cache' => false]);
                        $result = is_array($result) ? ($result[$file] ?? false) : $result;
                    } else { $result = $upgrader->install(asuc_package($entry), ['clear_update_cache' => false]); }
                    wp_clean_plugins_cache(false); $after = asuc_plugins();
                    if (is_wp_error($result) || !$result || !isset($after[$file]) || $after[$file]['Version'] !== $item['version']) { $error = is_wp_error($result) ? $result->get_error_message() : 'WordPress could not verify the installed version. See the native Plugins screen for recovery.'; }
                    else { $job['items'][$index]['status'] = 'success'; $job['items'][$index]['message'] = 'Installed ' . $item['version'] . '. ' . asuc_migration_status($entry); }
                }
            }
            if ($error) { $job['items'][$index]['status'] = 'failed'; $job['items'][$index]['message'] = sanitize_text_field($error); }
            break;
        }
        $pending = array_filter($job['items'], static fn($i) => in_array($i['status'], ['pending', 'working'], true));
        $job['status'] = $pending ? 'running' : (array_filter($job['items'], static fn($i) => $i['status'] === 'failed') ? 'partial' : 'complete');
        $job['updated_at'] = time(); asuc_put('batch', $job);
        return $job;
    } finally { asuc_unlock('batch', $lock); }
}
/** @return array|WP_Error */
function asuc_dispatch(string $op, array $input) {
    if (!asuc_authorised()) { return new WP_Error('permission', 'You cannot manage plugin updates.'); }
    if ($op === 'view') {
        $previous = $_GET['tab'] ?? null;
        $_GET['tab'] = sanitize_key($input['tab'] ?? 'installed');
        ob_start();
        try { asuc_render_admin(); $html = ob_get_contents(); }
        finally { ob_end_clean(); if ($previous === null) { unset($_GET['tab']); } else { $_GET['tab'] = $previous; } }
        return ['html' => $html];
    }
    if ($op === 'plugin_action') { return asuc_plugin_action(sanitize_text_field($input['plugin_id'] ?? ''), sanitize_key($input['plugin_action'] ?? '')); }
    if ($op === 'check') {
        $id = sanitize_text_field($input['plugin_id'] ?? '');
        if ($id && !isset(asuc_registry()[$id])) { return new WP_Error('unknown', 'Unknown plugin.'); }
        return asuc_refresh(true, true);
    }
    if ($op === 'start') { return asuc_start_batch(array_map('sanitize_text_field', (array) ($input['ids'] ?? [])), sanitize_key($input['kind'] ?? 'update')); }
    if ($op === 'step') { return asuc_step_batch(sanitize_text_field($input['job'] ?? '')); }
    if ($op === 'status') { return ['batch' => asuc_get('batch'), 'check' => asuc_get('check')]; }
    if ($op === 'dismiss') { asuc_put('setup_pending', false); return ['message' => 'Setup reminder dismissed.']; }
    if ($op === 'settings') {
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) { return new WP_Error('permission', 'You cannot change these settings.'); }
        asuc_put('settings', ['mode' => 'manual']);
        asuc_schedule(); return ['message' => 'Update checks are manual only.'];
    }
    return new WP_Error('operation', 'Unknown action.');
}
function asuc_handle_ajax(): void {
    check_ajax_referer('asuc_action', 'nonce');
    $result = asuc_dispatch(sanitize_key($_POST['operation'] ?? ''), wp_unslash($_POST));
    if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()], 400); }
    wp_send_json_success($result);
}
function asuc_handle_form(): void {
    check_admin_referer('asuc_action');
    $input = wp_unslash($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
    $op = sanitize_key($input['operation'] ?? '');
    if (!in_array($op, ['check', 'settings', 'dismiss'], true)) { wp_die('Unsupported form action.'); }
    $result = asuc_dispatch($op, $input);
    set_transient('asuc_notice_' . get_current_user_id(), ['error' => is_wp_error($result), 'message' => is_wp_error($result) ? $result->get_error_message() : ($result['message'] ?? 'Action completed.')], 120);
    wp_safe_redirect(asuc_url($op === 'settings' ? 'settings' : 'installed')); exit;
}
