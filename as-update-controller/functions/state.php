<?php
if (!defined('ABSPATH')) { exit; }
function asuc_available(): bool {
    if (!is_multisite()) { return true; }
    $active = get_site_option('active_sitewide_plugins', []);
    return isset($active[plugin_basename(ASUC_FILE)]);
}
function asuc_get(string $key, $default = []) { return get_site_option('asuc_' . $key, $default); }
function asuc_put(string $key, $value): void { update_site_option('asuc_' . $key, $value); }
function asuc_settings(): array { return ['mode' => 'manual']; }
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
/** Compatibility shim: old callers can only remove obsolete jobs. */
function asuc_schedule(): void {
    asuc_on_main(static function () { wp_clear_scheduled_hook('asuc_scheduled_check'); });
}
function asuc_migrate_manual_checks(): void {
    if (asuc_get('manual_checks_version', 0) === 1) { return; }
    asuc_schedule();
    asuc_put('settings', ['mode' => 'manual']);
    $state = (array) asuc_get('check'); unset($state['next_check']); asuc_put('check', $state);
    asuc_put('manual_checks_version', 1);
}
/** Obsolete entry points deliberately perform no discovery. */
function asuc_scheduled_check(): void { asuc_schedule(); }
function asuc_refresh_on_native_forced_check(): void {}
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
