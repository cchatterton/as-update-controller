<?php
/** Disposable WordPress only: wp eval-file tests/manual-discovery.php */
wp_set_current_user(1);
ob_start(); // Core admin_init may set headers; buffer assertion output in CLI.
function manual_assert($ok, $label) { if (!$ok) { throw new RuntimeException($label); } echo "PASS: $label\n"; }
foreach (['asuc', 'tnuc'] as $prefix) {
    $get = $prefix . '_get'; $put = $prefix . '_put'; $dispatch = $prefix . '_dispatch';
    $catalogue = $prefix . '_catalogue'; $registry = $prefix . '_registry'; $refresh = $prefix . '_refresh';
    $migrate = $prefix . '_migrate_manual_checks'; $schedule = $prefix . '_schedule';
    $slug = $prefix === 'asuc' ? 'as-update-controller' : 'tn-update-controller';
    $root = dirname(constant(strtoupper($prefix) . '_DIR'));
    $fixture = json_decode(file_get_contents($root . '/catalogue.json'), true);
    $entry = $fixture['plugins'][0];
    $id = $prefix . '-new-capability';
    foreach (['id', 'repo', 'slug'] as $key) { $entry[$key] = $id; }
    $entry['file'] = $id . '/' . $id . '.php'; $entry['asset'] = $id . '.zip';
    $entry['version'] = '99.0.0'; $entry['tag'] = 'v99.0.0';
    $entry['allowed_domains'] = []; $entry['include_subdomains'] = false; $entry['dependencies'] = [];
    $fixture['plugins'][] = $entry;
    $calls = 0;
    $intercept = static function ($pre, $args, $url) use (&$calls, &$fixture, $prefix) {
        if (strpos($url, constant(strtoupper($prefix) . '_CATALOGUE_URL')) !== 0) { return new WP_Error('test_http', 'Unrelated core HTTP blocked in test.'); }
        $calls++;
        return ['response' => ['code' => 200], 'headers' => [], 'body' => json_encode($fixture)];
    };
    add_filter('pre_http_request', $intercept, 10, 3);
    $previous = [];
    foreach (['catalogue', 'check', 'settings', 'manual_checks_version'] as $key) { $previous[$key] = $get($key); }
    $plugin_filter = static function ($plugins) use ($entry) {
        $plugins[$entry['file']] = ['Name' => $entry['name'], 'Version' => '1.0.0', 'Author' => $entry['author'], 'UpdateURI' => 'https://github.com/' . $entry['owner'] . '/' . $entry['repo']];
        return $plugins;
    };
    // get_plugins reads this cache; no fixture plugin is installed or executed.
    $old_cache = wp_cache_get('plugins', 'plugins');
    try {
        $put('catalogue', []); $put('check', []);
        manual_assert(!isset($registry()[$id]), "$prefix new identity has no registry entry");
        $put('settings', ['mode' => 'scheduled', 'hours' => 6]); $put('manual_checks_version', 0);
        wp_schedule_single_event(time() + 600, $prefix . '_scheduled_check');
        $migrate();
        manual_assert(!wp_next_scheduled($prefix . '_scheduled_check'), "$prefix upgrade removes old cron event");
        manual_assert(($prefix . '_settings')()['mode'] === 'manual', "$prefix saved scheduled preference is ignored");
        do_action($prefix . '_scheduled_check'); ($prefix . '_scheduled_check')();
        $_GET['force-check'] = '1'; ($prefix . '_refresh_on_native_forced_check')(); do_action('admin_init'); unset($_GET['force-check']);
        manual_assert(is_wp_error($refresh(false)) && $calls === 0, "$prefix cron, background and generic force-check cannot fetch");
        $plugins = get_plugins(); $plugins = $plugin_filter($plugins); wp_cache_set('plugins', ['' => $plugins], 'plugins');
        $result = $dispatch('check', []);
        manual_assert(!is_wp_error($result) && $calls === 1 && isset($catalogue()['plugins'][$id]), "$prefix one manual check discovers unregistered capability");
        $updates = get_site_transient('update_plugins');
        manual_assert(isset($updates->response[$entry['file']]) && $updates->response[$entry['file']]->new_version === '99.0.0', "$prefix same check refreshes installed update status");
        $dispatch('check', []);
        manual_assert($calls === 1, "$prefix repeated button checks respect cooldown");
        array_pop($fixture['plugins']); $put('check', []); $dispatch('check', []);
        manual_assert(!isset($registry()[$id]) && !isset(get_site_transient('update_plugins')->response[$entry['file']]), "$prefix withdrawn identity and its stale update disappear");
        $put('check', []); wp_set_current_user(0); $before = $calls;
        manual_assert(is_wp_error($dispatch('check', [])) && $calls === $before, "$prefix unauthorised check performs no HTTP"); wp_set_current_user(1);
        manual_assert(!wp_next_scheduled($prefix . '_scheduled_check'), "$prefix checking never schedules another check");
    } finally {
        remove_filter('pre_http_request', $intercept, 10);
        foreach ($previous as $key => $value) { $put($key, $value); }
        wp_cache_set('plugins', $old_cache, 'plugins');
    }
}

ob_end_flush();
