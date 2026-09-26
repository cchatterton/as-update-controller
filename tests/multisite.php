<?php
/** Disposable multisite with both controllers network-active and blog ID 2. */
if (!is_multisite()) { throw new RuntimeException('Multisite required'); }
function multi_assert($ok,$name){if(!$ok){throw new RuntimeException($name);}echo "PASS: $name\n";}
wp_set_current_user(1);
multi_assert(tnuc_available() && asuc_available(),'both network controllers available');
switch_to_blog(2);
tnuc_put('test_network','shared'); tnuc_dispatch('settings',['mode'=>'scheduled','hours'=>6]);
$lock=tnuc_lock('test_network');
restore_current_blog();
multi_assert(tnuc_get('test_network')==='shared','subsite and main site share controller state');
multi_assert(tnuc_lock('test_network')===false,'subsite and main site share atomic lock');tnuc_unlock('test_network',$lock);
multi_assert((bool)wp_next_scheduled('tnuc_scheduled_check'),'schedule stored on network main site');
switch_to_blog(2); multi_assert(!wp_next_scheduled('tnuc_scheduled_check'),'no duplicate subsite schedule');restore_current_blog();
$id=username_exists('subsite-manager');if(!$id){$id=wp_create_user('subsite-manager',wp_generate_password(),'subsite@example.test');}add_user_to_blog(2,$id,'administrator');
switch_to_blog(2);wp_set_current_user($id);
multi_assert(!tnuc_authorised() && is_wp_error(tnuc_start_batch(['tn-qrcodes'],'install')),'subsite admin cannot change network plugin files');
restore_current_blog();wp_set_current_user(1);
$network=get_site_option('active_sitewide_plugins');$without=$network;unset($without[plugin_basename(TNUC_FILE)]);update_site_option('active_sitewide_plugins',$without);
multi_assert(!tnuc_available(),'site-only loaded controller is not a network provider');update_site_option('active_sitewide_plugins',$network);
delete_site_option('tnuc_test_network');
echo "Multisite assertions passed.\n";
