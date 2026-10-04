<?php
/** Disposable WordPress only. Controller-first evaluation within one downloaded JSON. */
wp_set_current_user(1);
function first_assert($ok,$label){if(!$ok){throw new RuntimeException($label);}echo "PASS: $label\n";}
$saved=[];foreach(['tnuc_catalogue','asuc_catalogue','tnuc_check','asuc_check'] as $key){$saved[$key]=get_site_option($key);}
$feed=[];$calls=[];$url='';$http=static function($pre,$args,$request)use(&$feed,&$calls,&$url){$calls[]=$request;if(strtok($request,'?')!==$url){throw new RuntimeException('Unexpected remote request');}return ['response'=>['code'=>200],'headers'=>[],'body'=>json_encode($feed)];};
add_filter('pre_http_request',$http,10,3);
try{
 foreach(['tnuc'=>'tn-update-controller','asuc'=>'as-update-controller'] as $p=>$slug){
  $upper=strtoupper($p);$url=constant($upper.'_CATALOGUE_URL');$root=getenv($upper.'_TEST_REPO')?:dirname(constant($upper.'_DIR'));$feed=json_decode(file_get_contents($root.'/catalogue.json'),true);$current=$feed;
  foreach($feed['plugins'] as &$entry){if($entry['id']===$slug){$entry['version']='99.0.0';$entry['tag']='v99.0.0';$entry['sha256']=str_repeat('b',64);}}unset($entry);
  $feed['plugins'][]=['deliberately'=>'invalid other plugin must not be evaluated yet'];
  $before=($p.'_catalogue')()['plugins']??[];$calls=[];$result=($p.'_begin_scan')();
  first_assert(!is_wp_error($result)&&$result['status']==='controller_update'&&count($calls)===1,"$p one JSON; controller update stops other entry evaluation");
  first_assert(($p.'_catalogue')()['plugins'][$slug]['version']==='99.0.0',"$p controller update projected");
  foreach($before as $id=>$entry){if($id!==$slug){first_assert(($p.'_catalogue')()['plugins'][$id]===$entry,"$p cached entry preserved: $id");}}
  $_GET['tab']='installed';ob_start();($p.'_render_admin')();$html=ob_get_clean();first_assert(substr_count($html,'name="'.$p.'-selected"')===1,"$p only controller update listed");
  $feed=$current;$calls=[];$result=($p.'_begin_scan')();first_assert($result['status']==='complete'&&count($calls)===1,"$p current controller refreshes all entries from same one JSON");
  $prior=($p.'_catalogue')();$feed['plugins']=array_values(array_filter($feed['plugins'],static function($e)use($slug){return $e['id']!==$slug;}));$result=($p.'_begin_scan')();first_assert(is_wp_error($result)&&($p.'_catalogue')()===$prior,"$p missing controller rejected without losing catalogue");
 }
 echo "Controller-first catalogue assertions passed.\n";
}finally{remove_filter('pre_http_request',$http,10);unset($_GET['tab']);foreach($saved as $key=>$value){if($value===false){delete_site_option($key);}else{update_site_option($key,$value);}}}
