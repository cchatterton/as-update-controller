<?php
/** Disposable WordPress only. Both controllers active; no network escapes this fixture. */
wp_set_current_user(1);
function feed_assert($ok,$label){if(!$ok){throw new RuntimeException($label);}echo "PASS: $label\n";}
global $wpdb;$existed=ghc_v3_exists();$rows=ghc_v3_rows();$saved=[];
foreach(['tnuc_catalogue','asuc_catalogue','tnuc_check','asuc_check'] as $key){$saved[$key]=get_site_option($key);}
$old_updates=get_site_transient('update_plugins');$calls=[];$mode='good';$feed=[];$url='';
$http=static function($pre,$args,$requested)use(&$calls,&$mode,&$feed,&$url){
 $calls[]=$requested;
 feed_assert(strtok($requested,'?')===$url&&strpos($requested,'https://raw.githubusercontent.com/')===0,'only public catalogue JSON requested');
 feed_assert(empty($args['headers']['Authorization'])&&($args['redirection']??null)===0,'no token or redirect sent');
 if($mode==='error'){return ['response'=>['code'=>503],'headers'=>[],'body'=>''];}
 return ['response'=>['code'=>200],'headers'=>[],'body'=>json_encode($mode==='bad'?['schema'=>99]:$feed)];
};
add_filter('pre_http_request',$http,10,3);
$dir=WP_PLUGIN_DIR.'/feed-test-capability';$file=$dir.'/feed-test-capability.php';
try{
 foreach(['tnuc'=>'Techn','asuc'=>'AlphaSys'] as $p=>$brand){
  $upper=strtoupper($p);$slug=$p==='tnuc'?'tn-update-controller':'as-update-controller';$url=constant($upper.'_CATALOGUE_URL');
  $root=getenv($upper.'_TEST_REPO') ?: dirname(constant($upper.'_DIR'));
  $feed=json_decode(file_get_contents($root.'/catalogue.json'),true);$original=$feed;$mode='good';$calls=[];
  ($p.'_put')('catalogue',[]);($p.'_put')('check',[]);
  $result=($p.'_dispatch')('check',[]);
  feed_assert(!is_wp_error($result)&&$result['status']==='complete'&&count($calls)===1,"$p first check uses exactly one request");
  // New identities arrive through the published feed, without client-side repository scanning.
  $new=['id'=>'feed-test-capability','repo'=>'feed-test-capability','owner'=>'cchatterton','slug'=>'feed-test-capability','file'=>'feed-test-capability/feed-test-capability.php','asset'=>'feed-test-capability.zip','name'=>'Feed test','description'=>'Test','author'=>$brand,'version'=>'1.0.1','tag'=>'v1.0.1','requires'=>'6.0','requires_php'=>'7.4','dependencies'=>[],'controller_api'=>1,'sha256'=>str_repeat('a',64),'beta'=>true,'allowed_domains'=>[],'include_subdomains'=>false];
  $feed['plugins'][]=$new;wp_mkdir_p($dir);file_put_contents($file,"<?php\n/*\nPlugin Name: Feed test\nAuthor: $brand\nVersion: 1.0.0\nUpdate URI: https://github.com/cchatterton/feed-test-capability\n*/");wp_clean_plugins_cache(false);
  $calls=[];$result=($p.'_dispatch')('check',[]);
  feed_assert($result['status']==='complete'&&count($calls)===1&&isset(($p.'_catalogue')()['plugins'][$new['id']]),"$p new capability and installed update from one JSON");
  feed_assert(get_site_transient('update_plugins')->response[$new['file']]->new_version==='1.0.1',"$p native installed update projected");
  $row=ghc_v3_row($new['repo']);feed_assert($row['version']==='1.0.1'&&$row['local_version']==='1.0.0'&&$row['local_installed_state']==='inactive'&&$row['alpha_beta']==='beta',"$p shared version/local state/readiness retained");
  activate_plugin($new['file'],'',true);feed_assert(ghc_v3_row($new['repo'])['local_installed_state']==='network_active',"$p network activation reflected locally");deactivate_plugins($new['file'],false,true);
  for($i=2;$i<=16;$i++){$feed['plugins'][count($feed['plugins'])-1]['version']='1.0.'.$i;$feed['plugins'][count($feed['plugins'])-1]['tag']='v1.0.'.$i;$before=count($calls);$result=($p.'_dispatch')('check',[]);feed_assert($result['status']==='complete'&&count($calls)===$before+1,"$p rapid release $i: one JSON, no per-plugin requests");}
  feed_assert(count($calls)===count(array_unique($calls)),"$p explicit clicks use distinct freshness URLs");
  // A larger catalogue must not increase request count.
  for($i=0;$i<100;$i++){$entry=$new;$id='feed-extra-'.$i;foreach(['id','repo','slug'] as $key){$entry[$key]=$id;}$entry['file']=$id.'/'.$id.'.php';$entry['asset']=$id.'.zip';$feed['plugins'][]=$entry;}
  $calls=[];$result=($p.'_dispatch')('check',[]);feed_assert($result['status']==='complete'&&count($calls)===1,"$p 100 additional plugins still cost one JSON request");
  $before=($p.'_catalogue')();$mode='error';$calls=[];$result=($p.'_dispatch')('check',[]);feed_assert(is_wp_error($result)&&count($calls)===1&&($p.'_catalogue')()===$before,"$p failed download retains verified metadata");
  $mode='bad';$result=($p.'_dispatch')('check',[]);feed_assert(is_wp_error($result)&&($p.'_catalogue')()===$before,"$p invalid JSON schema retained previous catalogue");
  $mode='good';$calls=[];$result=($p.'_dispatch')('check',[]);feed_assert($result['status']==='complete'&&count($calls)===1,"$p immediate recovery needs one request");
  $calls=[];($p.'_registry')();($p.'_project_updates')(new stdClass());ob_start();($p.'_render_admin')();ob_end_clean();($p.'_scheduled_check')();feed_assert(!$calls,"$p rendering and old cron remain HTTP-free");
  wp_set_current_user(0);feed_assert(is_wp_error(($p.'_begin_scan')())&&!$calls,"$p permissions enforced before network");wp_set_current_user(1);
  @unlink($file);@rmdir($dir);wp_clean_plugins_cache(false);
  ($p.'_put')('catalogue',[]); // The next brand's synthetic identity intentionally reuses this test path.
 }
 echo "One-request catalogue assertions passed.\n";
}finally{
 remove_filter('pre_http_request',$http,10);wp_set_current_user(1);deactivate_plugins('feed-test-capability/feed-test-capability.php',true,true);@unlink($file);@rmdir($dir);wp_clean_plugins_cache(false);
 $wpdb->query('DROP TABLE IF EXISTS `github-cchatterton`');asuc_put('catalogue',[]);tnuc_put('catalogue',[]);if($existed){ghc_v3_ensure_table();foreach($rows as $row){$repo=$row['repo'];unset($row['repo']);ghc_v3_save($repo,$row);}}
 foreach($saved as $key=>$value){if($value===false){delete_site_option($key);}else{update_site_option($key,$value);}}set_site_transient('update_plugins',$old_updates);
}
