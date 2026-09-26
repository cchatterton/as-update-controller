<?php
if (!defined('ABSPATH')) { exit; }
function asuc_registry(): array {
    static $registry;
    if ($registry === null) { $registry = json_decode((string) file_get_contents(ASUC_DIR . 'data/registry.json'), true) ?: []; }
    return $registry;
}
function asuc_catalogue(): array { return (array) asuc_get('catalogue'); }
/** Readiness is catalogue metadata, separate from activation and GitHub prerelease channels. */
function asuc_is_beta(array $entry): bool {
    if (is_bool($entry['beta'] ?? null)) { return $entry['beta']; }
    return asuc_registry()[$entry['id'] ?? '']['beta'] ?? true;
}
function asuc_beta_badge(array $entry): string {
    return asuc_is_beta($entry) ? '<span class="asuc-beta">Beta</span>' : '';
}
function asuc_catalogue_groups(array $registry, array $releases, array $plugins): array {
    $groups = ['active' => [], 'installed' => [], 'available' => [], 'beta' => []];
    foreach ($registry as $id => $identity) {
        if (!asuc_domain_allowed($identity)) { continue; }
        $entry = $releases[$id] ?? $identity;
        $active = isset($plugins[$identity['file']]) && (is_multisite() ? is_plugin_active_for_network($identity['file']) : is_plugin_active($identity['file']));
        $group = $active ? 'active' : (isset($plugins[$identity['file']]) ? 'installed' : (asuc_is_beta($entry) ? 'beta' : 'available'));
        $groups[$group][$id] = $identity;
    }
    return $groups;
}
function asuc_plugins(): array {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    return get_plugins();
}
function asuc_match(array $entry, array $plugins): bool {
    if (!isset($plugins[$entry['file']])) { return false; }
    $plugin = $plugins[$entry['file']];
    $uri = rtrim((string) ($plugin['UpdateURI'] ?? ''), '/');
    if ($uri !== '') { return $uri === 'https://github.com/' . $entry['owner'] . '/' . $entry['repo']; }
    $author = strtolower(trim(wp_strip_all_tags((string) ($plugin['Author'] ?? ''))));
    return in_array($author, array_map('strtolower', [$entry['author'], $entry['author_header'] ?? $entry['author']]), true);
}
function asuc_package(array $entry): string {
    return 'https://github.com/' . $entry['owner'] . '/' . $entry['repo'] . '/releases/download/' . rawurlencode($entry['tag']) . '/' . rawurlencode($entry['asset']);
}
function asuc_release_url(array $entry): string { return 'https://github.com/' . $entry['owner'] . '/' . $entry['repo'] . '/releases/tag/' . rawurlencode($entry['tag']); }
/** @return array|WP_Error */
function asuc_validate_catalogue($candidate) {
    if (!is_array($candidate) || ($candidate['schema'] ?? 0) !== 1 || !is_array($candidate['plugins'] ?? null) || count($candidate['plugins']) > 200 || !is_string($candidate['published_at'] ?? null) || strtotime($candidate['published_at']) === false) {
        return new WP_Error('catalogue_schema', 'The catalogue format is not supported.');
    }
    $registry = asuc_registry(); $result = []; $seen = [];
    foreach ($candidate['plugins'] as $entry) {
        if (!is_array($entry) || !is_string($entry['id'] ?? null) || isset($seen[$entry['id']])) { return new WP_Error('catalogue_entry', 'The catalogue contains duplicate or invalid entries.'); }
        $id = $entry['id']; $seen[$id] = true;
        if (!isset($registry[$id])) { continue; } // A controller release approves new installation identities.
        foreach (['owner', 'repo', 'file', 'slug', 'asset', 'author'] as $key) {
            if (($entry[$key] ?? null) !== $registry[$id][$key]) { return new WP_Error('catalogue_identity', 'A catalogue plugin identity does not match the trusted registry.'); }
        }
        foreach (['version', 'requires', 'requires_php'] as $key) {
            if (!is_string($entry[$key] ?? null) || !preg_match('/^\d+\.\d+(?:\.\d+){0,2}$/D', $entry[$key])) { return new WP_Error('catalogue_version', 'A catalogue version is invalid.'); }
        }
        if (!in_array($entry['tag'] ?? '', [$entry['version'], 'v' . $entry['version'], 'V' . $entry['version']], true) || !preg_match('/^[a-f0-9]{64}$/D', (string) ($entry['sha256'] ?? '')) || !is_array($entry['dependencies'] ?? null) || !is_int($entry['controller_api'] ?? null)) {
            return new WP_Error('catalogue_package', 'A catalogue package is invalid.');
        }
        foreach ($entry['dependencies'] as $dependency) {
            if (!is_string($dependency) || !preg_match('/^[a-z0-9-]+$/D', $dependency)) { return new WP_Error('catalogue_dependencies', 'A dependency is invalid.'); }
        }
        if (array_key_exists('beta', $entry) && !is_bool($entry['beta'])) { return new WP_Error('catalogue_beta', 'A catalogue beta status is invalid.'); }
        $entry['beta'] = $entry['beta'] ?? ($registry[$id]['beta'] ?? true);
        // Availability rules are approved in controller releases, never expanded by remote metadata.
        $entry['allowed_domains'] = $registry[$id]['allowed_domains'] ?? [];
        $entry['include_subdomains'] = $registry[$id]['include_subdomains'] ?? false;
        $entry['name'] = $registry[$id]['name'];
        $entry['description'] = sanitize_text_field((string) ($entry['description'] ?? $registry[$id]['description']));
        $entry['body'] = sanitize_textarea_field((string) ($entry['body'] ?? ''));
        unset($entry['legacy']); // Legacy code trust is bundled, never accepted remotely.
        $result[$id] = $entry;
    }
    if (!$result) { return new WP_Error('catalogue_empty', 'No recognised releases were found.'); }
    return ['published_at' => $candidate['published_at'], 'plugins' => $result];
}
/** @return array|WP_Error */
function asuc_refresh(bool $manual = true) {
    $state = (array) asuc_get('check'); $now = time();
    if (($state['retry_at'] ?? 0) > $now) { return new WP_Error('backoff', 'A previous check failed. Retry after ' . gmdate('Y-m-d H:i', $state['retry_at']) . ' UTC.'); }
    if (($state['last_success'] ?? 0) > $now - 60) { return ['message' => 'The catalogue was checked less than a minute ago. Showing those results.']; }
    if (!$manual && ($state['next_check'] ?? 0) > $now) { return ['message' => 'The next scheduled check is not due.']; }
    $lock = asuc_lock('discovery', 60);
    if (!$lock) { return new WP_Error('check_running', 'A catalogue check is already running.'); }
    try {
        // Recheck after atomic acquisition: another worker may have just completed.
        $state = (array) asuc_get('check');
        if (($state['retry_at'] ?? 0) > time()) { return new WP_Error('backoff', 'The remote service is in backoff. Please retry later.'); }
        if (($state['last_success'] ?? 0) > time() - 60) { return ['message' => 'Using the recently completed catalogue check.']; }
        $state['last_attempt'] = $now; $state['job_id'] = wp_generate_uuid4(); $state['status'] = 'running'; asuc_put('check', $state);
        $response = wp_safe_remote_get(ASUC_CATALOGUE_URL, ['timeout' => 8, 'redirection' => 0, 'limit_response_size' => 1048576, 'headers' => ['Accept' => 'application/json', 'User-Agent' => 'TN-Update-Controller/' . ASUC_VERSION]]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $validated = $code === 200 ? asuc_validate_catalogue(json_decode(wp_remote_retrieve_body($response), true)) : new WP_Error('catalogue_http', 'The catalogue could not be refreshed. Previous results are preserved.');
        if (is_wp_error($validated)) {
            $failures = min(8, (int) ($state['failures'] ?? 0) + 1);
            $retry = $now + min(DAY_IN_SECONDS, 600 * (2 ** ($failures - 1))) + wp_rand(0, 60);
            if (!is_wp_error($response)) {
                $after = wp_remote_retrieve_header($response, 'retry-after');
                $reset = wp_remote_retrieve_header($response, 'x-ratelimit-reset');
                $retry = max($retry, is_numeric($after) ? $now + (int) $after : (int) strtotime((string) $after), is_numeric($reset) ? (int) $reset : 0);
            }
            $state = array_merge($state, ['status' => 'failed', 'failures' => $failures, 'http_code' => $code, 'error' => $validated->get_error_message(), 'retry_at' => $retry, 'next_check' => $retry]);
            asuc_put('check', $state);
            return $validated;
        }
        asuc_put('catalogue', $validated);
        asuc_put('check', ['status' => 'success', 'job_id' => $state['job_id'], 'last_attempt' => $now, 'last_success' => $now, 'failures' => 0, 'retry_at' => 0, 'next_check' => $now + asuc_settings()['hours'] * HOUR_IN_SECONDS + wp_rand(0, 300)]);
        $transient = get_site_transient('update_plugins');
        set_site_transient('update_plugins', asuc_project_updates($transient));
        return ['message' => 'Catalogue checked. Native WordPress update notices now reflect the available releases.'];
    } finally { asuc_unlock('discovery', $lock); }
}
function asuc_compatibility(array $entry): string {
    global $wp_version;
    if (!asuc_domain_allowed($entry)) { return 'This plugin is unavailable for this domain.'; }
    if (version_compare(PHP_VERSION, $entry['requires_php'], '<')) { return 'Requires PHP ' . $entry['requires_php']; }
    if (version_compare($wp_version, $entry['requires'], '<')) { return 'Requires WordPress ' . $entry['requires']; }
    if ($entry['controller_api'] > ASUC_API_VERSION && $entry['id'] !== 'as-update-controller') { return 'Update AlphaSys Update Controller first.'; }
    $plugins = asuc_plugins();
    foreach ($entry['dependencies'] as $slug) {
        $found = false;
        foreach ($plugins as $file => $plugin) {
            if (dirname($file) === $slug && (is_plugin_active($file) || is_plugin_active_for_network($file))) { $found = true; break; }
        }
        if (!$found) { return 'Requires active plugin: ' . $slug; }
    }
    return '';
}
