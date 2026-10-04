<?php
if (!defined('ABSPATH')) { exit; }
// Local repository records only. Neither controller performs discovery HTTP here.
if (!function_exists('ghc_v3_table')) {
function ghc_v3_table(): string { return 'github-cchatterton'; }
function ghc_v3_exists(): bool {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(ghc_v3_table()))) === ghc_v3_table();
}
function ghc_v3_ensure_table() {
    global $wpdb;
    if (ghc_v3_exists()) { return true; }
    $sql = 'CREATE TABLE IF NOT EXISTS `github-cchatterton` (
      repo varchar(100) NOT NULL,
      author varchar(255) NOT NULL DEFAULT \'\',
      brand varchar(16) NOT NULL DEFAULT \'\',
      version varchar(64) NOT NULL DEFAULT \'\',
      local_version varchar(64) NOT NULL DEFAULT \'\',
      local_installed_state varchar(24) NOT NULL DEFAULT \'not_installed\',
      alpha_beta varchar(8) NOT NULL DEFAULT \'beta\',
      plugin_file varchar(255) NOT NULL DEFAULT \'\',
      release_tag varchar(128) NOT NULL DEFAULT \'\',
      fingerprint varchar(64) NOT NULL DEFAULT \'\',
      status varchar(24) NOT NULL DEFAULT \'unknown\',
      package_data longtext NULL,
      release_data longtext NULL,
      last_checked datetime NULL,
      last_error text NULL,
      PRIMARY KEY (repo)
    ) ' . $wpdb->get_charset_collate();
    if ($wpdb->query($sql) === false || !ghc_v3_exists()) { return new WP_Error('table', 'Could not create the shared github-cchatterton table.'); }
    // Preserve already verified metadata on upgrade; the first API pass still validates every version.
    foreach (['asuc','tnuc'] as $prefix) {
        foreach ((array) (get_site_option($prefix . '_catalogue', [])['plugins'] ?? []) as $entry) {
            if (!is_array($entry) || ($entry['owner'] ?? '') !== 'cchatterton' || !in_array($entry['author'] ?? '', ['AlphaSys','Techn'], true)) { continue; }
            ghc_v3_save($entry['repo'], ['author' => $entry['author'], 'brand' => $entry['author'], 'version' => $entry['version'], 'plugin_file' => $entry['file'], 'alpha_beta' => empty($entry['beta']) ? 'alpha' : 'beta', 'release_tag' => $entry['tag'], 'status' => 'verified', 'package_data' => wp_json_encode($entry)]);
        }
    }
    return true;
}
function ghc_v3_row(string $repo): array {
    global $wpdb;
    return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM `github-cchatterton` WHERE repo = %s', $repo), ARRAY_A);
}
function ghc_v3_rows(): array {
    global $wpdb;
    if (!ghc_v3_exists()) { return []; }
    return (array) $wpdb->get_results('SELECT * FROM `github-cchatterton` ORDER BY repo', ARRAY_A);
}
function ghc_v3_save(string $repo, array $fields) {
    global $wpdb;
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,99}$/D', $repo)) { throw new RuntimeException('Invalid repository name.'); }
    $exists = ghc_v3_row($repo);
    if ($exists && !$fields) { return true; }
    $result = $exists ? $wpdb->update(ghc_v3_table(), $fields, ['repo' => $repo]) : $wpdb->insert(ghc_v3_table(), array_merge(['repo' => $repo], $fields));
    if ($result === false) { throw new RuntimeException('Could not save repository ' . $repo . '.'); }
    return true;
}
function ghc_v3_sync_local(): void {
    if(!ghc_v3_exists()){return;}
    require_once ABSPATH.'wp-admin/includes/plugin.php';wp_clean_plugins_cache(false);$plugins=get_plugins();$site_active=[];
    if(is_multisite()){
        $offset=0;do{$sites=get_sites(['fields'=>'ids','number'=>100,'offset'=>$offset]);foreach($sites as $site){foreach((array)get_blog_option($site,'active_plugins',[]) as $file){$site_active[$file]=true;}}$offset+=100;}while(count($sites)===100);
    }
    foreach(ghc_v3_rows() as $row){
        $file=$row['plugin_file'];
        if(!$file){foreach($plugins as $candidate=>$data){if(rtrim($data['UpdateURI']??'','/')==='https://github.com/cchatterton/'.$row['repo']){$file=$candidate;break;}}}
        $state='not_installed';$version='';
        if($file&&isset($plugins[$file])){$version=$plugins[$file]['Version'];$state=is_plugin_active_for_network($file)?'network_active':((is_plugin_active($file)||isset($site_active[$file]))?'active':'inactive');}
        ghc_v3_save($row['repo'],['local_version'=>$version,'local_installed_state'=>$state,'plugin_file'=>$file]);
    }
}
add_action('upgrader_process_complete','ghc_v3_sync_local',20,0);
function ghc_v3_activation_changed(string $option): void {
    if (in_array($option, ['active_plugins','active_sitewide_plugins'], true)) { ghc_v3_sync_local(); }
}
add_action('updated_option','ghc_v3_activation_changed',20,1);
add_action('update_site_option','ghc_v3_activation_changed',20,1);
add_action('added_option','ghc_v3_activation_changed',20,1);
add_action('add_site_option','ghc_v3_activation_changed',20,1);
add_action('deleted_plugin','ghc_v3_sync_local',20,0);

function ghc_v3_lock() {
    $run = static function () {
        global $wpdb;
        $key='ghc_v1_scan_lock'; $old=get_option($key);
        if (is_array($old) && ($old['expires']??0)<time()) { $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$key,maybe_serialize($old)));wp_cache_delete($key,'options'); }
        $token=wp_generate_uuid4();return add_option($key,['token'=>$token,'expires'=>time()+90],'',false)?$token:false;
    };
    return ghc_v3_main($run);
}
function ghc_v3_main(callable $run) {
    $switch=is_multisite()&&get_current_blog_id()!==get_main_site_id();if($switch){switch_to_blog(get_main_site_id());}
    try{return $run();}finally{if($switch){restore_current_blog();}}
}
function ghc_v3_unlock(string $token): void {
    ghc_v3_main(static function()use($token){global $wpdb;$key='ghc_v1_scan_lock';$old=get_option($key);if(is_array($old)&&hash_equals((string)$old['token'],$token)){$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$key,maybe_serialize($old)));wp_cache_delete($key,'options');}});
}
/** Mirror verified feed entries into the existing shared table without remote lookups. */
function ghc_v3_store_snapshot(array $entries, string $brand, bool $complete): void {
    $lock = ghc_v3_lock();
    if (!$lock) { throw new RuntimeException('Another check is writing shared records. Try again when it finishes.'); }
    try {
    $ready = ghc_v3_ensure_table();
    if (is_wp_error($ready)) { throw new RuntimeException($ready->get_error_message()); }
    $repos = [];
    foreach ($entries as $entry) {
        $repos[$entry['repo']] = true;
        ghc_v3_save($entry['repo'], ['author'=>$entry['author'], 'brand'=>$brand, 'version'=>$entry['version'], 'alpha_beta'=>empty($entry['beta']) ? 'alpha' : 'beta', 'plugin_file'=>$entry['file'], 'release_tag'=>$entry['tag'], 'status'=>'verified', 'package_data'=>wp_json_encode($entry), 'fingerprint'=>'', 'release_data'=>'', 'last_checked'=>gmdate('Y-m-d H:i:s'), 'last_error'=>'']);
    }
    if ($complete) {
        foreach (ghc_v3_rows() as $row) {
            if ($row['brand'] === $brand && !isset($repos[$row['repo']])) { ghc_v3_save($row['repo'], ['status'=>'excluded']); }
        }
    }
    } finally { ghc_v3_unlock($lock); }
}
}
