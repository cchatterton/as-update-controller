<?php
/** Disposable WordPress only: both controllers active. TEST_GRAPHQL=1 exercises batched transport. */
wp_set_current_user(1);
if (getenv('TEST_GRAPHQL')) { define('GITHUB_CCHATTERTON_TOKEN', 'test-public-metadata-token'); }
function shared_assert($ok, $label) { if (!$ok) { throw new RuntimeException($label); } echo "PASS: $label\n"; }
global $wpdb;
$existed=ghc_v1_exists();$saved_rows=ghc_v1_rows();$saved=[];
foreach(['asuc_catalogue','tnuc_catalogue','asuc_check','tnuc_check','ghc_v1_scan','auc_github_retry_public'] as $key){$saved[$key]=get_site_option($key);}
$files=[];$versions=['test-tn-shared'=>'1.0.0','test-as-shared'=>'1.0.0','test-other-shared'=>'1.0.0'];$authors=['test-tn-shared'=>'Techn','test-as-shared'=>'AlphaSys','test-other-shared'=>'Other Author'];
$write=static function($repo)use(&$files,&$versions,$authors){
 if(!isset($files[$repo])){$files[$repo]=wp_tempnam($repo.'.zip');}
 $zip=new ZipArchive();$zip->open($files[$repo],ZipArchive::OVERWRITE);
 $zip->addFromString("$repo/$repo.php","<?php\n/*\nPlugin Name: Shared test\nAuthor: ".$authors[$repo]."\nVersion: ".$versions[$repo]."\nUpdate URI: https://github.com/cchatterton/$repo\n*/");$zip->close();clearstatcache(true,$files[$repo]);
};
foreach(array_keys($versions) as $repo){$write($repo);}
$calls=[];$mode='good';$listed=array_keys($versions);
$release=static function($repo)use(&$versions,&$files){return ['tag_name'=>'v'.$versions[$repo],'draft'=>false,'prerelease'=>false,'assets'=>[['name'=>$repo.'.zip','size'=>filesize($files[$repo]),'id'=>$repo.'-'.$versions[$repo],'updated_at'=>$versions[$repo],'digest'=>'sha256:'.hash_file('sha256',$files[$repo])]]];};
$http=static function($pre,$args,$url)use(&$calls,&$mode,&$listed,&$files,$release){
 $calls[]=$url;
 if($mode==='limit'){return ['response'=>['code'=>403],'headers'=>['retry-after'=>'7200'],'body'=>''];}
 if($url==='https://api.github.com/graphql'){
  shared_assert(($args['headers']['Authorization']??'')==='Bearer test-public-metadata-token','token sent only to API host');
  $nodes=[];foreach($listed as $repo){$r=$release($repo);$a=$r['assets'][0];$a['updatedAt']=$a['updated_at'];unset($a['updated_at']);$nodes[]=['name'=>$repo,'isFork'=>false,'isArchived'=>false,'latestRelease'=>['tagName'=>$r['tag_name'],'isDraft'=>false,'isPrerelease'=>false,'releaseAssets'=>['nodes'=>[$a],'pageInfo'=>['hasNextPage'=>false]]]];}
  return ['response'=>['code'=>200],'headers'=>[],'body'=>json_encode(['data'=>['user'=>['repositories'=>['nodes'=>$nodes,'pageInfo'=>['hasNextPage'=>false,'endCursor'=>'end']]]]])];
 }
 if(strpos($url,'/users/cchatterton/repos?')!==false){$nodes=[];foreach($listed as $repo){$nodes[]=['name'=>$repo,'owner'=>['login'=>'cchatterton'],'private'=>false,'fork'=>false,'archived'=>false];}return ['response'=>['code'=>200],'headers'=>[],'body'=>json_encode($nodes)];}
 if(preg_match('#api.github.com/repos/cchatterton/([^/]+)/releases/latest$#',$url,$m)){return ['response'=>['code'=>200],'headers'=>[],'body'=>json_encode($release($m[1]))];}
 if(preg_match('#github.com/cchatterton/([^/]+)/releases/download/#',$url,$m)&&!empty($args['stream'])){shared_assert(empty($args['headers']['Authorization']),'token absent from download');copy($files[$m[1]],$args['filename']);return ['response'=>['code'=>200],'headers'=>[],'body'=>''];}
 throw new RuntimeException('Unexpected HTTP: '.$url);
};
$run=static function($prefix='tnuc'){$scan=($prefix.'_dispatch')('check',[]);for($i=0;!is_wp_error($scan)&&$scan['status']==='running'&&$i<100;$i++){$scan=($prefix.'_dispatch')('scan_step',['job'=>$scan['id']]);}if(is_wp_error($scan)){throw new RuntimeException($scan->get_error_message());}return $scan;};
$count=static function($part)use(&$calls){return count(array_filter($calls,static function($u)use($part){return strpos($u,$part)!==false;}));};
$installed=WP_PLUGIN_DIR.'/test-tn-shared';
add_filter('pre_http_request',$http,10,3);
try {
 $wpdb->query('DROP TABLE IF EXISTS `github-cchatterton`');
 asuc_put('catalogue',[]);tnuc_put('catalogue',[]);
 shared_assert(ghc_v1_ensure_table()===true&&ghc_v1_ensure_table()===true,'table created once, reused by both controllers');
 update_site_option('auc_github_retry_public',time()+DAY_IN_SECONDS);
 $result=$run();shared_assert($result['status']==='complete', 'initial check completes: '.$result['message']);
 shared_assert(count(ghc_v1_rows())===3,'one shared record per repository');
 shared_assert(ghc_v1_row('test-other-shared')['author']==='Other Author','other author retained in shared table');
 shared_assert(isset(tnuc_catalogue()['plugins']['test-tn-shared'])&&!isset(tnuc_catalogue()['plugins']['test-as-shared'])&&isset(asuc_catalogue()['plugins']['test-as-shared']),'separate catalogues from shared table');
 shared_assert(ghc_v1_row('test-tn-shared')['alpha_beta']==='beta','new plugin readiness recorded');
 shared_assert(ghc_v1_row('test-tn-shared')['local_installed_state']==='not_installed','local not-installed state');
 $calls=[];$result=$run('asuc');
 shared_assert($result['status']==='complete'&&$count('/releases/download/')===0,'immediate second-controller check reuses all unchanged package author metadata');
 shared_assert($count('api.github.com')===(getenv('TEST_GRAPHQL')?1:4),'every click freshly validates all repository versions via API');
 $versions['test-tn-shared']='1.0.1';$write('test-tn-shared');
 wp_mkdir_p($installed);file_put_contents($installed.'/test-tn-shared.php',"<?php\n/*\nPlugin Name: Shared test\nAuthor: Techn\nVersion: 1.0.0\nUpdate URI: https://github.com/cchatterton/test-tn-shared\n*/");
 $calls=[];$result=$run();
 shared_assert($result['status']==='complete'&&$count('/releases/download/')===1,'only changed package downloads during rapid recheck');
 $row=ghc_v1_row('test-tn-shared');shared_assert($row['version']==='1.0.1'&&$row['local_version']==='1.0.0'&&$row['local_installed_state']==='inactive','released and local versions stored separately');
 shared_assert(get_site_transient('update_plugins')->response['test-tn-shared/test-tn-shared.php']->new_version==='1.0.1','same check refreshes installed update projection');
 activate_plugin('test-tn-shared/test-tn-shared.php','',true);shared_assert(ghc_v1_row('test-tn-shared')['local_installed_state']==='network_active','network activation updates shared local state');
 deactivate_plugins('test-tn-shared/test-tn-shared.php',false,true);shared_assert(ghc_v1_row('test-tn-shared')['local_installed_state']==='inactive','deactivation updates local state');
 // Changing metadata under an unchanged tag is rejected, preserving the verified package.
 $old=tnuc_catalogue();file_put_contents($files['test-tn-shared'],'changed bytes');clearstatcache(true,$files['test-tn-shared']);
 $result=$run();shared_assert($result['status']==='partial'&&tnuc_catalogue()===$old,'invalid replacement preserves last good catalogue: '.json_encode($result).' equal='.(tnuc_catalogue()===$old?'yes':'no'));$write('test-tn-shared');
 $mode='limit';$result=$run();shared_assert($result['status']==='failed'&&tnuc_catalogue()===$old,'GitHub error preserves records and reports failure');
 $mode='good';$calls=[];$result=$run();shared_assert($result['status']==='complete'&&$count('api.github.com')>0,'immediate click after 403 retries without stored backoff');
 // Fifteen immediate releases: no sleeps, cooldown resets, or cache invalidation.
 for($i=2;$i<=16;$i++){$versions['test-tn-shared']='1.0.'.$i;$write('test-tn-shared');$calls=[];$result=$run();shared_assert($result['status']==='complete'&&ghc_v1_row('test-tn-shared')['version']===$versions['test-tn-shared']&&$count('/releases/download/')===1,'rapid release '.$i.' checked with one changed ZIP');}
 $new='test-added-shared';$versions[$new]='1.0.0';$files[$new]=wp_tempnam($new.'.zip');$z=new ZipArchive();$z->open($files[$new],ZipArchive::OVERWRITE);$z->addFromString("$new/$new.php","<?php\n/*\nPlugin Name: Added after first check\nAuthor: Techn\nVersion: 1.0.0\n*/");$z->close();clearstatcache(true,$files[$new]);$listed[]=$new;
 $calls=[];$result=$run();shared_assert($result['status']==='complete'&&count(ghc_v1_rows())===4&&isset(tnuc_catalogue()['plugins'][$new]),'new repository added after warm checks without registration');
 $before=count($calls);foreach(['tnuc','asuc'] as $p){($p.'_registry')();($p.'_project_updates')(new stdClass());ob_start();($p.'_render_admin')();ob_end_clean();($p.'_scheduled_check')();}
 shared_assert(count($calls)===$before,'ordinary pages, projection and obsolete cron remain HTTP-free');
 wp_set_current_user(0);shared_assert(is_wp_error(tnuc_begin_scan())&&is_wp_error(asuc_scan_step('bad')),'unauthorised checks refused');wp_set_current_user(1);
 $lock=ghc_v1_lock();shared_assert(is_wp_error(ghc_v1_begin()),'simultaneous database writers serialised');ghc_v1_unlock($lock);
 echo "Shared discovery assertions passed.\n";
} finally {
 wp_set_current_user(1);remove_filter('pre_http_request',$http,10);
 deactivate_plugins('test-tn-shared/test-tn-shared.php',true,true);@unlink($installed.'/test-tn-shared.php');@rmdir($installed);wp_clean_plugins_cache(false);
 $wpdb->query('DROP TABLE IF EXISTS `github-cchatterton`');asuc_put('catalogue',[]);tnuc_put('catalogue',[]);
 if($existed){ghc_v1_ensure_table();foreach($saved_rows as $row){$repo=$row['repo'];unset($row['repo']);ghc_v1_save($repo,$row);}}
 foreach($saved as $key=>$value){if($value===false){delete_site_option($key);}else{update_site_option($key,$value);}}
 foreach($files as $file){@unlink($file);}
}
