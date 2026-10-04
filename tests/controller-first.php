<?php
/** Disposable WordPress only, both controllers active. */
wp_set_current_user(1);
function first_assert($ok,$label){if(!$ok){throw new RuntimeException($label);}echo "PASS: $label\n";}
global $wpdb;$existed=ghc_v2_exists();$rows=ghc_v2_rows();$saved=[];
foreach(['tnuc_catalogue','asuc_catalogue','tnuc_check','asuc_check','ghc_v2_scan'] as $key){$saved[$key]=get_site_option($key);}
$old_updates=get_site_transient('update_plugins');$tmp=wp_tempnam('controller-first.zip');$calls=[];$mode='new';$repo='';$brand='';$version='';
$zip=static function()use($tmp,&$repo,&$brand,&$version){$z=new ZipArchive();$z->open($tmp,ZipArchive::OVERWRITE);$z->addFromString("$repo/$repo.php","<?php\n/*\nPlugin Name: Controller test\nAuthor: $brand\nVersion: $version\nUpdate URI: https://github.com/cchatterton/$repo\n*/");$z->close();clearstatcache(true,$tmp);};
$http=static function($pre,$args,$url)use(&$calls,&$mode,&$repo,&$version,$tmp){
 $calls[]=$url;
 if($url==='https://api.github.com/repos/cchatterton/'.$repo.'/releases/latest'){
  if($mode==='error'){return ['response'=>['code'=>403],'headers'=>[],'body'=>''];}
  return ['response'=>['code'=>200],'headers'=>[],'body'=>json_encode(['tag_name'=>'v'.$version,'draft'=>false,'prerelease'=>false,'assets'=>[['name'=>$repo.'.zip','size'=>filesize($tmp),'digest'=>'sha256:'.hash_file('sha256',$tmp)]]])];
 }
 if(strpos($url,'https://github.com/cchatterton/'.$repo.'/releases/download/')===0){copy($tmp,$args['filename']);return ['response'=>['code'=>200],'headers'=>[],'body'=>''];}
 if($mode==='current'&&strpos($url,'https://api.github.com/users/cchatterton/repos?')===0){return ['response'=>['code'=>200],'headers'=>[],'body'=>'[]'];}
 throw new RuntimeException('Other repository requested before controller was current: '.$url);
};
$run=static function($prefix){$scan=($prefix.'_begin_scan')();for($i=0;!is_wp_error($scan)&&$scan['status']==='running'&&$i<30;$i++){$scan=($prefix.'_scan_step')($scan['id']);}return $scan;};
add_filter('pre_http_request',$http,10,3);
try{
 foreach(['tnuc'=>'Techn','asuc'=>'AlphaSys'] as $prefix=>$brand){
  $repo=$prefix==='tnuc'?'tn-update-controller':'as-update-controller';$version='99.0.0';$mode='new';$calls=[];$zip();
  $wpdb->query('DROP TABLE IF EXISTS `github-cchatterton`');($prefix.'_put')('catalogue',[]);ghc_v2_ensure_table();$wpdb->delete(ghc_v2_table(),['repo'=>$repo]);
  $result=$run($prefix);
  first_assert($result['status']==='controller_update', "$prefix stops for verified controller update: ".json_encode($result));
  first_assert(count($calls)===2&&$calls[0]==='https://api.github.com/repos/cchatterton/'.$repo.'/releases/latest', "$prefix checks only its own API and ZIP; no listing or other repository");
  first_assert(($prefix.'_catalogue')()['plugins'][$repo]['version']==='99.0.0', "$prefix controller update listed in catalogue");
  first_assert(get_site_transient('update_plugins')->response[$repo.'/'.$repo.'.php']->new_version==='99.0.0', "$prefix native update projected");
  first_assert(empty(($prefix.'_get')('check')['last_success']), "$prefix does not claim other plugins were checked");
  $_GET['tab']='installed';ob_start();($prefix.'_render_admin')();$html=ob_get_clean();
  first_assert(strpos($html,'name="'.$prefix.'-selected" value="'.$repo.'"')!==false&&substr_count($html,'name="'.$prefix.'-selected"')===1,"$prefix lists only the controller in Updates available");
  $before=($prefix.'_catalogue')();$calls=[];$mode='error';$result=$run($prefix);
  first_assert($result['status']==='failed'&&count($calls)===1&&($prefix.'_catalogue')()===$before,"$prefix failed initial lookup stops and preserves verified update");
  $version=constant(strtoupper($prefix).'_VERSION');$mode='current';$calls=[];$zip();$result=$run($prefix);
  first_assert($result['status']==='complete'&&count(array_filter($calls,static function($u){return strpos($u,'/users/cchatterton/repos?')!==false;}))===1,"$prefix current controller proceeds to discovery on immediate next click");
 }
 echo "Controller-first assertions passed.\n";
}finally{
 remove_filter('pre_http_request',$http,10);@unlink($tmp);unset($_GET['tab']);
 $wpdb->query('DROP TABLE IF EXISTS `github-cchatterton`');asuc_put('catalogue',[]);tnuc_put('catalogue',[]);
 if($existed){ghc_v2_ensure_table();foreach($rows as $row){$repo=$row['repo'];unset($row['repo']);ghc_v2_save($repo,$row);}}
 foreach($saved as $key=>$value){if($value===false){delete_site_option($key);}else{update_site_option($key,$value);}}set_site_transient('update_plugins',$old_updates);
}
