<?php
if (!defined('ABSPATH')) { exit; }
function asuc_update_object(array $entry): object {
    return (object) ['id' => 'https://github.com/' . $entry['owner'] . '/' . $entry['repo'], 'slug' => $entry['slug'], 'plugin' => $entry['file'], 'new_version' => $entry['version'], 'url' => asuc_release_url($entry), 'package' => asuc_compatibility($entry) === '' ? asuc_package($entry) : '', 'requires' => $entry['requires'], 'requires_php' => $entry['requires_php']];
}
function asuc_project_updates($transient): object {
    if (!is_object($transient)) { $transient = new stdClass(); }
    $transient->response = isset($transient->response) && is_array($transient->response) ? $transient->response : [];
    $transient->no_update = isset($transient->no_update) && is_array($transient->no_update) ? $transient->no_update : [];
    $plugins = asuc_plugins();
    foreach (asuc_catalogue()['plugins'] ?? [] as $entry) {
        if (!asuc_match($entry, $plugins)) { continue; }
        $file = $entry['file']; $update = asuc_update_object($entry);
        if (version_compare($entry['version'], $plugins[$file]['Version'], '>')) {
            $transient->response[$file] = $update; unset($transient->no_update[$file]);
        } else {
            unset($transient->response[$file]); $update->package = ''; $transient->no_update[$file] = $update;
        }
    }
    return $transient;
}
function asuc_plugin_information($result, string $action, $args) {
    if ($action !== 'plugin_information') { return $result; }
    foreach (asuc_registry() as $id => $identity) {
        if (($args->slug ?? '') !== $identity['slug']) { continue; }
        $entry = asuc_catalogue()['plugins'][$id] ?? null;
        if (!$entry) { return new WP_Error('asuc_no_metadata', 'Open Plugins > AlphaSys Plugins and check the catalogue first.'); }
        return (object) ['name' => $entry['name'], 'slug' => $entry['slug'], 'version' => $entry['version'], 'author' => esc_html($entry['author']), 'homepage' => 'https://github.com/' . $entry['owner'] . '/' . $entry['repo'], 'download_link' => asuc_compatibility($entry) === '' ? asuc_package($entry) : '', 'requires' => $entry['requires'], 'requires_php' => $entry['requires_php'], 'sections' => ['description' => '<p>' . esc_html($entry['description']) . '</p>', 'changelog' => '<pre style="white-space:pre-wrap">' . esc_html($entry['body']) . '</pre>']];
    }
    return $result;
}
/** Version 1 client API: returns a nonce-protected explicit check action, never a network lookup. */
function asuc_check_url(string $plugin_file): string {
    foreach (asuc_registry() as $id => $entry) {
        if ($entry['file'] === $plugin_file) { return wp_nonce_url(add_query_arg(['action' => 'asuc_action', 'operation' => 'check', 'plugin_id' => $id], admin_url('admin-post.php')), 'asuc_action'); }
    }
    return asuc_url();
}
function asuc_row_meta(array $links, string $file, array $data = [], string $status = ''): array {
    foreach (asuc_registry() as $entry) {
        if ($entry['file'] !== $file || !asuc_match($entry, asuc_plugins())) { continue; }
        // Core version/author links remain; remove only known duplicate update/repository metadata.
        $links = array_values(array_filter($links, static function ($link) use ($entry) {
            $label = trim(wp_strip_all_tags($link));
            return !in_array($label, ['GitHub', 'Check for updates', 'Install AlphaSys Update Controller', 'Activate AlphaSys Update Controller'], true);
        }));
        $links[] = '<a href="' . esc_url('https://github.com/' . $entry['owner'] . '/' . $entry['repo']) . '">GitHub</a>';
        if (asuc_authorised()) { $links[] = '<a href="' . esc_url(asuc_check_url($file)) . '">Check for updates</a>'; }
        break;
    }
    return $links;
}
function asuc_verify_download($reply, string $package, $upgrader, array $extra = []) {
    if ($reply !== false) { return $reply; }
    foreach (asuc_catalogue()['plugins'] ?? [] as $entry) {
        if ($package !== asuc_package($entry)) { continue; }
        $temp = wp_tempnam($entry['asset']);
        if (!$temp) { return new WP_Error('asuc_temp', 'A temporary package file could not be created.'); }
        $url = $package;
        for ($hop = 0; $hop < 4; $hop++) {
            $host = wp_parse_url($url, PHP_URL_HOST);
            if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host, ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)) { @unlink($temp); return new WP_Error('asuc_host', 'The package redirected outside the trusted release hosts.'); }
            $response = wp_safe_remote_get($url, ['timeout' => 30, 'redirection' => 0, 'stream' => true, 'filename' => $temp, 'limit_response_size' => 67108864]);
            if (is_wp_error($response)) { @unlink($temp); return new WP_Error('asuc_download', 'The release package could not be downloaded.'); }
            $code = wp_remote_retrieve_response_code($response);
            if (in_array($code, [301, 302, 303, 307, 308], true)) { $url = WP_Http::make_absolute_url(wp_remote_retrieve_header($response, 'location'), $url); continue; }
            if ($code !== 200 || !hash_equals($entry['sha256'], hash_file('sha256', $temp))) { @unlink($temp); return new WP_Error('asuc_checksum', 'The release package did not match its verified checksum. No files were installed.'); }
            $GLOBALS['asuc_package_entry'] = $entry;
            return $temp;
        }
        @unlink($temp); return new WP_Error('asuc_redirect', 'Too many package redirects.');
    }
    return $reply;
}
function asuc_verify_source($source, $remote_source, $upgrader, $extra = []) {
    $entry = $GLOBALS['asuc_package_entry'] ?? null;
    if (!$entry || is_wp_error($source)) { return $source; }
    unset($GLOBALS['asuc_package_entry']);
    $main = trailingslashit($source) . basename($entry['file']);
    if (basename(untrailingslashit($source)) !== dirname($entry['file']) || !is_file($main)) { return new WP_Error('asuc_package_root', 'The package does not contain the expected plugin directory and main file.'); }
    $headers = get_file_data($main, ['version' => 'Version', 'name' => 'Plugin Name']);
    if ($headers['version'] !== $entry['version'] || !$headers['name']) { return new WP_Error('asuc_package_version', 'The package version does not match the catalogue.'); }
    return $source;
}
