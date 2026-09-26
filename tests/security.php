<?php
/** Disposable WordPress, both controllers active, integration.php run first. */
wp_set_current_user(1);
function assert_security($ok, $name) { if (!$ok) { throw new RuntimeException($name); } echo "PASS: $name\n"; }
$entry = tnuc_catalogue()['plugins']['menubot'];
$intercept = static function ($pre, $args, $url) { if (!empty($args['stream'])) { file_put_contents($args['filename'], 'tampered-package'); } return ['response'=>['code'=>200], 'body'=>'', 'headers'=>[]]; };
add_filter('pre_http_request', $intercept, 10, 3);
$result = tnuc_verify_download(false, tnuc_package($entry), null);
assert_security(is_wp_error($result) && $result->get_error_code() === 'tnuc_checksum', 'tampered ZIP rejected before extraction');
assert_security(!isset($GLOBALS['tnuc_package_entry']), 'failed download cannot contaminate a later native package');
remove_filter('pre_http_request', $intercept, 10);
$GLOBALS['tnuc_package_entry'] = $entry;
assert_security(is_wp_error(tnuc_verify_source('/tmp/wrong-root/', '/tmp/', null)), 'unexpected archive root rejected');
$entry['requires_php'] = '99.0'; assert_security(tnuc_compatibility($entry) !== '', 'incompatible PHP release blocked');
$entry = tnuc_catalogue()['plugins']['menubot']; $entry['dependencies']=['missing-required-plugin'];
assert_security(tnuc_compatibility($entry) !== '', 'missing required plugin blocked');
$deny = static fn() => false; add_filter('file_mod_allowed', $deny);
assert_security(is_wp_error(tnuc_start_batch(['tn-qrcodes'], 'install')), 'file modification policy respected'); remove_filter('file_mod_allowed', $deny);
// No downloads: first queued item has changed metadata; second already completed before an interrupted request.
$entry = tnuc_catalogue()['plugins']['menubot'];
$old = tnuc_get('batch');
$job = ['id'=>'test-resume', 'owner'=>1, 'kind'=>'update', 'status'=>'running', 'updated_at'=>time(), 'items'=>[
 ['id'=>'menubot','version'=>'0.0.0','sha256'=>$entry['sha256'],'status'=>'pending'],
 ['id'=>'menubot','version'=>$entry['version'],'sha256'=>$entry['sha256'],'status'=>'working']]];
tnuc_put('batch',$job); $job = tnuc_step_batch('test-resume'); assert_security($job['status']==='running' && $job['items'][0]['status']==='failed','individual failure does not end the remaining batch');
$job=tnuc_step_batch('test-resume'); assert_security($job['status']==='partial' && $job['items'][1]['status']==='success','interrupted completed item resumes without reinstalling'); tnuc_put('batch',$old);
require_once (getenv('TNUC_TEST_REPO') ?: dirname(TNUC_DIR)) . '/integration/controller-client.php';
tnuc_client_register(WP_PLUGIN_DIR.'/menubot/menubot.php','menubot');
assert_security(tnuc_client_links([], 'menubot/menubot.php') === [], 'compatible active controller owns client row links');
$links=apply_filters('plugin_row_meta', [], 'menubot/menubot.php', [], 'all');
assert_security(count(array_filter($links, static fn($v)=>str_contains($v, '>GitHub<')))===1, 'one GitHub row link with active client and controller');
assert_security(count(array_filter($links, static fn($v)=>str_contains($v, '>Check for updates<')))===1, 'one controller check link');
foreach (['tnuc'=>['menubot','menubot/menubot.php'], 'asuc'=>['as-qs-relay','as-qs-relay/as-qs-relay.php']] as $prefix=>$details) {
 $registry=($prefix.'_registry')(); $identity=$registry[$details[0]];
 assert_security(in_array(($prefix.'_migration_status')($identity), ['Controller integration', 'Audited legacy compatibility bridge'], true) || str_contains(($prefix.'_migration_status')($identity), 'Audited legacy'), 'reviewed integration recognised: '.$details[0]);
 global $wp_filter; $remaining=0;
 foreach(['site_transient_update_plugins','pre_set_site_transient_update_plugins','plugins_api','admin_init','load-plugins.php','plugin_row_meta'] as $hook){
  foreach(($wp_filter[$hook]->callbacks ?? []) as $group){foreach($group as $cb){
   try{$f=$cb['function'];$r=is_array($f)?new ReflectionMethod($f[0],$f[1]):new ReflectionFunction($f);$name=$r->getFileName();
    foreach($identity['legacy'] as $legacy){if($name===WP_PLUGIN_DIR.'/'.dirname($details[1]).'/'.$legacy['path']){$remaining++;}}
   }catch(ReflectionException|TypeError $e){}
  }}
 }
 assert_security($remaining===0,'reviewed updater callbacks removed: '.$details[0]);
}
echo "Security and recovery assertions passed.\n";
