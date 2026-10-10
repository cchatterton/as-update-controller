<?php
/** Disposable WordPress only: creates/removes inert fixture plugins for both controllers. */
wp_set_current_user(1);
function exclusive_assert($ok, $message) { if (!$ok) { throw new RuntimeException($message); } echo "PASS: $message\n"; }
$site_url=static function(){return 'https://exclusive-test.example.org/';};
add_filter('network_home_url',$site_url);add_filter('home_url',$site_url);
$requests=0;$package_requests=0;$fixture_zip=null;$fixture_url=null;
$deny=function($pre,$args,$url)use(&$requests,&$package_requests,&$fixture_zip,&$fixture_url){
 if ($fixture_zip && $url===$fixture_url && !empty($args['stream'])) { copy($fixture_zip,$args['filename']);$package_requests++;return ['response'=>['code'=>200],'headers'=>[],'body'=>'']; }
 // Native upgrader may refresh WordPress.org metadata; block that unrelated traffic.
 if (wp_parse_url($url,PHP_URL_HOST)==='api.wordpress.org') { return new WP_Error('test_core_http','Blocked WordPress.org request'); }
 $requests++;return new WP_Error('test_http','Unexpected HTTP: '.$url);
};
add_filter('pre_http_request',$deny,1,3);
try {
 foreach (['asuc'=>'as-update-controller','tnuc'=>'tn-update-controller'] as $prefix=>$slug) {
  $get=$prefix.'_get';$put=$prefix.'_put';$validate=$prefix.'_validate_catalogue';$group=$prefix.'_catalogue_groups';
  $saved=$get('catalogue');$batch=$get('batch');$check=$get('check');
  if ($prefix==='asuc') {
   try { $put('catalogue',[]);foreach (['as-ms-reporting','as-transcript-themes'] as $exclusive_id) { exclusive_assert(($prefix.'_domain_allowed')(($prefix.'_bundled_registry')()[$exclusive_id]),"$exclusive_id unrestricted on arbitrary domain with cold cache"); } } finally { $put('catalogue',$saved); }
  }
  $candidate=json_decode(file_get_contents(getenv(strtoupper($prefix).'_TEST_REPO').'/catalogue.json'),true);
  $valid=$validate($candidate);exclusive_assert(!is_wp_error($valid),"$prefix accepts current catalogue");
  $legacy=$candidate;foreach ($legacy['plugins'] as &$legacy_entry) { unset($legacy_entry['exclusive']); }unset($legacy_entry);
  $legacy_valid=$validate($legacy);exclusive_assert(!is_wp_error($legacy_valid),"$prefix accepts missing exclusive metadata");
  foreach (['true',1,null,[]] as $bad) { $malformed=$candidate;$malformed['plugins'][0]['exclusive']=$bad;exclusive_assert(is_wp_error($validate($malformed)),"$prefix rejects non-boolean exclusive"); }
  if ($prefix==='asuc') { foreach (['as-ms-reporting','as-transcript-themes'] as $id) { exclusive_assert($legacy_valid['plugins'][$id]['exclusive']===true,"$id exclusive fallback for old catalogue"); } }
  $entry=$valid['plugins'][$slug];$id=$prefix.'-exclusive-fixture';$file=$id.'/'.$id.'.php';$dir=WP_PLUGIN_DIR.'/'.$id;
  exclusive_assert(!file_exists($dir),'fixture directory absent');
  $entry=array_merge($entry,['id'=>$id,'repo'=>$id,'slug'=>$id,'file'=>$file,'asset'=>$id.'.zip','name'=>'Exclusive Fixture','exclusive'=>true,'version'=>'1.0.0','tag'=>'v1.0.0','requires'=>'6.5','requires_php'=>'7.4','dependencies'=>[],'allowed_domains'=>[],'include_subdomains'=>false]);
  try {
   $put('batch',[]);$put('check',[]);$put('catalogue',['plugins'=>[$id=>$entry]]);
   foreach ([false,true] as $beta) {
    $entry['beta']=$beta;$groups=$group([$id=>$entry],[$id=>$entry],[]);
    exclusive_assert(array_sum(array_map('count',$groups))===0,"$prefix uninstalled exclusive hidden regardless of beta");
   }
   exclusive_assert(is_wp_error(($prefix.'_start_batch')([$id],'install')),"$prefix direct installation blocked");
   mkdir($dir);
   file_put_contents($dir.'/'.$id.'.php',"<?php\n/*\nPlugin Name: Exclusive Fixture\nVersion: 0.0.1\nAuthor: ".$entry['author']."\nUpdate URI: https://github.com/cchatterton/$id\n*/\n");
   wp_clean_plugins_cache(false);
   $groups=$group([$id=>$entry],[$id=>$entry],get_plugins());
   exclusive_assert(isset($groups['installed'][$id]),"$prefix manually installed inactive exclusive visible");
   exclusive_assert(!is_wp_error(($prefix.'_plugin_action')($id,'activate')),"$prefix exclusive activation succeeds");
   $groups=$group([$id=>$entry],[$id=>$entry],get_plugins());
   exclusive_assert(isset($groups['active'][$id]),"$prefix active exclusive visible");
   exclusive_assert(!is_wp_error(($prefix.'_plugin_action')($id,'deactivate')),"$prefix exclusive deactivation succeeds");
   $updates=($prefix.'_project_updates')((object)['response'=>[],'no_update'=>[]]);
   exclusive_assert(isset($updates->response[$file]),"$prefix exclusive newer version projected to WordPress");
   $fixture_zip=wp_tempnam('exclusive-fixture.zip');$zip=new ZipArchive();$zip->open($fixture_zip,ZipArchive::OVERWRITE);
   $zip->addFromString($file,str_replace('Version: 0.0.1','Version: 1.0.0',file_get_contents($dir.'/'.$id.'.php')));$zip->close();
   $entry['sha256']=hash_file('sha256',$fixture_zip);$fixture_url=($prefix.'_package')($entry);$put('catalogue',['plugins'=>[$id=>$entry]]);
   $job=($prefix.'_start_batch')([$id],'update');exclusive_assert(!is_wp_error($job),"$prefix exclusive update eligible");
   $result=($prefix.'_step_batch')($job['id']);
   exclusive_assert(!is_wp_error($result) && $result['status']==='complete',"$prefix exclusive native ZIP update succeeds");
   exclusive_assert(get_plugins()[$file]['Version']==='1.0.0' && !is_plugin_active($file) && !is_plugin_active_for_network($file),"$prefix updated version verified and inactive state preserved");
   unlink($fixture_zip);$fixture_zip=null;$fixture_url=null;
   $put('batch',[]);
   exclusive_assert(!is_wp_error(($prefix.'_plugin_action')($id,'delete')),"$prefix exclusive deletion succeeds");
   $groups=$group([$id=>$entry],[$id=>$entry],get_plugins());
   exclusive_assert(array_sum(array_map('count',$groups))===0,"$prefix deleted exclusive hidden again");
   $entry['exclusive']=false;
   $groups=$group([$id=>$entry],[$id=>$entry],[]);
   exclusive_assert(isset($groups['beta'][$id]),"$prefix ordinary uninstalled plugin remains available");
  } finally {
   if ($fixture_zip && file_exists($fixture_zip)) { unlink($fixture_zip); }$fixture_zip=null;$fixture_url=null;
   deactivate_plugins($file,true,is_multisite());
   if (file_exists($dir.'/'.$id.'.php')) { unlink($dir.'/'.$id.'.php');rmdir($dir); }
   wp_clean_plugins_cache(false);$put('catalogue',$saved);$put('batch',$batch);$put('check',$check);
  }
 }
} finally {remove_filter('pre_http_request',$deny,1);remove_filter('network_home_url',$site_url);remove_filter('home_url',$site_url);}
exclusive_assert($requests===0,'exclusive management performs zero metadata HTTP');
exclusive_assert($package_requests===2,'only the two explicit fixture ZIP updates download packages');
