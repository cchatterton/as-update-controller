<?php
if (DB_NAME !== 'tnuc_test') { throw new RuntimeException('Disposable tnuc_test database required.'); }
wp_set_current_user(1);
function alpha_assert($ok,$label){if(!$ok){throw new RuntimeException($label);}echo "PASS $label\n";}
$http=0;add_filter('pre_http_request',function()use(&$http){$http++;return new WP_Error('blocked','No external requests during contract checks.');});
foreach(['gf-sf-webhook','raiven-connector','gravity-forms-data-retention-policy','as-content-stream'] as $slug){
 $file=$slug.'/'.$slug.'.php';$headers=get_plugin_data(WP_PLUGIN_DIR.'/'.$file);
 alpha_assert($headers['RequiresWP']==='7.0' && $headers['RequiresPHP']==='7.4',"$slug compatibility");
 $links=apply_filters('plugin_row_meta',[],$file,$headers,'all');$html=implode(' ',$links);
 alpha_assert(substr_count($html,'>GitHub<')===1 && strpos($html,'Check for updates')!==false,"$slug controller links");
 alpha_assert(strpos($html,'Visit plugin site')===false,"$slug no duplicate site link");
}
foreach(['GFSF_GitHub_Updater','GFDRP_GitHub_Updater','AS329_RAI_GitHub_Updater','AS_Content_Stream_GitHub_Updater'] as $class){alpha_assert(!class_exists($class),"$class removed");}
for($i=0;$i<3;$i++){get_site_transient('update_plugins');}
alpha_assert($http===0,'metadata reads and rows cause zero HTTP');
foreach(['asuc','tnuc'] as $prefix){
 $registry=($prefix.'_registry')();$groups=($prefix.'_catalogue_groups')($registry,[],get_plugins());
 alpha_assert(!isset($groups['beta']['as-ms-reporting']),'restricted reporting hidden');
 ob_start(); $_GET['tab']='installed';($prefix.'_render_admin')();$view=ob_get_clean();
 alpha_assert(strpos($view,'Updates available')!==false,'Updates available tab');
 alpha_assert(strpos($view,'Manage plugin')===false,'Manage plugin removed');
}
$e=asuc_registry()['as-ms-reporting'];
foreach(['alphasys.com.au'=>true,'stage.alphasys.com.au'=>true,'ALPHASYS.COM.AU.'=>true,'notalphasys.com.au'=>false,'alphasys.com.au.evil.test'=>false] as $host=>$expected){
 $filter=function()use($host){return 'https://'.$host.'/';};add_filter(is_multisite()?'network_home_url':'home_url',$filter);
 alpha_assert(asuc_domain_allowed($e)===$expected,'domain boundary '.$host);remove_filter(is_multisite()?'network_home_url':'home_url',$filter);
}
$active=is_multisite()?is_plugin_active_for_network('gf-sf-webhook/gf-sf-webhook.php'):is_plugin_active('gf-sf-webhook/gf-sf-webhook.php');
asuc_put('batch',[]);
$r=asuc_plugin_action('gf-sf-webhook','delete');alpha_assert(is_wp_error($r),'active deletion blocked');
$r=asuc_plugin_action('gf-sf-webhook','deactivate');alpha_assert(!is_wp_error($r),'deactivation works');
$r=asuc_plugin_action('gf-sf-webhook','activate');alpha_assert(!is_wp_error($r),'activation works');
$r=asuc_plugin_action('as-update-controller','deactivate');alpha_assert(is_wp_error($r),'self-deactivation routes to native screen');
if(is_multisite()){
 $site_ids=get_sites(['fields'=>'ids']);$sub=end($site_ids);
 deactivate_plugins('gf-sf-webhook/gf-sf-webhook.php',false,true);
 switch_to_blog($sub);activate_plugin('gf-sf-webhook/gf-sf-webhook.php');restore_current_blog();
 alpha_assert(is_wp_error(asuc_plugin_action('gf-sf-webhook','delete')),'deletion blocked by subsite activation');
 switch_to_blog($sub);deactivate_plugins('gf-sf-webhook/gf-sf-webhook.php',false,false);restore_current_blog();
 alpha_assert(!is_wp_error(asuc_plugin_action('gf-sf-webhook','activate')),'network reactivation');
 alpha_assert(asuc_available() && tnuc_available(),'controllers network active');
}
wp_set_current_user(0);alpha_assert(is_wp_error(asuc_plugin_action('gf-sf-webhook','deactivate')),'unauthorised action blocked');
