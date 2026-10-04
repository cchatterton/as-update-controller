<?php
/** Run only on disposable WordPress with both controllers active. No plugin activation hooks are invoked. */
wp_set_current_user(1);
function beta_assert($ok,$message){if(!$ok){throw new RuntimeException($message);}echo "PASS: $message\n";}
$stable=['wp-pattern-import','as-update-controller','tn-update-controller','help-guides','menubot','persona26','tn-authenticator','tn-content-planner','tn-environments','tn-pallet','tn-qrcodes','tn-user-management','tn-wp-migrate-code-diff','as-local-css','gf-sf-webhook','as-content-stream','raiven-connector','gravity-forms-data-retention-policy'];
$requests=0;$http=function()use(&$requests){$requests++;return new WP_Error('test_http','Unexpected metadata request');};add_filter('pre_http_request',$http,10,3);
$active=[];$filter=function()use(&$active){return $active;};add_filter('option_active_plugins',$filter);
$network_filter=static function()use(&$active){return array_fill_keys($active,1);};add_filter('site_option_active_sitewide_plugins',$network_filter);
try{
 foreach(['tnuc'=>'tn-update-controller','asuc'=>'as-update-controller'] as $prefix=>$slug){
  $registry=($prefix.'_registry')();$is_beta=$prefix.'_is_beta';$group=$prefix.'_catalogue_groups';$validate=$prefix.'_validate_catalogue';
  foreach($registry as $id=>$entry){beta_assert(is_bool($entry['beta']) && $entry['beta']===!in_array($id,$stable,true),"$prefix explicit readiness: $id");}
  $candidate=json_decode(file_get_contents((getenv(strtoupper($prefix).'_TEST_REPO') ?: dirname(constant(strtoupper($prefix).'_DIR'))).'/catalogue.json'),true);
  $validated=$validate($candidate);beta_assert(!is_wp_error($validated),"$prefix accepts current catalogue");
  $legacy=$candidate;foreach($legacy['plugins'] as &$e){unset($e['beta']);}unset($e);
  $fallback=$validate($legacy);beta_assert(!is_wp_error($fallback),"$prefix accepts older catalogue without beta field");
  foreach($fallback['plugins'] as $id=>$entry){beta_assert($entry['beta']===$registry[$id]['beta'],"$prefix legacy catalogue uses bundled status: $id");}
  foreach(['false',0,null,[]] as $value){$bad=$candidate;$bad['plugins'][0]['beta']=$value;beta_assert(is_wp_error($validate($bad)),"$prefix rejects malformed beta value");}
  $id=$prefix==='tnuc'?'tn-journey-graph':'as-qs-relay';
  if(!isset($registry[$id])){$id=$slug;}
  $known=$registry[$id];$plugins=[$known['file']=>['Version'=>'0.0.1']];
  $active=[];$groups=$group($registry,[],$plugins);
  beta_assert(isset($groups['installed'][$id]) && !isset($groups['active'][$id]),"$prefix inactive installed beta stays in Installed");
  $active=[$known['file']];$groups=$group($registry,[],$plugins);
  beta_assert(isset($groups['active'][$id]) && !isset($groups['beta'][$id]),"$prefix active beta moves only to Active");
  beta_assert(strpos(($prefix.'_beta_badge')($known),'Beta')!==false,"$prefix active beta retains visible chip");
  beta_assert(array_keys($groups)===['active','installed','available','beta'],"$prefix group order is stable");
  beta_assert(array_sum(array_map('count',$groups))===count(array_filter($registry, $prefix.'_domain_allowed')),"$prefix every card appears exactly once");
  $active=[];$promoted=$known;$promoted['beta']=false;$groups=$group($registry,[$id=>$promoted],$plugins);
  beta_assert(isset($groups['installed'][$id]) && !isset($groups['beta'][$id]),"$prefix explicit catalogue promotion keeps installed plugin in Installed");
  beta_assert(($prefix.'_beta_badge')($promoted)==='',"$prefix promoted entry has no beta chip");
  if($prefix==='tnuc'){
   $id='menubot';$active=[];$groups=$group($registry,[],[$registry[$id]['file']=>['Version'=>'1.0']]);beta_assert(isset($groups['installed'][$id]),'inactive migrated plugin is Installed');
   $active=[$registry[$id]['file']];$groups=$group($registry,[],[$registry[$id]['file']=>['Version'=>'1.0']]);beta_assert(isset($groups['active'][$id]),'active migrated plugin is Active');
  }
 }
}finally{remove_filter('site_option_active_sitewide_plugins',$network_filter);remove_filter('option_active_plugins',$filter);remove_filter('pre_http_request',$http,10);}
beta_assert($requests===0,'classification and rendering metadata use zero HTTP');
