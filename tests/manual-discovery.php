<?php
/** Disposable WordPress only. Both controllers must be active. No live HTTP allowed. */
wp_set_current_user(1);
function direct_assert($ok, $label) { if (!$ok) { throw new RuntimeException($label); } echo "PASS: $label\n"; }
foreach (['tnuc' => 'Techn', 'asuc' => 'AlphaSys'] as $p => $brand) {
    $get = $p . '_get'; $put = $p . '_put'; $dispatch = $p . '_dispatch';
    $saved = []; foreach (['catalogue','check','scan','settings','manual_checks_version'] as $key) { $saved[$key] = $get($key); }
    $plugins_before = wp_cache_get('plugins','plugins'); $updates_before = get_site_transient('update_plugins');
    $repo = $p . '-new-capability'; $file = "$repo/$repo.php";
    $temp = wp_tempnam('direct-test.zip'); $zip = new ZipArchive(); $zip->open($temp, ZipArchive::OVERWRITE);
    $zip->addFromString($file, "<?php\n/*\nPlugin Name: Discovered capability\nAuthor: $brand\nVersion: 0.11.9\nUpdate URI: https://github.com/cchatterton/$repo\n*/"); $zip->close();
    $asset = ['id'=>42,'name'=>"$repo.zip",'size'=>filesize($temp),'updated_at'=>uniqid(),'digest'=>'sha256:'.hash_file('sha256',$temp)];
    $release = ['draft'=>false,'prerelease'=>false,'tag_name'=>'v0.11.9','assets'=>[$asset]];
    $list = [['name'=>$repo,'owner'=>['login'=>'cchatterton'],'private'=>false,'fork'=>false,'archived'=>false]];
    $paths = ['users/cchatterton/repos?per_page=100&type=owner&page=1',"repos/cchatterton/$repo/releases/latest"];
    $clear = static function () use ($paths) { foreach ($paths as $path) { delete_site_transient('auc_public_github_'.hash('sha256',$path)); } delete_site_option('auc_github_retry_public'); };
    $calls=0; $mode='ok'; $urls=[];
    $http = static function ($pre,$args,$url) use (&$calls,&$mode,&$urls,&$release,$list,$temp) {
        $calls++; $urls[]=$url;
        if (strpos($url,'https://api.github.com/')===0) {
            if ($mode==='limit' && strpos($url,'/releases/latest')!==false) { return ['response'=>['code'=>429],'headers'=>['retry-after'=>'600'],'body'=>'']; }
            return ['response'=>['code'=>200],'headers'=>[],'body'=>json_encode(strpos($url,'/repos?')!==false ? $list : $release)];
        }
        if (strpos($url,'/releases/download/')!==false && !empty($args['stream'])) { copy($temp,$args['filename']); return ['response'=>['code'=>200],'headers'=>[],'body'=>'']; }
        throw new RuntimeException('Unexpected HTTP '.$url);
    };
    add_filter('pre_http_request',$http,10,3);
    $run = static function ($scan) use ($dispatch) { for($i=0; !is_wp_error($scan) && $scan['status']==='running' && $i<20; $i++) { $scan=$dispatch('scan_step',['job'=>$scan['id']]); } return $scan; };
    try {
        $clear(); $put('catalogue',[]); $put('check',[]); $put('scan',[]);
        $plugins=get_plugins(); $plugins[$file]=['Name'=>'Discovered capability','Version'=>'0.11.7','Author'=>$brand,'UpdateURI'=>'https://github.com/cchatterton/'.$repo]; wp_cache_set('plugins',[''=>$plugins],'plugins');
        $put('settings',['mode'=>'scheduled','hours'=>6]); $put('manual_checks_version',0); wp_schedule_single_event(time()+600,$p.'_scheduled_check'); ($p.'_migrate_manual_checks')();
        ($p.'_scheduled_check')(); ($p.'_refresh_on_native_forced_check')();
        direct_assert(is_wp_error(($p.'_refresh')(false)) && $calls===0 && !wp_next_scheduled($p.'_scheduled_check'),"$p no background discovery or obsolete schedule");
        ($p.'_registry')(); ($p.'_project_updates')(new stdClass()); ob_start(); ($p.'_render_admin')(); ob_end_clean();
        direct_assert($calls===0,"$p cold rendering and projections are local");
        $result=$run($dispatch('check',[]));
        direct_assert(!is_wp_error($result) && $result['status']==='complete',"$p direct check completes");
        direct_assert(isset(($p.'_catalogue')()['plugins'][$repo]) && $calls===3,"$p new unregistered plugin discovered without feed");
        direct_assert(get_site_transient('update_plugins')->response[$file]->new_version==='0.11.9',"$p same check projects installed 0.11.7 to released 0.11.9");
        $before=$calls; $dispatch('check',[]); direct_assert($calls===$before,"$p completed check cooldown");
        $last=($p.'_catalogue')(); $put('scan',[]); $put('check',[]); $clear(); $mode='limit';
        $paused=$run($dispatch('check',[]));
        direct_assert($paused['status']==='paused' && ($p.'_catalogue')()===$last,"$p quota interruption preserves verified catalogue");
        $before=$calls; direct_assert(is_wp_error($dispatch('check',[])) && $calls===$before,"$p retry deadline prevents more HTTP");
        $scan=$get('scan'); $scan['retry_at']=0; $put('scan',$scan); $clear(); $mode='ok';
        $resumed=$dispatch('check',[]); direct_assert($resumed['id']===$paused['id'],"$p next explicit click resumes cursor");
        direct_assert($run($resumed)['status']==='complete',"$p resumed check completes");
        $entry=($p.'_catalogue')()['plugins'][$repo]; $bad=$entry; $bad['author']=$brand==='Techn'?'AlphaSys':'Techn';
        direct_assert(is_wp_error(($p.'_accept_discovered')($bad)) && ($p.'_catalogue')()===$last,"$p wrong brand cannot replace verified entry");
        $bad=$entry; $bad['file']='other/other.php'; direct_assert(is_wp_error(($p.'_accept_discovered')($bad)),"$p identity drift rejected");
        $badRelease=$release; $badRelease['tag_name']='v0.12.0'; $inspected=($p.'_inspect_zip')($temp,$repo,$badRelease,$asset,[]);
        direct_assert(is_wp_error(($p.'_accept_discovered')($inspected)),"$p tag and packaged version mismatch rejected");
        $wrongBrand=($p.'_inspect_zip')($temp,$repo,$release,$asset,['author_header'=>'Unrelated Publisher']);
        direct_assert($wrongBrand===null,"$p released package author must match");
        $unsafe=wp_tempnam('unsafe.zip'); $z=new ZipArchive(); $z->open($unsafe,ZipArchive::OVERWRITE); $z->addFromString('../escape.php','<?php'); $z->close();
        direct_assert(is_wp_error(($p.'_inspect_zip')($unsafe,$repo,$release,$asset,[])),"$p unsafe archive path rejected before acceptance"); unlink($unsafe);
        $before=$calls; ($p.'_apply_exclusions')([$repo=>['exclude'=>true]]);
        direct_assert(!isset(($p.'_catalogue')()['plugins'][$repo]) && !isset(get_site_transient('update_plugins')->response[$file]) && $calls===$before,"$p explicit withdrawal removes only local owned entry without HTTP");
        if ($p === 'tnuc') {
            // A cold incremental scan has not yet loaded this reviewed, nonstandard identity.
            $legacy=array_merge($entry,tnuc_bundled_registry()['help-guides']);
            direct_assert(!is_wp_error(tnuc_accept_discovered($legacy)),"tnuc retains bundled legacy identity pins during incremental cold scan");
        }
        $badAsset=$asset; $badAsset['digest']='sha256:'.str_repeat('0',64); $badAsset['id']=43;
        direct_assert(is_wp_error(($p.'_inspect_release')($repo,$release,$badAsset,[])),"$p release checksum mismatch rejected");
        wp_set_current_user(0); $before=$calls;
        direct_assert(is_wp_error($dispatch('check',[])) && is_wp_error($dispatch('scan_step',['job'=>$resumed['id']])) && $calls===$before,"$p unauthorised actions perform no HTTP"); wp_set_current_user(1);
        direct_assert(!wp_next_scheduled($p.'_scheduled_check'),"$p no check scheduled after success");
    } finally { wp_set_current_user(1); remove_filter('pre_http_request',$http,10); $clear(); foreach($saved as $key=>$value){$put($key,$value);} wp_cache_set('plugins',$plugins_before,'plugins'); set_site_transient('update_plugins',$updates_before); unlink($temp); }
}
