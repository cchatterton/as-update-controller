<?php
if (!defined('ABSPATH')) { exit; }
function asuc_bundled_registry(): array {
    static $registry;
    if ($registry === null) { $registry = json_decode((string) file_get_contents(ASUC_DIR . 'data/registry.json'), true) ?: []; }
    return array_filter($registry, static function ($entry) {
        if (!is_array($entry) || !empty($entry['exclude']) || !empty($entry['superseded_by'])) { return false; }
        foreach (['id', 'owner', 'repo', 'file', 'slug', 'asset', 'author'] as $key) { if (!is_string($entry[$key] ?? null)) { return false; } }
        return true;
    });
}
/** Verified released packages may add branded identities; executable legacy trust stays bundled. */
function asuc_registry(): array {
    $bundled = asuc_bundled_registry();
    $registry = asuc_catalogue() ? [] : $bundled;
    foreach (asuc_catalogue()['plugins'] ?? [] as $id => $entry) {
        $legacy = $bundled[$id]['legacy'] ?? [];
        $legacy_identity = $bundled[$id]['legacy_identity'] ?? [];
        $registry[$id] = array_merge($bundled[$id] ?? [], $entry);
        $registry[$id]['legacy'] = $legacy;
        $registry[$id]['legacy_identity'] = $legacy_identity;
    }
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
    if (($GLOBALS['asuc_clients'][$entry['file']] ?? '') === ($entry['repo'] ?? '')) { return true; }
    $uri = rtrim((string) ($plugin['UpdateURI'] ?? ''), '/');
    if ($uri !== '') { return $uri === 'https://github.com/' . $entry['owner'] . '/' . $entry['repo']; }
    $trusted = asuc_registry()[$entry['id'] ?? ''] ?? $entry;
    $author = strtolower(trim(wp_strip_all_tags((string) ($plugin['Author'] ?? ''))));
    foreach ((array) ($trusted['legacy_identity'] ?? []) as $legacy) {
        $legacy_author = strtolower(trim(wp_strip_all_tags((string) ($legacy['author'] ?? ''))));
        $max_version = (string) ($legacy['max_version'] ?? '');
        if ($legacy_author && hash_equals($legacy_author, $author) && $max_version && version_compare((string) ($plugin['Version'] ?? '0'), $max_version, '<=')) { return true; }
    }
    return in_array($author, array_map('strtolower', [$entry['author'], $entry['author_header'] ?? $entry['author']]), true);
}
function asuc_package(array $entry): string {
    return 'https://github.com/' . $entry['owner'] . '/' . $entry['repo'] . '/releases/download/' . rawurlencode($entry['tag']) . '/' . rawurlencode($entry['asset']);
}
function asuc_release_url(array $entry): string { return 'https://github.com/' . $entry['owner'] . '/' . $entry['repo'] . '/releases/tag/' . rawurlencode($entry['tag']); }
function asuc_decode_catalogue_response(string $body) {
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) { return null; }
    if (($decoded['schema'] ?? 0) === 1) { return $decoded; }
    if (($decoded['encoding'] ?? '') !== 'base64' || !is_string($decoded['content'] ?? null)) { return null; }
    $content = base64_decode(preg_replace('/\s+/', '', $decoded['content']), true);
    if (!is_string($content)) { return null; }
    $catalogue = json_decode($content, true);
    return is_array($catalogue) ? $catalogue : null;
}
/** @return array|WP_Error */
function asuc_validate_catalogue($candidate) {
    if (!is_array($candidate) || ($candidate['schema'] ?? 0) !== 1 || !is_array($candidate['plugins'] ?? null) || count($candidate['plugins']) > 200 || !is_string($candidate['published_at'] ?? null) || strtotime($candidate['published_at']) === false) {
        return new WP_Error('catalogue_schema', 'The catalogue format is not supported.');
    }
    $registry = array_merge(asuc_bundled_registry(), asuc_registry()); $result = []; $seen = []; $files = [];
    foreach ($candidate['plugins'] as $entry) {
        if (!is_array($entry) || !is_string($entry['id'] ?? null) || isset($seen[$entry['id']])) { return new WP_Error('catalogue_entry', 'The catalogue contains duplicate or invalid entries.'); }
        $id = $entry['id']; $seen[$id] = true;
        if (($entry['owner'] ?? '') !== 'cchatterton' || ($entry['author'] ?? '') !== 'AlphaSys') { return new WP_Error('catalogue_brand', 'A catalogue identity belongs to another publisher or brand.'); }
        foreach (['owner', 'repo', 'file', 'slug', 'asset', 'author'] as $key) {
            if (!is_string($entry[$key] ?? null) || (isset($registry[$id]) && $entry[$key] !== $registry[$id][$key])) { return new WP_Error('catalogue_identity', 'Invalid or changed plugin identity.'); }
        }
        if (!isset($registry[$id])) {
            foreach (['id', 'repo', 'slug'] as $key) {
                if (!is_string($entry[$key] ?? null) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $entry[$key])) { return new WP_Error('catalogue_identity', 'Invalid plugin identity.'); }
            }
            if (!is_string($entry['file'] ?? null) || !preg_match('/^' . preg_quote($entry['slug'], '/') . '\/[a-zA-Z0-9_-]+\.php$/D', $entry['file']) || ($entry['asset'] ?? '') !== $entry['slug'] . '.zip' || isset($files[$entry['file']])) { return new WP_Error('catalogue_identity', 'Unsafe or duplicate plugin package identity.'); }
        }
        if (isset($files[$entry['file']])) { return new WP_Error('catalogue_identity', 'Duplicate plugin file.'); }
        foreach ($registry as $known_id => $known) {
            if ($known_id !== $id && ($known['file'] === $entry['file'] || $known['slug'] === $entry['slug'] || $known['repo'] === $entry['repo'])) { return new WP_Error('catalogue_identity', 'A new entry conflicts with an existing plugin identity.'); }
        }
        $files[$entry['file']] = true;
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
        $entry['allowed_domains'] = $entry['allowed_domains'] ?? [];
        $entry['include_subdomains'] = $entry['include_subdomains'] ?? false;
        if (!asuc_valid_domain_policy($entry)) { return new WP_Error('catalogue_domains', 'Invalid plugin domain metadata.'); }
        $entry['name'] = sanitize_text_field((string) ($registry[$id]['name'] ?? $entry['name'] ?? $id));
        $entry['description'] = sanitize_text_field((string) ($entry['description'] ?? $registry[$id]['description'] ?? ''));
        $entry['body'] = sanitize_textarea_field((string) ($entry['body'] ?? ''));
        unset($entry['legacy'], $entry['legacy_identity']); // Legacy code and identity trust is bundled, never accepted remotely.
        $registry[$id] = $entry;
        $result[$id] = $entry;
    }
    if (!$result) { return new WP_Error('catalogue_empty', 'No recognised releases were found.'); }
    return ['published_at' => $candidate['published_at'], 'plugins' => $result];
}
/** One public JSON download per explicit check, independent of repository count. */
function asuc_refresh(bool $manual = true, bool $force = false) {
    if (!$manual || !asuc_authorised() || (defined('DOING_CRON') && DOING_CRON)) { return new WP_Error('manual_only', 'Use an authorised Check for updates action.'); }
    $lock = asuc_lock('discovery', 60);
    if (!$lock) { return new WP_Error('check_running', 'A catalogue check is already running.'); }
    $state = (array) asuc_get('check'); $now = time();
    $state = array_merge($state, ['last_attempt'=>$now, 'job_id'=>wp_generate_uuid4(), 'status'=>'running', 'retry_at'=>0]);
    asuc_put('check', $state);
    try {
        $url = add_query_arg('check', $state['job_id'], ASUC_CATALOGUE_URL);
        $response = wp_safe_remote_get($url, ['timeout'=>10, 'redirection'=>0, 'limit_response_size'=>1048576, 'headers'=>['Accept'=>'application/json', 'Cache-Control'=>'no-cache', 'User-Agent'=>'AS-Update-Controller/'.ASUC_VERSION]]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) { throw new RuntimeException('The published catalogue could not be downloaded'.($code ? ' (HTTP '.$code.')' : '').'. Previous results are retained.'); }
        $candidate = asuc_decode_catalogue_response((string) wp_remote_retrieve_body($response));
        if (!is_array($candidate) || ($candidate['schema'] ?? 0) !== 1 || !is_array($candidate['plugins'] ?? null) || !is_string($candidate['published_at'] ?? null)) { throw new RuntimeException('The published catalogue is invalid. Previous results are retained.'); }
        // Inspect only this controller first; defer all other entries when it needs updating.
        $controllers = array_values(array_filter($candidate['plugins'], static function($entry) { return is_array($entry) && ($entry['id'] ?? '') === 'as-update-controller'; }));
        if (count($controllers) !== 1) { throw new RuntimeException('The catalogue must contain exactly one controller release. Previous results are retained.'); }
        $own = asuc_validate_catalogue(['schema'=>1, 'published_at'=>$candidate['published_at'], 'plugins'=>$controllers]);
        if (is_wp_error($own)) { throw new RuntimeException($own->get_error_message()); }
        $controller = $own['plugins']['as-update-controller'];
        $installed = asuc_plugins()['as-update-controller/as-update-controller.php']['Version'] ?? ASUC_VERSION;
        $needs_controller = version_compare($controller['version'], $installed, '>');
        $validated = $needs_controller ? $own : asuc_validate_catalogue($candidate);
        if (is_wp_error($validated)) { throw new RuntimeException($validated->get_error_message()); }
        $cached = $validated;
        if ($needs_controller) { $cached['plugins'] = array_replace(asuc_catalogue()['plugins'] ?? [], $own['plugins']); }
        ghc_v3_store_snapshot($validated['plugins'], 'AlphaSys', !$needs_controller);
        asuc_store_catalogue($cached);
        ghc_v3_sync_local();
        $message = $needs_controller ? $controller['name'].' '.$controller['version'].' is available (installed '.$installed.'). Update the controller first, then click Check for updates again. Other plugins were not refreshed.' : 'Available plugins and installed update status refreshed from the published catalogue.';
        $state = array_merge($state, ['status'=>$needs_controller ? 'controller_update' : 'success', 'error'=>'', 'message'=>$message, 'failures'=>0, 'catalogue_published_at'=>$candidate['published_at']]);
        if (!$needs_controller) { $state['last_success'] = $now; }
        asuc_put('check', $state);
        return ['id'=>$state['job_id'], 'status'=>$needs_controller ? 'controller_update' : 'complete', 'done'=>count($validated['plugins']), 'total'=>count($validated['plugins']), 'message'=>$message];
    } catch (Throwable $error) {
        $state = array_merge($state, ['status'=>'failed', 'error'=>$error->getMessage(), 'retry_at'=>0]);
        asuc_put('check', $state);
        return new WP_Error('catalogue_check', $error->getMessage());
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

function asuc_store_catalogue(array $validated): void {
        $transient = get_site_transient('update_plugins');
        // Remove only our withdrawn identities; leave other providers untouched.
        foreach (asuc_catalogue()['plugins'] ?? [] as $id => $old) {
            if (isset($validated['plugins'][$id]) || !is_object($transient)) { continue; }
            foreach (['response', 'no_update'] as $bucket) {
                $item = $transient->{$bucket}[$old['file']] ?? null;
                if (is_object($item) && ($item->id ?? '') === 'https://github.com/' . $old['owner'] . '/' . $old['repo']) { unset($transient->{$bucket}[$old['file']]); }
            }
        }
        asuc_put('catalogue', $validated);
        set_site_transient('update_plugins', asuc_project_updates($transient));
}
