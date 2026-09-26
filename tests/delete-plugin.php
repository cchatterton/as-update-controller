<?php
if(DB_NAME!=='tnuc_test'){throw new RuntimeException('Disposable database only');}
wp_set_current_user(1);asuc_put('batch',[]);
$file='gf-sf-webhook/gf-sf-webhook.php';
asuc_plugin_action('gf-sf-webhook','deactivate');
$r=asuc_plugin_action('gf-sf-webhook','delete');
if(is_wp_error($r)||file_exists(WP_PLUGIN_DIR.'/'.$file)){throw new RuntimeException(is_wp_error($r)?$r->get_error_message():'Not deleted');}
echo "PASS real inactive plugin deletion\n";
