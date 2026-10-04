<?php
if (!defined('ABSPATH')) { exit; }
/** Public metadata only. Both brands may reuse this short transport cache, never each other's catalogue. */
function asuc_github_json(string $path) {
    if (!preg_match('#^(users/cchatterton/repos\?per_page=100&type=owner&page=\d+|repos/cchatterton/[a-zA-Z0-9_.-]+/releases/latest)$#D', $path)) { return new WP_Error('source', 'Invalid discovery source.'); }
    $cache_key = 'auc_public_github_' . hash('sha256', $path);
    $cached = get_site_transient($cache_key);
    if (is_array($cached)) { return $cached; }
    $token = defined('ASUC_GITHUB_TOKEN') ? (string) ASUC_GITHUB_TOKEN : '';
    $scope = $token === '' ? 'public' : hash('sha256', $token);
    $retry_key = 'auc_github_retry_' . $scope;
    $retry = (int) get_site_option($retry_key, 0);
    if ($retry > time()) { return new WP_Error('github_wait', 'GitHub is limiting requests. Check again after ' . gmdate('Y-m-d H:i', $retry) . ' UTC.', ['retry_at' => $retry]); }
    $headers = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'AS-Update-Controller/' . ASUC_VERSION, 'X-GitHub-Api-Version' => '2022-11-28'];
    if ($token !== '') { $headers['Authorization'] = 'Bearer ' . $token; }
    $response = wp_safe_remote_get('https://api.github.com/' . $path, ['timeout' => 8, 'redirection' => 0, 'limit_response_size' => 1048576, 'headers' => $headers]);
    $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    if ($code === 404) { $result = ['missing' => true]; set_site_transient($cache_key, $result, 60); return $result; }
    if ($code !== 200) {
        $failures = min(8, (int) get_site_option($retry_key . '_failures', 0) + 1);
        update_site_option($retry_key . '_failures', $failures);
        $retry = time() + min(DAY_IN_SECONDS, 600 * (2 ** ($failures - 1))) + wp_rand(0, 60);
        if (!is_wp_error($response)) {
            $after = wp_remote_retrieve_header($response, 'retry-after');
            $reset = wp_remote_retrieve_header($response, 'x-ratelimit-reset');
            $retry = max($retry, is_numeric($after) ? time() + (int) $after : (int) strtotime((string) $after), is_numeric($reset) ? (int) $reset : 0);
        }
        update_site_option($retry_key, $retry);
        return new WP_Error('github_wait', 'GitHub could not complete this check (HTTP ' . $code . '). Verified results are retained. Check again after ' . gmdate('Y-m-d H:i', $retry) . ' UTC.', ['retry_at' => $retry]);
    }
    update_site_option($retry_key . '_failures', 0);
    $result = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($result)) { return new WP_Error('github_json', 'GitHub returned invalid release metadata.'); }
    set_site_transient($cache_key, $result, 60);
    return $result;
}
function asuc_discovery_rules(): array {
    $rules = json_decode((string) file_get_contents(ASUC_DIR . 'data/registry.json'), true) ?: [];
    $by_repo = [];
    foreach ($rules as $key => $rule) { if (is_array($rule)) { $by_repo[$rule['repo'] ?? $key] = $rule; } }
    return $by_repo;
}
function asuc_scan_public(array $scan): array {
    return ['known_checked' => $scan['index'] >= ($scan['known_count'] ?? PHP_INT_MAX) && empty($scan['known_failed']), 'id' => $scan['id'], 'status' => $scan['status'], 'done' => (int) $scan['index'], 'total' => count($scan['repos']), 'message' => $scan['message'] ?? 'Discovering released plugins…'];
}
function asuc_begin_scan(bool $full = false) {
    if (!asuc_authorised() || (defined('DOING_CRON') && DOING_CRON)) { return new WP_Error('manual_only', 'Use an authorised Check for updates action.'); }
    if (!class_exists('ZipArchive')) { return new WP_Error('zip_support', 'Enable the PHP ZIP extension to inspect released plugins.'); }
    $lock = asuc_lock('discovery', 60); if (!$lock) { return new WP_Error('check_running', 'A check step is already running.'); }
    try {
        $scan = (array) asuc_get('scan'); $state = (array) asuc_get('check');
        if (($scan['engine'] ?? 0) === 2 && !$full && in_array($scan['status'] ?? '', ['running', 'paused'], true) && ($scan['created'] ?? 0) > time() - DAY_IN_SECONDS) {
            if (($scan['retry_at'] ?? 0) > time()) { return new WP_Error('backoff', $scan['message']); }
            $scan['status'] = 'running'; asuc_put('scan', $scan); asuc_put('check', array_merge($state, ['status' => 'running', 'last_attempt' => time(), 'retry_at' => 0])); return asuc_scan_public($scan);
        }
        if (!$full && ($state['status'] ?? '') === 'success' && ($state['last_success'] ?? 0) > time() - 60) { return ['id' => '', 'status' => 'complete', 'message' => 'Using the check completed less than a minute ago.']; }
        $known = []; $priority = []; $installed = asuc_plugins();
        foreach (array_merge(asuc_bundled_registry(), asuc_registry()) as $entry) { $known[$entry['repo']] = $entry; $priority[$entry['repo']] = isset($installed[$entry['file']]) ? 0 : 1; }
        $repos = array_keys($known); usort($repos, static function ($a, $b) use ($priority) { return ($priority[$a] <=> $priority[$b]) ?: strcasecmp($a, $b); });
        $scan = ['engine' => 2, 'full' => $full, 'known' => $known, 'listed' => false, 'known_count' => count($repos), 'id' => wp_generate_uuid4(), 'created' => time(), 'status' => 'running', 'phase' => 'release', 'page' => 1, 'repos' => $repos, 'index' => 0, 'warnings' => [], 'retry_at' => 0, 'message' => 'Checking known plugins…'];
        asuc_put('scan', $scan);
        asuc_put('check', array_merge($state, ['status' => 'running', 'last_attempt' => time(), 'job_id' => $scan['id']]));
        return asuc_scan_public($scan);
    } finally { asuc_unlock('discovery', $lock); }
}
/** Each browser-requested step does one metadata request or one bounded ZIP inspection. No worker is scheduled. */
function asuc_scan_step_once(string $id) {
    if (!asuc_authorised() || (defined('DOING_CRON') && DOING_CRON)) { return new WP_Error('manual_only', 'Use an authorised Check for updates action.'); }
    $lock = asuc_lock('discovery', 60); if (!$lock) { return new WP_Error('check_running', 'A check step is already running.'); }
    try {
        $scan = (array) asuc_get('scan');
        if (!$id || ($scan['id'] ?? '') !== $id) { return new WP_Error('scan_missing', 'This check is no longer available. Start another check.'); }
        if ($scan['status'] !== 'running') { return asuc_scan_public($scan); }
        $rules = asuc_discovery_rules();
        asuc_apply_exclusions($rules);
        $error = null;
        if ($scan['phase'] === 'repos') {
            $repos = asuc_github_json('users/cchatterton/repos?per_page=100&type=owner&page=' . (int) $scan['page']);
            if (is_wp_error($repos)) { $scan['warnings'][] = 'Known plugins checked; discovery of new repositories deferred: ' . $repos->get_error_message(); $scan['listed'] = true; $scan['phase'] = 'release'; }
            elseif (isset($repos['missing']) || (bool) array_filter($repos, static function ($repo) { return !is_array($repo) || !is_string($repo['name'] ?? null) || !is_string($repo['owner']['login'] ?? null) || !is_bool($repo['private'] ?? null) || !is_bool($repo['fork'] ?? null) || !is_bool($repo['archived'] ?? null); })) { $error = new WP_Error('repos_schema', 'The repository list is invalid.'); }
            else {
                foreach ($repos as $repo) {
                    $rule = $rules[$repo['name']] ?? [];
                    if ($repo['owner']['login'] !== 'cchatterton' || $repo['private'] || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $repo['name']) || !empty($rule['exclude']) || !empty($rule['superseded_by'])) { continue; }
                    if (($repo['fork'] || $repo['archived']) && empty($rule['include'])) { continue; }
                    if (!in_array($repo['name'], $scan['repos'], true) && ($scan['full'] || !asuc_ignored_repo($repo['name'], $rule))) { $scan['repos'][] = $repo['name']; }
                }
                if (count($repos) === 100) {
                    $scan['page']++;
                    if ($scan['page'] > 10) { $error = new WP_Error('repos_limit', 'More than 1,000 repositories require review. Previous results are retained.'); }
                } else {
                    $scan['listed'] = true;
                    $scan['phase'] = 'release';
                }
            }
        } elseif ($scan['index'] >= count($scan['repos']) && !$scan['listed']) {
            $scan['phase'] = 'repos';
        } elseif ($scan['index'] >= count($scan['repos'])) {
            $scan['status'] = $scan['warnings'] ? 'partial' : 'complete';
            $scan['message'] = $scan['warnings'] ? 'Verified releases refreshed; some packages need review: ' . implode('; ', $scan['warnings']) : 'Available plugins and installed updates refreshed directly from GitHub.';
            $state = (array) asuc_get('check');
            $state = array_merge($state, ['known_checked' => empty($scan['known_failed']), 'status' => $scan['warnings'] ? 'partial' : 'success', 'error' => $scan['warnings'] ? $scan['message'] : '', 'retry_at' => 0, 'failures' => 0]);
            if (!$scan['warnings']) { $state['last_success'] = time(); }
            asuc_put('check', $state);
        } else {
            $repo = $scan['repos'][$scan['index']]; $rule = $rules[$repo] ?? [];
            $known = $scan['known'][$repo] ?? null;
            if (!empty($rule['exclude']) || !empty($rule['superseded_by'])) { $scan['index']++; asuc_put('scan', $scan); return asuc_scan_public($scan); }
            if ($scan['phase'] === 'release') {
                $release = $known && !$scan['full'] ? asuc_known_release($repo, $known) : asuc_github_json('repos/cchatterton/' . $repo . '/releases/latest');
                if (is_wp_error($release)) { if ($known) { $scan['known_failed'] = true; } $scan['warnings'][] = $repo . ': ' . $release->get_error_message(); $scan['index']++; }
                elseif (!empty($release['unchanged'])) { $scan['index']++; }
                elseif (!empty($release['missing'])) { if (isset(asuc_catalogue()['plugins'][$rule['id'] ?? $repo])) { $scan['known_failed'] = true; $scan['warnings'][] = $repo . ': no stable release; previous verified entry retained.'; } if (!$known) { asuc_ignore_repo($repo, $rule); } $scan['index']++; }
                elseif (!is_bool($release['draft'] ?? null) || !is_bool($release['prerelease'] ?? null) || !is_string($release['tag_name'] ?? null) || !is_array($release['assets'] ?? null)) { $error = new WP_Error('release_schema', 'Invalid release metadata for ' . $repo); }
                elseif ($release['draft'] || $release['prerelease']) { $scan['index']++; }
                else {
                    $assets = array_values(array_filter($release['assets'], static function ($asset) use ($rule) { return is_array($asset) && is_string($asset['name'] ?? null) && substr($asset['name'], -4) === '.zip' && (empty($rule['asset']) || $rule['asset'] === $asset['name']); }));
                    if (count($assets) > 20) { $error = new WP_Error('assets', 'Too many ZIP assets for ' . $repo . '; add a package exception.'); }
                    else { $scan['release'] = $release; $scan['assets'] = $assets; $scan['asset_index'] = 0; $scan['matches'] = []; $scan['phase'] = 'assets'; }
                }
            } elseif ($scan['asset_index'] < count($scan['assets'])) {
                $asset = $scan['assets'][$scan['asset_index']];
                $entry = asuc_inspect_release($repo, $scan['release'], $asset, $rule);
                if (is_wp_error($entry)) {
                    if ($known) { $scan['known_failed'] = true; }
                    $scan['warnings'][] = $repo . ': ' . $entry->get_error_message();
                    $scan['matches'] = []; $scan['index']++; $scan['phase'] = 'release';
                } else {
                    if ($entry) { $scan['matches'][] = $entry; }
                    $scan['asset_index']++;
                }
            } else {
                if (count($scan['matches']) === 1) {
                    $result = asuc_accept_discovered($scan['matches'][0]);
                    if (is_wp_error($result)) { if ($known) { $scan['known_failed'] = true; } $scan['warnings'][] = $repo . ': ' . $result->get_error_message(); }
                } elseif (count($scan['matches']) > 1) { $scan['warnings'][] = $repo . ': multiple plugin ZIPs require an exception.'; }
                elseif (isset(asuc_catalogue()['plugins'][$rule['id'] ?? $repo])) { $scan['known_failed'] = true; $scan['warnings'][] = $repo . ': no matching package; previous verified entry retained.'; }
                elseif (!$known) { asuc_ignore_repo($repo, $rule); }
                $scan['index']++; $scan['phase'] = 'release';
                unset($scan['release'], $scan['assets'], $scan['matches']);
            }
            if (!$error) { $scan['message'] = 'Checked ' . $scan['index'] . ' of ' . count($scan['repos']) . ' repositories. ' . $repo; }
        }
        if ($error) {
            $scan['status'] = 'paused'; $scan['retry_at'] = $error->get_error_data()['retry_at'] ?? (time() + 600);
            $scan['message'] = $error->get_error_message();
            $state = (array) asuc_get('check'); asuc_put('check', array_merge($state, ['status' => 'partial', 'error' => $scan['message'], 'retry_at' => $scan['retry_at']]));
        }
        $scan['updated_at'] = time(); asuc_put('scan', $scan);
        return asuc_scan_public($scan);
    } finally { asuc_unlock('discovery', $lock); }
}
function asuc_release_header(string $text, string $name, string $default = ''): string {
    return preg_match('/^[ \t]*\*?[ \t]*' . preg_quote($name, '/') . ':[ \t]*([^\r\n]*)/m', $text, $match) ? trim($match[1]) : $default;
}
/** Validate released bytes without extracting or executing plugin code. */
function asuc_inspect_zip(string $path, string $repo, array $release, array $asset, array $rule) {
    if (!class_exists('ZipArchive')) { return new WP_Error('zip_support', 'The PHP ZIP extension is required for direct release inspection.'); }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { return new WP_Error('zip', 'The release ZIP could not be read.'); }
    try {
        $names = []; $mains = []; $expanded = 0;
        if ($zip->numFiles > 10000) { return new WP_Error('zip_entries', 'Archive has too many files.'); }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); $name = $stat['name']; $expanded += $stat['size'];
            $opsys = 0; $attributes = 0; $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            if (!$name || isset($names[$name]) || $name[0] === '/' || strpos($name, '\\') !== false || in_array('..', explode('/', $name), true) || (($attributes >> 16) & 0170000) === 0120000 || $expanded > 268435456) { return new WP_Error('zip_path', 'Unsafe or oversized archive.'); }
            $names[$name] = true;
            if (substr_count($name, '/') !== 1 || substr($name, -4) !== '.php') { continue; }
            if ($stat['size'] > 2097152) { return new WP_Error('zip_main', 'Oversized plugin main file.'); }
            $text = (string) $zip->getFromIndex($i, 8192);
            if (asuc_release_header($text, 'Plugin Name') && (empty($rule['file']) || $rule['file'] === $name)) { $mains[$name] = $text; }
        }
        if (!$mains) { return null; }
        if (count($mains) !== 1) { return new WP_Error('ambiguous', 'Multiple plugin main files require an exception.'); }
        $file = (string) key($mains); $text = current($mains); $slug = dirname($file);
        if (strcasecmp(asuc_release_header($text, 'Author'), $rule['author_header'] ?? 'AlphaSys') !== 0) { return null; }
        foreach ($names as $name => $_) { if (strpos($name, $slug . '/') !== 0) { return new WP_Error('root', 'Unexpected package root.'); } }
        $entry = ['id' => $rule['id'] ?? $repo, 'owner' => 'cchatterton', 'repo' => $repo, 'file' => $file, 'slug' => $slug, 'asset' => $asset['name'], 'author' => 'AlphaSys'];
        foreach (['owner', 'repo', 'file', 'slug', 'asset', 'author'] as $key) { if (isset($rule[$key]) && $rule[$key] !== $entry[$key]) { return new WP_Error('identity', 'Released package identity changed.'); } }
        $uri = asuc_release_header($text, 'Update URI');
        if ($uri && rtrim($uri, '/') !== 'https://github.com/cchatterton/' . $repo) { return new WP_Error('identity', 'Update URI differs from the release repository.'); }
        $domains = asuc_release_header($text, 'Allowed Domains'); $subdomains = strtolower(asuc_release_header($text, 'Allow Subdomains', 'false'));
        if (!in_array($subdomains, ['true', 'false'], true)) { return new WP_Error('domains', 'Invalid subdomain policy.'); }
        $api = asuc_release_header($text, 'AlphaSys Controller API', $repo === 'as-update-controller' ? '1' : '0');
        if (!preg_match('/^\d+$/D', $api)) { return new WP_Error('api', 'Invalid controller API version.'); }
        $entry += ['name' => asuc_release_header($text, 'Plugin Name'), 'description' => asuc_release_header($text, 'Description'), 'version' => asuc_release_header($text, 'Version'), 'tag' => $release['tag_name'], 'requires' => asuc_release_header($text, 'Requires at least', '6.0'), 'requires_php' => asuc_release_header($text, 'Requires PHP', '7.4'), 'dependencies' => array_values(array_filter(array_map('trim', explode(',', asuc_release_header($text, 'Requires Plugins'))))), 'controller_api' => (int) $api, 'body' => (string) ($release['body'] ?? ''), 'beta' => $rule['beta'] ?? ($repo !== 'as-update-controller'), 'sha256' => hash_file('sha256', $path), 'allowed_domains' => $rule['allowed_domains'] ?? ($domains === '' ? [] : array_map('trim', explode(',', strtolower($domains)))), 'include_subdomains' => $rule['include_subdomains'] ?? ($subdomains === 'true')];
        return $entry;
    } finally { $zip->close(); }
}
function asuc_inspect_release(string $repo, array $release, array $asset, array $rule) {
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*\.zip$/D', $asset['name']) || (empty($asset['direct']) && ($asset['size'] ?? 0) < 1) || ($asset['size'] ?? 0) > 67108864 || !preg_match('/^[vV]?\d+\.\d+(?:\.\d+){0,2}$/D', $release['tag_name'])) { return new WP_Error('asset', 'Invalid, oversized or unsupported release asset.'); }
    $key = hash('sha256', json_encode([$repo, $release['tag_name'], $asset['id'] ?? '', $asset['updated_at'] ?? '', $asset['digest'] ?? '', !empty($asset['direct']), $rule]));
    $cached = get_site_transient('asuc_package_' . $key);
    if ($cached === false && empty($asset['direct'])) {
        // Reuse 0.7.0's already verified package/other-author result while learning the durable index.
        $legacy_key = hash('sha256', json_encode([$repo, $release['tag_name'], $asset['id'] ?? '', $asset['updated_at'] ?? '', $asset['digest'] ?? '', $rule]));
        $cached = get_site_transient('asuc_package_' . $legacy_key);
    }
    if (is_array($cached)) { if (isset($cached['entry'])) { $cached['entry']['body'] = (string) ($release['body'] ?? ''); } return $cached['entry'] ?? null; }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $temp = wp_tempnam($asset['name']); if (!$temp) { return new WP_Error('temp', 'Could not create a temporary inspection file.'); }
    $url = 'https://github.com/cchatterton/' . $repo . '/releases/download/' . rawurlencode($release['tag_name']) . '/' . rawurlencode($asset['name']);
    $deadline = microtime(true) + 25;
    try {
        for ($hop = 0; $hop < 4; $hop++) {
            if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array(wp_parse_url($url, PHP_URL_HOST), ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)) { return new WP_Error('host', 'Untrusted release redirect.'); }
            $remaining = $deadline - microtime(true); if ($remaining < 1) { return new WP_Error('timeout', 'Release inspection timed out. Retry the manual check.'); }
            $response = wp_safe_remote_get($url, ['timeout' => $remaining, 'redirection' => 0, 'stream' => true, 'filename' => $temp, 'limit_response_size' => 67108864]);
            if (is_wp_error($response)) { return new WP_Error('download', 'The released ZIP could not be inspected.'); }
            $code = (int) wp_remote_retrieve_response_code($response);
            if (in_array($code, [301, 302, 303, 307, 308], true)) { $url = WP_Http::make_absolute_url(wp_remote_retrieve_header($response, 'location'), $url); continue; }
            if ($code !== 200 || filesize($temp) < 1 || filesize($temp) >= 67108864 || (empty($asset['direct']) && filesize($temp) !== $asset['size'])) { return new WP_Error('download', 'The released ZIP download was incomplete.'); }
            if (!empty($asset['digest']) && $asset['digest'] !== 'sha256:' . hash_file('sha256', $temp)) { return new WP_Error('checksum', 'The released ZIP differs from its GitHub checksum.'); }
            $entry = asuc_inspect_zip($temp, $repo, $release, $asset, $rule);
            if (!is_wp_error($entry)) { set_site_transient('asuc_package_' . $key, ['entry' => $entry], DAY_IN_SECONDS); }
            return $entry;
        }
        return new WP_Error('redirect', 'Too many release redirects.');
    } finally { @unlink($temp); }
}
/** Commit a verified result immediately, preserving unvisited/failed entries and unrelated update providers. */
function asuc_accept_discovered(array $entry) {
    $plugins = asuc_catalogue()['plugins'] ?? [];
    $old = $plugins[$entry['id']] ?? [];
    if (($old['tag'] ?? '') === $entry['tag'] && ($old['sha256'] ?? '') !== $entry['sha256']) { return new WP_Error('immutable_release', 'Published package bytes changed under the same tag. Publish a new version; previous verified metadata retained.'); }
    $plugins[$entry['id']] = $entry;
    $rules = asuc_discovery_rules();
    foreach ($plugins as $id => $known) { $rule = $rules[$known['repo']] ?? []; if (!empty($rule['exclude']) || !empty($rule['superseded_by'])) { unset($plugins[$id]); } }
    $validated = asuc_validate_catalogue(['schema' => 1, 'published_at' => gmdate('c'), 'plugins' => array_values($plugins)]);
    if (is_wp_error($validated)) { return $validated; }
    asuc_store_catalogue($validated);
    return true;
}

/** Explicit policy withdrawals apply even when no new package can be verified. */
function asuc_apply_exclusions(array $rules): void {
    $catalogue = asuc_catalogue(); $changed = false;
    foreach ($catalogue['plugins'] ?? [] as $id => $entry) {
        $rule = $rules[$entry['repo']] ?? [];
        if (!empty($rule['exclude']) || !empty($rule['superseded_by'])) { unset($catalogue['plugins'][$id]); $changed = true; }
    }
    if ($changed) { asuc_store_catalogue($catalogue); }
}

/** A learned negative result is durable, expires after a day, and is invalidated by rule changes. */
function asuc_ignored_repo(string $repo, array $rule): bool {
    $entry = ((array) asuc_get('ignored_repos'))[$repo] ?? [];
    return ($entry['until'] ?? 0) > time() && ($entry['rule'] ?? '') === hash('sha256', wp_json_encode($rule));
}
function asuc_ignore_repo(string $repo, array $rule): void {
    $ignored = array_filter((array) asuc_get('ignored_repos'), static function ($entry) { return ($entry['until'] ?? 0) > time(); });
    $ignored[$repo] = ['until' => time() + DAY_IN_SECONDS, 'rule' => hash('sha256', wp_json_encode($rule))];
    asuc_put('ignored_repos', $ignored);
}
/** Known identity: public stable-release tag first; no REST API quota or ZIP when unchanged. */
function asuc_known_release(string $repo, array $known) {
    $key = 'asuc_latest_' . hash('sha256', $repo);
    $state = (array) get_site_transient($key);
    if (($state['retry_at'] ?? 0) > time()) { return new WP_Error('release_wait', 'Release lookup is paused until ' . gmdate('Y-m-d H:i', $state['retry_at']) . ' UTC.'); }
    if (($state['checked'] ?? 0) > time() - 60 && !empty($state['tag'])) { $tag = $state['tag']; }
    else {
        $response = wp_safe_remote_head('https://github.com/cchatterton/' . rawurlencode($repo) . '/releases/latest', ['timeout' => 8, 'redirection' => 0]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $location = is_wp_error($response) ? '' : (string) wp_remote_retrieve_header($response, 'location');
        $prefix = 'https://github.com/cchatterton/' . $repo . '/releases/tag/';
        $tag = strpos($location, $prefix) === 0 ? substr($location, strlen($prefix)) : '';
        if (!in_array($code, [301,302,303,307,308], true) || !preg_match('/^[vV]?\d+\.\d+(?:\.\d+){0,2}$/D', $tag)) {
            $after = is_wp_error($response) ? '' : wp_remote_retrieve_header($response, 'retry-after');
            $retry = max(time() + 600, is_numeric($after) ? time() + (int) $after : (int) strtotime((string) $after));
            set_site_transient($key, ['retry_at' => $retry], max(600, $retry - time()));
            return new WP_Error('release_lookup', 'Could not verify the stable release (HTTP ' . $code . '). Previous result retained. Retry after ' . gmdate('Y-m-d H:i', $retry) . ' UTC.');
        }
        set_site_transient($key, ['tag' => $tag, 'checked' => time()], 60);
    }
    $existing = asuc_catalogue()['plugins'][$known['id']] ?? [];
    if (($existing['tag'] ?? '') === $tag && !empty($existing['sha256'])) { return ['unchanged' => true]; }
    return ['draft' => false, 'prerelease' => false, 'tag_name' => $tag, 'body' => '', 'assets' => [['name' => $known['asset'], 'direct' => true, 'id' => $tag, 'size' => 0]]];
}

/** Amortise WordPress boot overhead while preserving short bounded steps and the shared lock. */
function asuc_scan_step(string $id) {
    $deadline = microtime(true) + 2;
    for ($i = 0; $i < 5; $i++) {
        $result = asuc_scan_step_once($id);
        if (is_wp_error($result) || ($result['status'] ?? '') !== 'running' || microtime(true) >= $deadline) { return $result; }
    }
    return $result;
}
