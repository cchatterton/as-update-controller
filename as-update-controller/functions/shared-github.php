<?php
if (!defined('ABSPATH')) { exit; }
// Identical in both controllers. Versioned functions make plugin load order immaterial.
if (!function_exists('ghc_v1_table')) {
function ghc_v1_table(): string { return 'github-cchatterton'; }
function ghc_v1_exists(): bool {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(ghc_v1_table()))) === ghc_v1_table();
}
function ghc_v1_ensure_table() {
    global $wpdb;
    if (ghc_v1_exists()) { return true; }
    $sql = 'CREATE TABLE IF NOT EXISTS `github-cchatterton` (
      repo varchar(100) NOT NULL,
      author varchar(255) NOT NULL DEFAULT \'\',
      brand varchar(16) NOT NULL DEFAULT \'\',
      version varchar(64) NOT NULL DEFAULT \'\',
      local_version varchar(64) NOT NULL DEFAULT \'\',
      local_installed_state varchar(24) NOT NULL DEFAULT \'not_installed\',
      alpha_beta varchar(8) NOT NULL DEFAULT \'beta\',
      plugin_file varchar(255) NOT NULL DEFAULT \'\',
      release_tag varchar(128) NOT NULL DEFAULT \'\',
      fingerprint varchar(64) NOT NULL DEFAULT \'\',
      status varchar(24) NOT NULL DEFAULT \'unknown\',
      package_data longtext NULL,
      release_data longtext NULL,
      last_checked datetime NULL,
      last_error text NULL,
      PRIMARY KEY (repo)
    ) ' . $wpdb->get_charset_collate();
    if ($wpdb->query($sql) === false || !ghc_v1_exists()) { return new WP_Error('table', 'Could not create the shared github-cchatterton table.'); }
    // Preserve already verified metadata on upgrade; the first API pass still validates every version.
    foreach (['asuc','tnuc'] as $prefix) {
        foreach ((array) (get_site_option($prefix . '_catalogue', [])['plugins'] ?? []) as $entry) {
            if (!is_array($entry) || ($entry['owner'] ?? '') !== 'cchatterton' || !in_array($entry['author'] ?? '', ['AlphaSys','Techn'], true)) { continue; }
            ghc_v1_save($entry['repo'], ['author' => $entry['author'], 'brand' => $entry['author'], 'version' => $entry['version'], 'plugin_file' => $entry['file'], 'alpha_beta' => empty($entry['beta']) ? 'alpha' : 'beta', 'release_tag' => $entry['tag'], 'status' => 'verified', 'package_data' => wp_json_encode($entry)]);
        }
    }
    return true;
}
function ghc_v1_row(string $repo): array {
    global $wpdb;
    return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM `github-cchatterton` WHERE repo = %s', $repo), ARRAY_A);
}
function ghc_v1_rows(): array {
    global $wpdb;
    if (!ghc_v1_exists()) { return []; }
    return (array) $wpdb->get_results('SELECT * FROM `github-cchatterton` ORDER BY repo', ARRAY_A);
}
function ghc_v1_save(string $repo, array $fields) {
    global $wpdb;
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,99}$/D', $repo)) { throw new RuntimeException('Invalid repository name.'); }
    $exists = ghc_v1_row($repo);
    if ($exists && !$fields) { return true; }
    $result = $exists ? $wpdb->update(ghc_v1_table(), $fields, ['repo' => $repo]) : $wpdb->insert(ghc_v1_table(), array_merge(['repo' => $repo], $fields));
    if ($result === false) { throw new RuntimeException('Could not save repository ' . $repo . '.'); }
    return true;
}
function ghc_v1_rules(): array {
    $rules = [];
    foreach (['as-update-controller'=>'AlphaSys','tn-update-controller'=>'Techn'] as $slug=>$brand) {
        $path = WP_PLUGIN_DIR . '/' . $slug . '/data/registry.json';
        if (!is_readable($path)) { continue; }
        foreach ((array) json_decode((string) file_get_contents($path), true) as $key=>$rule) {
            if (is_array($rule)) { $rules[$rule['repo'] ?? $key] = array_merge($rule, ['brand' => $brand]); }
        }
    }
    return $rules;
}
function ghc_v1_token(): string {
    foreach (['GITHUB_CCHATTERTON_TOKEN','ASUC_GITHUB_TOKEN','TNUC_GITHUB_TOKEN'] as $name) {
        if (defined($name) && (string) constant($name) !== '') { return (string) constant($name); }
    }
    return '';
}
/** Every explicit scan fetches fresh API data. No TTL, cooldown, stored backoff or silent fallback. */
function ghc_v1_api(string $path, ?array $payload = null) {
    if ($path !== 'graphql' && !preg_match('#^(users/cchatterton/repos\?per_page=100&type=owner&page=\d+|repos/cchatterton/[a-zA-Z0-9_.-]+/releases/latest)$#D', $path)) { return new WP_Error('api_path', 'Invalid GitHub API path.'); }
    $headers = ['Accept'=>'application/vnd.github+json','User-Agent'=>'GitHub-cchatterton-Controller','X-GitHub-Api-Version'=>'2022-11-28','Cache-Control'=>'no-cache'];
    $token = ghc_v1_token(); if ($token !== '') { $headers['Authorization'] = 'Bearer ' . $token; }
    $args = ['timeout'=>15,'redirection'=>0,'limit_response_size'=>4194304,'headers'=>$headers];
    if ($payload !== null) { $args['method']='POST'; $args['body']=wp_json_encode($payload); $args['headers']['Content-Type']='application/json'; }
    $response = wp_safe_remote_request('https://api.github.com/' . $path, $args);
    if (is_wp_error($response)) { return new WP_Error('github_http', 'GitHub could not be reached. Previous records are retained; another click will try again.'); }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code === 404 && strpos($path, '/releases/latest') !== false) { return ['missing'=>true]; }
    if ($code !== 200) {
        $message = 'GitHub declined this request (HTTP ' . $code . ').';
        if ($code === 403 || $code === 429) { $message .= ' GitHub may be limiting API requests. The controller has not imposed a waiting period.'; }
        if ($code === 401) { $message .= ' Check the configured GitHub token.'; }
        return new WP_Error('github_http', $message . ' Previous records are retained.');
    }
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($data) || !empty($data['errors'])) { return new WP_Error('github_data', 'GitHub returned incomplete API data. Previous records are retained.'); }
    return $data;
}
function ghc_v1_page(array $scan) {
    if ($scan['transport'] === 'rest') { return ghc_v1_api('users/cchatterton/repos?per_page=100&type=owner&page=' . (int) $scan['page']); }
    $query = 'query($cursor:String) { user(login:"cchatterton") { repositories(first:100,after:$cursor,privacy:PUBLIC,ownerAffiliations:OWNER) { pageInfo { hasNextPage endCursor } nodes { name isFork isArchived latestRelease { tagName isDraft isPrerelease updatedAt releaseAssets(first:100) { pageInfo { hasNextPage } nodes { name size updatedAt id digest } } } } } } }';
    return ghc_v1_api('graphql', ['query'=>$query,'variables'=>['cursor'=>$scan['cursor']]]);
}
function ghc_v1_lock() {
    $run = static function () {
        global $wpdb;
        $key='ghc_v1_scan_lock'; $old=get_option($key);
        if (is_array($old) && ($old['expires']??0)<time()) { $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$key,maybe_serialize($old)));wp_cache_delete($key,'options'); }
        $token=wp_generate_uuid4();return add_option($key,['token'=>$token,'expires'=>time()+90],'',false)?$token:false;
    };
    return ghc_v1_main($run);
}
function ghc_v1_main(callable $run) {
    $switch=is_multisite()&&get_current_blog_id()!==get_main_site_id();if($switch){switch_to_blog(get_main_site_id());}
    try{return $run();}finally{if($switch){restore_current_blog();}}
}
function ghc_v1_unlock(string $token): void {
    ghc_v1_main(static function()use($token){global $wpdb;$key='ghc_v1_scan_lock';$old=get_option($key);if(is_array($old)&&hash_equals((string)$old['token'],$token)){$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$key,maybe_serialize($old)));wp_cache_delete($key,'options');}});
}
function ghc_v1_public(array $scan): array {
    return ['id'=>$scan['id'],'status'=>$scan['status'],'done'=>$scan['index'],'total'=>count($scan['repos']),'message'=>$scan['message']];
}
function ghc_v1_begin() {
    $lock=ghc_v1_lock();if(!$lock){return new WP_Error('busy','Another check step is currently writing repository records. Try again when it finishes.');}
    try {
        $ready=ghc_v1_ensure_table();if(is_wp_error($ready)){return $ready;}
        $scan=['id'=>wp_generate_uuid4(),'status'=>'running','transport'=>ghc_v1_token()!==''?'graphql':'rest','phase'=>'list','page'=>1,'cursor'=>null,'repos'=>[],'index'=>0,'warnings'=>[],'message'=>'Checking GitHub repository versions…'];
        ghc_v1_sync_local();update_site_option('ghc_v1_scan',$scan);return ghc_v1_public($scan);
    }finally{ghc_v1_unlock($lock);}
}
function ghc_v1_normalise_release(array $release): array {
    $assets=[];
    foreach($release['releaseAssets']['nodes']??[] as $asset){$assets[]=['name'=>$asset['name']??null,'size'=>$asset['size']??null,'id'=>$asset['id']??null,'updated_at'=>$asset['updatedAt']??null,'digest'=>$asset['digest']??null];}
    return ['tag_name'=>$release['tagName']??null,'draft'=>$release['isDraft']??null,'prerelease'=>$release['isPrerelease']??null,'assets'=>$assets,'assets_truncated'=>!empty($release['releaseAssets']['pageInfo']['hasNextPage'])];
}
/** Metadata fingerprint includes release asset revision/digest, not just the version string. */
function ghc_v1_fingerprint(array $release,array $rule): string {
    $assets=[];foreach($release['assets']??[] as $a){$assets[]=['name'=>$a['name']??'','size'=>$a['size']??0,'id'=>$a['node_id']??$a['id']??'','updated_at'=>$a['updated_at']??'','digest'=>$a['digest']??''];}
    usort($assets,static function($a,$b){return strcmp($a['name'],$b['name']);});
    return hash('sha256',wp_json_encode([$release['tag_name']??'',$release['draft']??false,$release['prerelease']??false,$assets,$rule]));
}
function ghc_v1_step(string $id) {
    $lock=ghc_v1_lock();if(!$lock){return new WP_Error('busy','Another check step is currently writing repository records.');}
    try {
        $scan=(array)get_site_option('ghc_v1_scan',[]);
        if(!$id||($scan['id']??'')!==$id){return new WP_Error('scan_missing','A newer check has started. Use its results or start another check.');}
        if($scan['status']!=='running'){return ghc_v1_public($scan);}
        $rules=ghc_v1_rules();$deadline=microtime(true)+2;
        for($step=0;$step<25 && microtime(true)<$deadline && $scan['status']==='running';$step++){
            if($scan['phase']==='list'){
                $page=ghc_v1_page($scan);if(is_wp_error($page)){throw new RuntimeException($page->get_error_message());}
                if($scan['transport']==='graphql'){
                    $connection=$page['data']['user']['repositories']??null;
                    if(!is_array($connection)||!is_array($connection['nodes']??null)||!is_bool($connection['pageInfo']['hasNextPage']??null)){throw new RuntimeException('GitHub returned an invalid repository page.');}
                    $nodes=$connection['nodes'];$more=$connection['pageInfo']['hasNextPage'];$next=$connection['pageInfo']['endCursor']??null;
                    if($more&&(!is_string($next)||$next===$scan['cursor'])){throw new RuntimeException('GitHub returned an invalid page cursor.');}
                }else{$nodes=$page;$more=count($nodes)===100;$next=null;}
                foreach($nodes as $node){
                    $graphql=$scan['transport']==='graphql';$repo=$node['name']??'';
                    if(!is_string($repo)||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,99}$/D',$repo)){throw new RuntimeException('Invalid GitHub repository identity.');}
                    if(!$graphql && (($node['owner']['login']??'')!=='cchatterton'||($node['private']??true)!==false)){throw new RuntimeException('GitHub returned a repository outside the public owner scope.');}
                    if($graphql&&(!array_key_exists('latestRelease',$node)||!is_bool($node['isFork']??null)||!is_bool($node['isArchived']??null))){throw new RuntimeException('Incomplete GitHub repository metadata.');}
                    $rule=$rules[$repo]??[];$excluded=!empty($rule['exclude'])||!empty($rule['superseded_by'])||((!empty($node[$graphql?'isFork':'fork'])||!empty($node[$graphql?'isArchived':'archived']))&&empty($rule['include']));
                    $saved=ghc_v1_save($repo,[]);if(is_wp_error($saved)){throw new RuntimeException($saved->get_error_message());}
                    $scan['repos'][]=['repo'=>$repo,'excluded'=>$excluded,'release'=>$graphql?(isset($node['latestRelease'])?ghc_v1_normalise_release($node['latestRelease']):['missing'=>true]):null];
                }
                $scan['page']++;$scan['cursor']=$next;
                if(!$more){$scan['phase']='repos';}
                // Pages are fetched once per user click; no stored throttle or timed author skip.
                break;
            }
            if($scan['index']>=count($scan['repos'])){
                ghc_v1_sync_local();$scan['status']=$scan['warnings']?'partial':'complete';
                $scan['message']=$scan['warnings']?'Repository check finished with errors: '.implode('; ',$scan['warnings']):'Repository versions and local plugin state checked.';
                break;
            }
            $item=&$scan['repos'][$scan['index']];$repo=$item['repo'];$rule=$rules[$repo]??[];$row=ghc_v1_row($repo);
            if($item['release']===null){$item['release']=ghc_v1_api('repos/cchatterton/'.$repo.'/releases/latest');if(is_wp_error($item['release'])){$message=$item['release']->get_error_message();$item['release']=null;ghc_v1_save($repo,['last_error'=>$message]);throw new RuntimeException($message);}}
            $release=$item['release'];
            if($item['excluded']){ghc_v1_save($repo,['status'=>'excluded','version'=>ltrim($release['tag_name']??'','vV'),'release_tag'=>$release['tag_name']??'','last_checked'=>gmdate('Y-m-d H:i:s'),'last_error'=>'']);$scan['index']++;unset($item);continue;}
            if(!empty($release['missing'])){
                ghc_v1_save($repo,['status'=>empty($row['package_data'])?'no_release':'error','last_checked'=>gmdate('Y-m-d H:i:s'),'last_error'=>empty($row['package_data'])?'':'Published release is unavailable. Previous package retained.']);
                if(!empty($row['package_data'])){$scan['warnings'][]=$repo.': published release is unavailable.';}
                $scan['index']++;unset($item);continue;
            }
            if(!is_string($release['tag_name']??null)||!is_bool($release['draft']??null)||!is_bool($release['prerelease']??null)||!is_array($release['assets']??null)||!empty($release['assets_truncated'])){throw new RuntimeException('Incomplete release metadata for '.$repo.'.');}
            if($release['draft']||$release['prerelease']){ghc_v1_save($repo,['status'=>'no_stable_release','last_checked'=>gmdate('Y-m-d H:i:s')]);$scan['index']++;unset($item);continue;}
            $fingerprint=ghc_v1_fingerprint($release,$rule);
            $fields=['version'=>ltrim($release['tag_name'],'vV'),'release_tag'=>$release['tag_name'],'release_data'=>wp_json_encode($release),'last_checked'=>gmdate('Y-m-d H:i:s'),'last_error'=>''];
            if(($row['fingerprint']??'')===$fingerprint&&in_array($row['status']??'',['verified','not_plugin'],true)){ghc_v1_save($repo,$fields);$scan['index']++;unset($item);continue;}
            if(!isset($item['assets'])){
                $item['assets']=array_values(array_filter($release['assets'],static function($a)use($rule){return is_array($a)&&is_string($a['name']??null)&&substr($a['name'],-4)==='.zip'&&(empty($rule['asset'])||$rule['asset']===$a['name']);}));
                $item['asset_index']=0;$item['packages']=[];
                // Upgrade can reuse already verified bytes when GitHub advertises their exact digest.
                $old=json_decode($row['package_data']??'',true);
                if(empty($row['fingerprint'])&&is_array($old)&&($old['tag']??'')===$release['tag_name']&&count($item['assets'])===1&&($item['assets'][0]['name']??'')===($old['asset']??'')&&($item['assets'][0]['digest']??'')==='sha256:'.($old['sha256']??'')){$old['beta']=$rule['beta']??$old['beta'];$old['allowed_domains']=$rule['allowed_domains']??($old['allowed_domains']??[]);$old['include_subdomains']=$rule['include_subdomains']??($old['include_subdomains']??false);$item['packages']=[$old];$item['asset_index']=1;}
            }
            if($item['asset_index']<count($item['assets'])){
                $asset=$item['assets'][$item['asset_index']++];$entry=ghc_v1_download($repo,$release,$asset,$rule);
                if(is_wp_error($entry)){$scan['warnings'][]=$repo.': '.$entry->get_error_message();ghc_v1_save($repo,['status'=>'error','last_checked'=>gmdate('Y-m-d H:i:s'),'last_error'=>$entry->get_error_message()]);$scan['index']++;unset($item);break;}
                if($entry){$item['packages'][]=$entry;}
                unset($item);break;
            }
            if(count($item['packages'])>1){$scan['warnings'][]=$repo.': ambiguous plugin package.';ghc_v1_save($repo,['status'=>'error','last_error'=>'Multiple plugin packages require an exception.']);}
            elseif(count($item['packages'])===1){
                $entry=$item['packages'][0];$old=json_decode($row['package_data']??'',true);
                if(is_array($old)&&($old['tag']??'')===$entry['tag']&&($old['sha256']??'')!==$entry['sha256']){$scan['warnings'][]=$repo.': published bytes changed under the same tag.';ghc_v1_save($repo,['status'=>'error','last_error'=>'Publish changed bytes under a new version.']);}
                else{
                    $validator = ($entry['author'] === 'Techn' ? 'tnuc' : 'asuc') . '_validate_catalogue';
                    if (in_array($entry['author'], ['Techn','AlphaSys'], true) && function_exists($validator)) {
                        $validated = $validator(['schema'=>1,'published_at'=>gmdate('c'),'plugins'=>[$entry]]);
                        if (is_wp_error($validated)) { $scan['warnings'][]=$repo.': '.$validated->get_error_message();ghc_v1_save($repo,['status'=>'error','last_error'=>$validated->get_error_message()]);$scan['index']++;unset($item);continue; }
                    }
                    $fields+=['author'=>$entry['author_header']??$entry['author'],'brand'=>in_array($entry['author'],['Techn','AlphaSys'],true)?$entry['author']:'','alpha_beta'=>empty($entry['beta'])?'alpha':'beta','plugin_file'=>$entry['file'],'package_data'=>wp_json_encode($entry),'fingerprint'=>$fingerprint,'status'=>'verified'];ghc_v1_save($repo,$fields);}
            }elseif(!empty($row['package_data'])){$scan['warnings'][]=$repo.': previously verified plugin package is missing.';ghc_v1_save($repo,['status'=>'error','last_error'=>'Previously verified plugin package is missing; previous package retained.']);}
            else{ghc_v1_save($repo,array_merge($fields,['fingerprint'=>$fingerprint,'status'=>'not_plugin','author'=>'','brand'=>'','package_data'=>'','plugin_file'=>'']));}
            $scan['index']++;unset($item);
        }
        if($scan['status']==='running'){$scan['message']='Checked '.$scan['index'].' of '.count($scan['repos']).' repositories.';}
        update_site_option('ghc_v1_scan',$scan);ghc_v1_import();return ghc_v1_public($scan);
    }catch(Throwable $error){
        if(isset($item)){unset($item);}
        if(isset($scan)&&is_array($scan)&&!empty($scan['id'])){$scan['status']='failed';$scan['message']=$error->getMessage();update_site_option('ghc_v1_scan',$scan);return ghc_v1_public($scan);}
        return new WP_Error('scan',$error->getMessage());
    }finally{ghc_v1_unlock($lock);}
}
function ghc_v1_import(): void {
    foreach(['asuc','tnuc'] as $prefix){if(function_exists($prefix.'_import_shared')){($prefix.'_import_shared')();}}
}
function ghc_v1_sync_local(): void {
    if(!ghc_v1_exists()){return;}
    require_once ABSPATH.'wp-admin/includes/plugin.php';wp_clean_plugins_cache(false);$plugins=get_plugins();$site_active=[];
    if(is_multisite()){
        $offset=0;do{$sites=get_sites(['fields'=>'ids','number'=>100,'offset'=>$offset]);foreach($sites as $site){foreach((array)get_blog_option($site,'active_plugins',[]) as $file){$site_active[$file]=true;}}$offset+=100;}while(count($sites)===100);
    }
    foreach(ghc_v1_rows() as $row){
        $file=$row['plugin_file'];
        if(!$file){foreach($plugins as $candidate=>$data){if(rtrim($data['UpdateURI']??'','/')==='https://github.com/cchatterton/'.$row['repo']){$file=$candidate;break;}}}
        $state='not_installed';$version='';
        if($file&&isset($plugins[$file])){$version=$plugins[$file]['Version'];$state=is_plugin_active_for_network($file)?'network_active':((is_plugin_active($file)||isset($site_active[$file]))?'active':'inactive');}
        ghc_v1_save($row['repo'],['local_version'=>$version,'local_installed_state'=>$state,'plugin_file'=>$file]);
    }
}
add_action('upgrader_process_complete','ghc_v1_sync_local',20,0);
function ghc_v1_activation_changed(string $option): void {
    if (in_array($option, ['active_plugins','active_sitewide_plugins'], true)) { ghc_v1_sync_local(); }
}
add_action('updated_option','ghc_v1_activation_changed',20,1);
add_action('update_site_option','ghc_v1_activation_changed',20,1);
add_action('added_option','ghc_v1_activation_changed',20,1);
add_action('add_site_option','ghc_v1_activation_changed',20,1);
add_action('deleted_plugin','ghc_v1_sync_local',20,0);
function ghc_v1_header(string $text, string $name, string $default = ''): string {
    return preg_match('/^[ \t]*\*?[ \t]*' . preg_quote($name, '/') . ':[ \t]*([^\r\n]*)/m', $text, $match) ? trim($match[1]) : $default;
}
/** Validate released bytes without extracting or executing plugin code. */
function ghc_v1_inspect_zip(string $path, string $repo, array $release, array $asset, array $rule) {
    if (!class_exists('ZipArchive')) { return new WP_Error('zip_support', 'The PHP ZIP extension is required for direct release inspection.'); }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { return new WP_Error('zip', 'The release ZIP could not be read.'); }
    try {
        $names = []; $mains = []; $expanded = 0;
        if ($zip->numFiles > 10000) { return new WP_Error('zip_entries', 'Archive has too many files.'); }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); $name = $stat['name']; $expanded += $stat['size'];
            $opsys = 0; $attributes = 0; $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            if (!$name || isset($names[$name]) || $name[0] === '/' || strpos($name, '\\') !== false || in_array('..', explode('/', $name), true) || (($attributes >> 16) & 0170000) === 0120000 || $expanded > 268435456) { return new WP_Error('zip_path', 'Unsafe or oversized archive.'); }
            $names[$name] = true;
            if (substr_count($name, '/') !== 1 || substr($name, -4) !== '.php') { continue; }
            if ($stat['size'] > 2097152) { return new WP_Error('zip_main', 'Oversized plugin main file.'); }
            $text = (string) $zip->getFromIndex($i, 8192);
            if (ghc_v1_header($text, 'Plugin Name') && (empty($rule['file']) || $rule['file'] === $name)) { $mains[$name] = $text; }
        }
        if (!$mains) { return null; }
        if (count($mains) !== 1) { return new WP_Error('ambiguous', 'Multiple plugin main files require an exception.'); }
        $file = (string) key($mains); $text = current($mains); $slug = dirname($file);
        $author = ghc_v1_header($text, 'Author'); $brand = '';
        foreach (['AlphaSys', 'Techn'] as $candidate) { if (strcasecmp($author, $candidate) === 0) { $brand = $candidate; } }
        if (isset($rule['author_header']) && strcasecmp($author, $rule['author_header']) === 0) { $brand = $rule['brand'] ?? ''; }
        if (!empty($rule['author']) && $brand !== $rule['author']) { return new WP_Error('author', 'Released author differs from its reviewed exception.'); }
        foreach ($names as $name => $_) { if (strpos($name, $slug . '/') !== 0) { return new WP_Error('root', 'Unexpected package root.'); } }
        $entry = ['id' => $rule['id'] ?? $repo, 'owner' => 'cchatterton', 'repo' => $repo, 'file' => $file, 'slug' => $slug, 'asset' => $asset['name'], 'author' => $brand ?: $author, 'author_header' => $author];
        foreach (['owner', 'repo', 'file', 'slug', 'asset', 'author'] as $key) { if (isset($rule[$key]) && $rule[$key] !== $entry[$key]) { return new WP_Error('identity', 'Released package identity changed.'); } }
        $uri = ghc_v1_header($text, 'Update URI');
        if ($uri && rtrim($uri, '/') !== 'https://github.com/cchatterton/' . $repo) { return new WP_Error('identity', 'Update URI differs from the release repository.'); }
        $domains = ghc_v1_header($text, 'Allowed Domains'); $subdomains = strtolower(ghc_v1_header($text, 'Allow Subdomains', 'false'));
        if (!in_array($subdomains, ['true', 'false'], true)) { return new WP_Error('domains', 'Invalid subdomain policy.'); }
        $api = ghc_v1_header($text, ($brand === 'AlphaSys' ? 'AlphaSys' : 'Techn') . ' Controller API', in_array($repo, ['as-update-controller','tn-update-controller'], true) ? '1' : '0');
        if (!preg_match('/^\d+$/D', $api)) { return new WP_Error('api', 'Invalid controller API version.'); }
        $entry += ['name' => ghc_v1_header($text, 'Plugin Name'), 'description' => ghc_v1_header($text, 'Description'), 'version' => ghc_v1_header($text, 'Version'), 'tag' => $release['tag_name'], 'requires' => ghc_v1_header($text, 'Requires at least', '6.0'), 'requires_php' => ghc_v1_header($text, 'Requires PHP', '7.4'), 'dependencies' => array_values(array_filter(array_map('trim', explode(',', ghc_v1_header($text, 'Requires Plugins'))))), 'controller_api' => (int) $api, 'body' => (string) ($release['body'] ?? ''), 'beta' => $rule['beta'] ?? (!in_array($repo, ['as-update-controller','tn-update-controller'], true)), 'sha256' => hash_file('sha256', $path), 'allowed_domains' => $rule['allowed_domains'] ?? ($domains === '' ? [] : array_map('trim', explode(',', strtolower($domains)))), 'include_subdomains' => $rule['include_subdomains'] ?? ($subdomains === 'true')];
        if (!in_array($entry['tag'], [$entry['version'], 'v'.$entry['version'], 'V'.$entry['version']], true)) { return new WP_Error('version', 'Plugin header version differs from its release tag.'); }
        return $entry;
    } finally { $zip->close(); }
}
function ghc_v1_download(string $repo, array $release, array $asset, array $rule) {
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*\.zip$/D', $asset['name']) || (empty($asset['direct']) && ($asset['size'] ?? 0) < 1) || ($asset['size'] ?? 0) > 67108864 || !preg_match('/^[vV]?\d+\.\d+(?:\.\d+){0,2}$/D', $release['tag_name'])) { return new WP_Error('asset', 'Invalid, oversized or unsupported release asset.'); }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $temp = wp_tempnam($asset['name']); if (!$temp) { return new WP_Error('temp', 'Could not create a temporary inspection file.'); }
    $url = 'https://github.com/cchatterton/' . $repo . '/releases/download/' . rawurlencode($release['tag_name']) . '/' . rawurlencode($asset['name']);
    $deadline = microtime(true) + 25;
    try {
        for ($hop = 0; $hop < 4; $hop++) {
            if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array(wp_parse_url($url, PHP_URL_HOST), ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)) { return new WP_Error('host', 'Untrusted release redirect.'); }
            $remaining = $deadline - microtime(true); if ($remaining < 1) { return new WP_Error('timeout', 'Release inspection timed out. Retry the manual check.'); }
            $response = wp_safe_remote_get($url, ['timeout' => $remaining, 'redirection' => 0, 'stream' => true, 'filename' => $temp, 'limit_response_size' => 67108864]);
            if (is_wp_error($response)) { return new WP_Error('download', 'The released ZIP could not be inspected.'); }
            $code = (int) wp_remote_retrieve_response_code($response);
            if (in_array($code, [301, 302, 303, 307, 308], true)) { $url = WP_Http::make_absolute_url(wp_remote_retrieve_header($response, 'location'), $url); continue; }
            if ($code !== 200 || filesize($temp) < 1 || filesize($temp) >= 67108864 || (empty($asset['direct']) && filesize($temp) !== $asset['size'])) { return new WP_Error('download', 'The released ZIP download was incomplete.'); }
            if (!empty($asset['digest']) && $asset['digest'] !== 'sha256:' . hash_file('sha256', $temp)) { return new WP_Error('checksum', 'The released ZIP differs from its GitHub checksum.'); }
            return ghc_v1_inspect_zip($temp, $repo, $release, $asset, $rule);
        }
        return new WP_Error('redirect', 'Too many release redirects.');
    } finally { @unlink($temp); }
}

}
