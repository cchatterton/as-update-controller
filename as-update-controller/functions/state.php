<?php
if (!defined('ABSPATH')) { exit; }
function asuc_available(): bool {
    if (!is_multisite()) { return true; }
    $active = get_site_option('active_sitewide_plugins', []);
    return isset($active[plugin_basename(ASUC_FILE)]);
}
function asuc_get(string $key, $default = []) { return get_site_option('asuc_' . $key, $default); }
function asuc_put(string $key, $value): void { update_site_option('asuc_' . $key, $value); }
function asuc_settings(): array { return array_merge(['mode' => 'scheduled', 'hours' => 6], (array) asuc_get('settings')); }
function asuc_url(string $tab = 'installed'): string { return add_query_arg(['page' => 'asuc', 'tab' => $tab], network_admin_url('plugins.php')); }
function asuc_authorised(string $cap = 'update_plugins'): bool {
    return current_user_can($cap) && (!is_multisite() || current_user_can('manage_network_plugins'));
}
function asuc_activate(bool $network_wide = false): void {
    if (is_multisite() && !$network_wide) { wp_die(esc_html__('Network activate the controller to manage shared plugin files.', 'as-update-controller')); }
    asuc_put('setup_pending', true);
    asuc_schedule();
}
function asuc_deactivate(): void {
    asuc_on_main(static function () { wp_clear_scheduled_hook('asuc_scheduled_check'); });
}
function asuc_on_main(callable $callback) {
    $switch = is_multisite() && get_current_blog_id() !== get_main_site_id();
    if ($switch) { switch_to_blog(get_main_site_id()); }
    try { return $callback(); } finally { if ($switch) { restore_current_blog(); } }
}
function asuc_schedule(): void {
    asuc_on_main(static function () {
        wp_clear_scheduled_hook('asuc_scheduled_check');
        $settings = asuc_settings();
        if ($settings['mode'] === 'scheduled') {
            $at = max(time() + 60, (int) (asuc_get('check')['next_check'] ?? (time() + 300)));
            wp_schedule_single_event($at, 'asuc_scheduled_check');
        }
    });
}
function asuc_scheduled_check(): void {
    if (asuc_settings()['mode'] !== 'scheduled') { return; }
    asuc_refresh(false);
    asuc_schedule();
}
function asuc_refresh_on_native_forced_check(): void {
    if (empty($_GET['force-check']) || !asuc_authorised()) { return; }
    asuc_refresh(true, true);
    asuc_schedule();
}
/**
 * Unique option_name provides atomic acquisition; compare-and-delete protects a replacement owner.
 * @return string|false
 */
function asuc_lock(string $name, int $ttl = 120) {
    return asuc_on_main(static function () use ($name, $ttl) {
        global $wpdb;
        $key = 'asuc_lock_' . $name;
        $old = get_option($key);
        if (is_array($old) && ($old['expires'] ?? 0) < time()) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, maybe_serialize($old)));
            wp_cache_delete($key, 'options');
        }
        $token = wp_generate_uuid4();
        return add_option($key, ['token' => $token, 'expires' => time() + $ttl], '', false) ? $token : false;
    });
}
function asuc_unlock(string $name, string $token): void {
    asuc_on_main(static function () use ($name, $token) {
        global $wpdb;
        $key = 'asuc_lock_' . $name;
        $old = get_option($key);
        if (is_array($old) && hash_equals((string) $old['token'], $token)) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, maybe_serialize($old)));
            wp_cache_delete($key, 'options');
        }
    });
}
