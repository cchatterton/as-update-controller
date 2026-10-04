<?php
/** Run against a disposable WordPress installation: wp eval-file tests/integration.php */
if (!defined('ABSPATH') || !defined('TNUC_VERSION') || !defined('ASUC_VERSION')) { throw new RuntimeException('Activate both controllers in a disposable WordPress installation.'); }
wp_set_current_user(1);
function check_test($condition, string $label): void { if (!$condition) { throw new RuntimeException('FAIL: ' . $label); } echo "PASS: $label\n"; }
$root = getenv('TNUC_TEST_REPO') ?: dirname(TNUC_DIR);
$techn = json_decode(file_get_contents($root . '/catalogue.json'), true);
$alpha = json_decode(file_get_contents((getenv('ASUC_TEST_REPO') ?: dirname(ASUC_DIR)) . '/catalogue.json'), true);
$fixture_mode = 'good'; $calls = [];
$intercept = static function ($pre, $args, $url) use (&$fixture_mode, &$calls, $techn, $alpha) {
    $calls[] = $url;
    if ($url !== TNUC_CATALOGUE_URL && $url !== ASUC_CATALOGUE_URL) { return new WP_Error('test_http', 'Unexpected HTTP during test: ' . $url); }
    if ($fixture_mode === '429') { return ['response'=>['code'=>429], 'headers'=>['retry-after'=>'1200'], 'body'=>'']; }
    if ($fixture_mode === 'invalid') { return ['response'=>['code'=>200], 'headers'=>[], 'body'=>'{"schema":9}']; }
    return ['response'=>['code'=>200], 'headers'=>[], 'body'=>json_encode($url === TNUC_CATALOGUE_URL ? $techn : $alpha)];
};
add_filter('pre_http_request', $intercept, 10, 3);
foreach (['tnuc','asuc'] as $prefix) { delete_site_option($prefix . '_catalogue'); delete_site_option($prefix . '_check'); }
$unknown = (object) ['response'=>['other/plugin.php'=>(object)['new_version'=>'3.0']]];
for ($i=0;$i<20;$i++) { apply_filters('site_transient_update_plugins', $unknown); apply_filters('pre_set_site_transient_update_plugins', $unknown); }
$_GET['force-check']='1'; $_POST['action']='update-selected';
foreach (['installed','catalogue','settings'] as $tab) {
    $_GET['tab']=$tab; ob_start(); tnuc_render_admin(); asuc_render_admin(); $html=ob_get_clean();
    check_test(str_contains($html, 'Techn Plugins') && str_contains($html, 'AlphaSys Plugins'), 'both brand screens render: ' . $tab);
}
apply_filters('plugins_api', false, 'plugin_information', (object)['slug'=>'menubot']);
check_test(count($calls)===0, 'cold caches, repeated reads, details and all admin tabs make zero HTTP calls');
unset($_GET['force-check'],$_POST['action']);
check_test(!is_wp_error(tnuc_refresh()) && count($calls)===1, 'one catalogue request for a Techn manual check');
check_test(!is_wp_error(tnuc_refresh()) && count($calls)===2, 'repeated manual check fetches fresh API data');
check_test(!is_wp_error(asuc_refresh()) && count($calls)===3, 'AlphaSys has its own single catalogue request');
foreach (tnuc_catalogue()['plugins'] as $entry) { check_test($entry['author']==='Techn','Techn catalogue ownership: '.$entry['id']); }
foreach (asuc_catalogue()['plugins'] as $entry) { check_test($entry['author']==='AlphaSys','AlphaSys catalogue ownership: '.$entry['id']); }
check_test(!array_intersect_key(tnuc_registry(), asuc_registry()), 'registries do not overlap');
$before=count($calls); for($i=0;$i<20;$i++){apply_filters('site_transient_update_plugins',$unknown);}
check_test(count($calls)===$before && isset($unknown->response['other/plugin.php']), 'warm reads preserve unrelated provider and make zero HTTP calls');
$valid=tnuc_catalogue(); tnuc_put('check',[]); $fixture_mode='429';
check_test(is_wp_error(tnuc_refresh()) && tnuc_catalogue()===$valid, '429 preserves last good catalogue');
$before=count($calls);check_test(is_wp_error(tnuc_refresh()) && count($calls)===$before+1,'another manual click retries the API immediately');
check_test(tnuc_get('check')['retry_at']===0,'no controller waiting period is stored');
tnuc_put('check',[]);$fixture_mode='invalid';check_test(is_wp_error(tnuc_refresh()) && tnuc_catalogue()===$valid,'invalid JSON/schema preserves last good catalogue');
tnuc_put('check',[]);$fixture_mode='good';$lock=tnuc_lock('discovery');$before=count($calls);
check_test(is_wp_error(tnuc_refresh()) && count($calls)===$before,'concurrent worker lock prevents another lookup');
tnuc_unlock('discovery','not-the-owner');check_test(tnuc_lock('discovery')===false,'non-owner cannot release a lock');tnuc_unlock('discovery',$lock);
check_test(!is_wp_error(tnuc_refresh()),'released lock permits successful refresh');
$bad=$techn;$bad['plugins'][0]['repo']='another-repo';check_test(is_wp_error(tnuc_validate_catalogue($bad)),'catalogue cannot change trusted repository identity');
$bad=$techn;$bad['plugins'][]=$bad['plugins'][0];check_test(is_wp_error(tnuc_validate_catalogue($bad)),'duplicate catalogue IDs rejected');
// Test header-only matching independently of a real active client's registration.
$registered_clients = $GLOBALS['tnuc_clients'] ?? []; unset($GLOBALS['tnuc_clients']['menubot/menubot.php']);
check_test(!tnuc_match(tnuc_registry()['menubot'], ['menubot/menubot.php'=>['Author'=>'Unrelated','UpdateURI'=>'']]),'unrelated author cannot claim a legacy basename');
$GLOBALS['tnuc_clients'] = $registered_clients;
wp_set_current_user(0);check_test(is_wp_error(tnuc_dispatch('check',[])),'unauthorised discovery rejected');check_test(is_wp_error(tnuc_start_batch(['menubot'],'install')),'unauthorised installation rejected');wp_set_current_user(1);
check_test(!is_wp_error(tnuc_dispatch('settings',['mode'=>'manual','hours'=>6])) && !tnuc_on_main(static fn()=>wp_next_scheduled('tnuc_scheduled_check')),'manual-only clears automatic discovery');
$before=count($calls);tnuc_scheduled_check();check_test(count($calls)===$before,'manual-only refuses scheduled discovery');
tnuc_dispatch('settings',['mode'=>'scheduled','hours'=>6]);tnuc_schedule();tnuc_schedule();
$events=tnuc_on_main(static function(){ $n=0;foreach(_get_cron_array() as $time=>$hooks){$n+=count($hooks['tnuc_scheduled_check']??[]);}return $n;});
check_test($events===0,'legacy scheduled setting cannot create an event');
$before=count($calls);$_GET['tab']='catalogue';ob_start();tnuc_render_admin();asuc_render_admin();ob_end_clean();check_test(count($calls)===$before,'catalogue rendering after failures remains network-free');
remove_filter('pre_http_request',$intercept,10);
echo "All integration assertions passed.\n";
