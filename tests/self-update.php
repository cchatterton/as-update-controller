<?php
/** Disposable COPY of both controllers, with main-file versions changed to 0.0.1 (or CONTROLLER_TEST_FROM set to the actual old release); never symlinks into source. */
wp_set_current_user(1);
function self_assert($ok,$label){if(!$ok){throw new RuntimeException($label);}echo "PASS: $label\n";}
foreach (['tnuc'=>'tn-update-controller','asuc'=>'as-update-controller'] as $prefix=>$slug) {
 $file=$slug.'/'.$slug.'.php';
 self_assert(!is_link(WP_PLUGIN_DIR.'/'.$slug),'self-update uses disposable copy: '.$slug);
 self_assert(get_plugins()[$file]['Version']===(getenv('CONTROLLER_TEST_FROM') ?: '0.0.1'),'old-version fixture present: '.$slug);
 ($prefix.'_put')('check',[]);
 $result=($prefix.'_refresh')(); self_assert(!is_wp_error($result),'live published catalogue fetch: '.$slug);
 $catalogue=($prefix.'_catalogue')();self_assert(isset($catalogue['plugins'][$slug]),'controller included in its own catalogue');
 ($prefix.'_put')('self_test_setting','preserve');
 $job=($prefix.'_start_batch')([$slug],'update'); self_assert(!is_wp_error($job),'self-update batch starts');
 $job=($prefix.'_step_batch')($job['id']);
 if(is_wp_error($job)){throw new RuntimeException($job->get_error_message());}
 self_assert($job['status']==='complete','official controller ZIP self-update completes');
 self_assert(get_plugins()[$file]['Version']===$catalogue['plugins'][$slug]['version'],'installed controller version verified');
 self_assert(is_plugin_active_for_network($file),'network activation preserved');
 self_assert(($prefix.'_get')('self_test_setting')==='preserve','controller state preserved');
 delete_site_option($prefix.'_self_test_setting');
}
echo "Live controller self-update assertions passed.\n";
