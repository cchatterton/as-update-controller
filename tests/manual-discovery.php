<?php
/** Disposable WordPress only. Set ASUC_TEST_REPO and TNUC_TEST_REPO to the source repositories. */
wp_set_current_user(1);
function fast_assert($ok,$label){if(!$ok){throw new RuntimeException($label);}echo "PASS: $label\n";}
foreach(['tnuc'=>'Techn','asuc'=>'AlphaSys'] as $p=>$brand){
 $get=$p.'_get';$put=$p.'_put';$dispatch=$p.'_dispatch';$upper=strtoupper($p);
 $root=getenv($upper.'_TEST_REPO') ?: dirname(constant($upper.'_DIR'));
 $fixture=json_decode(file_get_contents($root.'/catalogue.json'),true);
 $saved=[];foreach(['catalogue','check','scan','ignored_repos','settings','manual_checks_version'] as $k){$saved[$k]=$get($k);}
 $old_plugins=wp_cache_get('plugins','plugins');$old_updates=get_site_transient('update_plugins');
 $suffix=substr(wp_generate_uuid4(),0,8);$repo=$p.'-new-capability-'.$suffix;$other=$p.'-other-author-'.$suffix;$file="$repo/$repo.php";
 $temp=wp_tempnam('fast-own.zip');$other_temp=wp_tempnam('fast-other.zip');$version='0.11.9';
 $write=static function($path,$slug,$author,$version){$z=new ZipArchive();$z->open($path,ZipArchive::OVERWRITE);$z->addFromString("$slug/$slug.php","<?php\n/*\nPlugin Name: Test capability\nAuthor: $author\nVersion: $version\nUpdate URI: https://github.com/cchatterton/$slug\n*/");$z->close();clearstatcache(true,$path);};
 $write($temp,$repo,$brand,$version);$write($other_temp,$other,'Different Author','1.0.0');
 $known=[];foreach($fixture['plugins'] as $e){$known[$e['repo']]=$e;}
 $calls=[];$mode='good';
 $clear=static function()use($known,$repo,$other,$p){
  foreach(array_merge(array_keys($known),[$repo,$other]) as $r){delete_site_transient($p.'_latest_'.hash('sha256',$r));delete_site_transient('auc_public_github_'.hash('sha256',"repos/cchatterton/$r/releases/latest"));}
  delete_site_transient('auc_public_github_'.hash('sha256','users/cchatterton/repos?per_page=100&type=owner&page=1'));
 };
 $http=static function($pre,$args,$url)use(&$calls,&$mode,&$version,$known,$repo,$other,$temp,$other_temp){
  $calls[]=$url;
  if(($args['method']??'GET')==='HEAD'){
   preg_match('#/cchatterton/([^/]+)/releases/latest$#',$url,$m);$r=rawurldecode($m[1]??'');
   $tag=$r===$repo?'v'.$version:($known[$r]['tag']??'');
   if(!$tag){throw new RuntimeException('Unexpected known identity '.$url);}
   return ['response'=>['code'=>302],'headers'=>['location'=>"https://github.com/cchatterton/$r/releases/tag/$tag"],'body'=>''];
  }
  if(strpos($url,'/repos?')!==false){
   if($mode==='limit'){return ['response'=>['code'=>403],'headers'=>['x-ratelimit-reset'=>(string)(time()+3600)],'body'=>''];}
   $rows=[];foreach([$repo,$other] as $r){$rows[]=['name'=>$r,'owner'=>['login'=>'cchatterton'],'private'=>false,'fork'=>false,'archived'=>false];}
   return ['response'=>['code'=>200],'headers'=>[],'body'=>json_encode($rows)];
  }
  if(strpos($url,'api.github.com/repos/')!==false){
   $is_other=strpos($url,'/'.$other.'/')!==false;$r=$is_other?$other:$repo;$path=$is_other?$other_temp:$temp;
   return ['response'=>['code'=>200],'headers'=>[],'body'=>json_encode(['draft'=>false,'prerelease'=>false,'tag_name'=>'v'.($is_other?'1.0.0':$version),'assets'=>[['name'=>$r.'.zip','id'=>uniqid(),'updated_at'=>uniqid(),'size'=>filesize($path),'digest'=>'sha256:'.hash_file('sha256',$path)]]])];
  }
  if(strpos($url,'/releases/download/')!==false && !empty($args['stream'])){copy(strpos($url,'/'.$other.'/')!==false?$other_temp:$temp,$args['filename']);return ['response'=>['code'=>200],'headers'=>[],'body'=>''];}
  throw new RuntimeException('Unexpected HTTP '.$url);
 };
 $run=static function($scan)use($dispatch){for($i=0;!is_wp_error($scan)&&$scan['status']==='running'&&$i<200;$i++){$scan=$dispatch('scan_step',['job'=>$scan['id']]);}return $scan;};
 $counts=static function($calls){return [count(array_filter($calls,static function($u){return strpos($u,'api.github.com')!==false;})),count(array_filter($calls,static function($u){return strpos($u,'/releases/download/')!==false;}))];};
 add_filter('pre_http_request',$http,10,3);
 try{
  $clear();delete_site_option('auc_github_retry_public');delete_site_option('auc_github_retry_public_failures');
  $put('catalogue',[]);$put('catalogue',($p.'_validate_catalogue')($fixture));$put('check',[]);$put('scan',[]);$put('ignored_repos',[]);
  $plugins=get_plugins();$plugins[$file]=['Name'=>'Test capability','Author'=>$brand,'Version'=>'0.11.7','UpdateURI'=>'https://github.com/cchatterton/'.$repo];wp_cache_set('plugins',[''=>$plugins],'plugins');
  $put('settings',['mode'=>'scheduled']);$put('manual_checks_version',0);wp_schedule_single_event(time()+600,$p.'_scheduled_check');($p.'_migrate_manual_checks')();($p.'_scheduled_check')();($p.'_refresh_on_native_forced_check')();
  ($p.'_registry')();($p.'_project_updates')(new stdClass());ob_start();($p.'_render_admin')();ob_end_clean();
  fast_assert(!$calls&&!wp_next_scheduled($p.'_scheduled_check'),"$p local rendering, projections and old cron perform zero HTTP");
  $result=$run($dispatch('check',[]));fast_assert($result['status']==='complete',"$p complete initial discovery");
  fast_assert(isset(($p.'_catalogue')()['plugins'][$repo])&&!isset(($p.'_catalogue')()['plugins'][$other]),"$p new own-author plugin discovered; other author excluded");
  fast_assert(($p.'_ignored_repo')($other,[]),"$p other-author classification persisted outside transient cache");
  fast_assert(get_site_transient('update_plugins')->response[$file]->new_version==='0.11.9',"$p installed update projected in same check");
  $bad=($p.'_catalogue')()['plugins'][$repo];$bad['sha256']=str_repeat('0',64);
  fast_assert(is_wp_error(($p.'_accept_discovered')($bad)),"$p changed bytes under an immutable tag are rejected");
  $before=count($calls);$dispatch('check',[]);fast_assert(count($calls)===$before,"$p successful check cooldown");
  $clear();$put('check',[]);$calls=[];$scan=$dispatch('check',[]);$dispatch('scan_step',['job'=>$scan['id']]);$resumed=$dispatch('check',[]);
  fast_assert($resumed['id']===$scan['id'],"$p interrupted check resumes only on explicit action");
  fast_assert($run($resumed)['status']==='complete'&&$counts($calls)===[1,0],"$p warm check uses one repository-list API call, zero release API calls and zero ZIPs");
  $warm=count($calls);fast_assert($warm===count($known)+2,"$p ignored author has no HTTP request or browser step");
  // Fifteen fresh versions at a simulated two-minute cadence: expire only time-based check caches, never identity state.
  $calls=[];
  for($i=1;$i<=15;$i++){
   $version='0.12.'.$i;$write($temp,$repo,$brand,$version);$clear();$put('check',[]);
   $result=$run($dispatch('check',[]));fast_assert($result['status']==='complete',"$p rapid release $i completes");
  }
  fast_assert($counts($calls)===[15,15],"$p fifteen changed releases use 15 API listings and exactly 15 ZIPs, no release API queries");
  fast_assert(get_site_transient('update_plugins')->response[$file]->new_version==='0.12.15',"$p rapid-release latest version retained");
  $clear();$put('check',[]);$mode='limit';$calls=[];$version='0.13.0';$write($temp,$repo,$brand,$version);
  $result=$run($dispatch('check',[]));fast_assert($result['status']==='partial'&&get_site_transient('update_plugins')->response[$file]->new_version==='0.13.0',"$p API limit defers new discovery but cannot block a known plugin update");
  $before=count($calls);fast_assert(is_wp_error(($p.'_github_json')('users/cchatterton/repos?per_page=100&type=owner&page=1'))&&count($calls)===$before,"$p API backoff remains enforced");
  fast_assert(!($p.'_ignored_repo')($other,['include'=>true]),"$p exception changes invalidate learned ignore");
  wp_set_current_user(0);$before=count($calls);fast_assert(is_wp_error($dispatch('check',[]))&&is_wp_error($dispatch('scan_step',['job'=>'invalid']))&&count($calls)===$before,"$p unauthorised checks perform no HTTP");wp_set_current_user(1);
 }finally{wp_set_current_user(1);remove_filter('pre_http_request',$http,10);$clear();delete_site_option('auc_github_retry_public');delete_site_option('auc_github_retry_public_failures');foreach($saved as $k=>$v){$put($k,$v);}wp_cache_set('plugins',$old_plugins,'plugins');set_site_transient('update_plugins',$old_updates);unlink($temp);unlink($other_temp);}
}
