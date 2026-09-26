<?php
wp_set_current_user(1);$old=asuc_get('catalogue');$cat=$old;
foreach($cat['plugins'] as &$entry){$entry['version']='0.0.0';}unset($entry);
$cat['plugins']['gf-sf-webhook']['version']='99.0.0';asuc_put('catalogue',$cat);
try{
 $_GET['tab']='installed';ob_start();asuc_render_admin();$html=ob_get_clean();
 if(strpos($html,'<strong>GF SF Webhook</strong>')===false||strpos($html,'<strong>AS Local CSS</strong>')!==false){throw new RuntimeException('Update filtering failed');}
 echo "PASS first tab contains only newer installed releases\n";
}finally{asuc_put('catalogue',$old);}
$entry=asuc_registry()['as-ms-reporting'];
$old=asuc_get('catalogue');asuc_put('catalogue',[]);
try{if(!is_wp_error(asuc_verify_download(false,'https://github.com/cchatterton/as-ms-reporting/releases/download/v0.1.0/as-ms-reporting.zip',null))){throw new RuntimeException('Domain bypass with missing cache');}echo "PASS domain download restriction survives empty cache\n";}finally{asuc_put('catalogue',$old);}
