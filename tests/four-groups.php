<?php
if(DB_NAME!=='tnuc_test'){throw new RuntimeException('Disposable database required');}
wp_set_current_user(1);
function groups_assert($ok,$message){if(!$ok){throw new RuntimeException($message);}echo "PASS $message\n";}
$requests=0;add_filter('pre_http_request',function()use(&$requests){$requests++;return new WP_Error('test','Unexpected HTTP');});
foreach(['asuc'=>'gravity-forms-data-retention-policy','tnuc'=>'menubot'] as $pre=>$id){
 $registry=($pre.'_registry')();$entry=$registry[$id];$file=$entry['file'];$action=$pre.'_plugin_action';$group=$pre.'_catalogue_groups';$dispatch=$pre.'_dispatch';
 ($pre.'_put')('batch',[]);
 $original=is_multisite()?is_plugin_active_for_network($file):is_plugin_active($file);
 try{
  $r=$action($id,'deactivate');groups_assert(!is_wp_error($r),'deactivate '.$id);
  $groups=$group($registry,[],get_plugins());groups_assert(isset($groups['installed'][$id]),'inactive goes into Installed '.$id);
  groups_assert(array_keys($groups)===['active','installed','available','beta'],'four groups in requested order');
  $view=$dispatch('view',['tab'=>'catalogue']);
  groups_assert(!is_wp_error($view)&&strpos($view['html'],'data-plugin-action="activate" data-plugin-id="'.$id.'"')!==false,'inactive CTA Activate');
  groups_assert(strpos($view['html'],'data-plugin-action="delete" data-plugin-id="'.$id.'"')!==false,'inactive CTA Delete');
  groups_assert(strpos($view['html'],'data-install="'.$id.'"')===false,'installed card has no Install or Update CTA');
  $r=$action($id,'activate');groups_assert(!is_wp_error($r),'activate '.$id);
  $groups=$group($registry,[],get_plugins());groups_assert(isset($groups['active'][$id])&&!isset($groups['installed'][$id]),'activation moves into Active '.$id);
  $view=$dispatch('view',['tab'=>'catalogue']);
  groups_assert(strpos($view['html'],'data-plugin-action="deactivate" data-plugin-id="'.$id.'"')!==false,'refreshed CTA Deactivate');
  groups_assert(strpos($view['html'],'data-plugin-action="activate" data-plugin-id="'.$id.'"')===false,'refreshed Activate removed');
  groups_assert(strpos($view['html'],'data-plugin-action="delete" data-plugin-id="'.$id.'"')===false,'active card has no Delete');
  $fake=$entry;$fake['beta']=true;$action($id,'deactivate');$groups=$group($registry,[$id=>$fake],get_plugins());groups_assert(isset($groups['installed'][$id]),'beta installed goes into Installed');
 }finally{if($original){activate_plugin($file,'',is_multisite());}else{deactivate_plugins($file,false,is_multisite());}}
}
groups_assert($requests===0,'view refresh and grouping perform zero HTTP');
wp_set_current_user(0);groups_assert(is_wp_error(asuc_dispatch('view',['tab'=>'catalogue'])),'unauthorised view rejected');
