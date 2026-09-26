<?php
/** Disposable WordPress only. Downloads two official packages and exercises native installation and updates. */
wp_set_current_user(1);
function verify_install($condition,$label){if(!$condition){throw new RuntimeException('FAIL: '.$label);}echo 'PASS: '.$label."\n";}
foreach (['tnuc'=>['menubot','tn-update-controller'],'asuc'=>['as-qs-relay','as-update-controller']] as $prefix=>$config) {
    [$id,$slug]=$config; $get=$prefix.'_get';$put=$prefix.'_put';$catalogue=$prefix.'_catalogue';$start=$prefix.'_start_batch';$step=$prefix.'_step_batch';$registry=$prefix.'_registry';
    $entry=$catalogue()['plugins'][$id];$file=$entry['file'];
    $put('batch',[]);
    $job=$start([$id],'install'); verify_install(!is_wp_error($job),'start official '.$id.' install');
    $job=$step($job['id']); if(is_wp_error($job)){throw new RuntimeException($job->get_error_message());}
    echo json_encode($job['items'])."\n";
    verify_install($job['status']==='complete','native checksum-verified '.$id.' installation');
    verify_install(!is_plugin_active($file),'installation does not silently activate '.$id);
    // Replace only this disposable installation with a minimal old-version fixture.
    file_put_contents(WP_PLUGIN_DIR.'/'.$file,"<?php\n/*\nPlugin Name: Test previous version\nVersion: 0.0.1\nAuthor: ".$entry['author']."\nUpdate URI: https://github.com/cchatterton/".$entry['repo']."\n*/\n");
    wp_clean_plugins_cache(false);$activated=activate_plugin($file,'',is_multisite());verify_install(!is_wp_error($activated),'activate old-version fixture');
    update_option('controller_preserve_settings_'.$id,['saved'=>'keep me']);
    $put('batch',[]);$job=$start([$id],'update');verify_install(!is_wp_error($job),'start '.$id.' upgrade');
    $job=$step($job['id']);echo json_encode($job['items'])."\n";
    verify_install($job['status']==='complete','native '.$id.' upgrade succeeds');
    verify_install(is_plugin_active($file)||is_plugin_active_for_network($file),'upgrade preserves activation for '.$id);
    verify_install(get_option('controller_preserve_settings_'.$id)===['saved'=>'keep me'],'upgrade preserves settings for '.$id);
    $repeat=$step($job['id']);verify_install($repeat['items']===$job['items'],'completed batch is not reinstalled');
}
echo "Native installation and migration assertions passed.\n";
