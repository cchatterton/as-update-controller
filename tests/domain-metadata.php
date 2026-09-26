<?php
// Standalone contract tests: no WordPress database, network or customer installation.
const ABSPATH = __DIR__;
define('ASUC_DIR', dirname(__DIR__) . '/as-update-controller/');
define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/controller-domains-' . getmypid());
mkdir(WP_PLUGIN_DIR);
class WP_Error { public $message; public function __construct($code, $message) { $this->message=$code.': '.$message; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
function sanitize_text_field($s) { return strip_tags($s); }
function sanitize_textarea_field($s) { return strip_tags($s); }
function asuc_get($k) { return $GLOBALS['cache']; }
function is_multisite() { return $GLOBALS['network']; }
function home_url($p) { return $GLOBALS['url']; }
function network_home_url($p) { return $GLOBALS['url']; }
function wp_parse_url($u,$c) { return parse_url($u,$c); }
function get_file_data($path,$fields) {
    $out=[]; foreach($fields as $key=>$header) { preg_match('/^\s*\*?\s*'.preg_quote($header,'/').':\s*(.*)$/m',file_get_contents($path),$m); $out[$key]=trim($m[1]??''); } return $out;
}
function is_plugin_active($f) { return false; }
function is_plugin_active_for_network($f) { return false; }
function check($v,$s) { if(!$v) { throw new RuntimeException($s); } echo "PASS $s\n"; }
require ASUC_DIR.'functions/catalogue.php';
require ASUC_DIR.'functions/actions.php';
$cache=[];$network=false;$url='https://example.com/';
$feed=json_decode(file_get_contents(dirname(__DIR__).'/catalogue.json'),true);
$first=asuc_validate_catalogue($feed);check(!is_wp_error($first), 'existing catalogue accepted '.($first->message??''));
$new=$feed['plugins'][0];
$new=array_merge($new,['id'=>'custom-fixture','repo'=>'custom-fixture','slug'=>'custom-fixture','file'=>'custom-fixture/custom-fixture.php','asset'=>'custom-fixture.zip','allowed_domains'=>['alphasys.com.au'],'include_subdomains'=>true,'legacy'=>[['path'=>'bad.php','sha256'=>str_repeat('0',64)]]]);
$feed['plugins'][]=$new;$validated=asuc_validate_catalogue($feed);
check(!is_wp_error($validated),'new approved plugin requires no controller release');
$cache=$validated;
check(isset(asuc_registry()['custom-fixture']),'new plugin appears in runtime registry');
check(asuc_registry()['custom-fixture']['legacy']===[],'remote executable trust discarded');
foreach([false,true] as $network){
 foreach(['alphasys.com.au'=>true,'stage.alphasys.com.au'=>true,'deep.stage.alphasys.com.au'=>true,'ALPHASYS.COM.AU.'=>true,'localhost:10411'=>true,'localhost'=>true,'sub.localhost'=>false,'127.0.0.1'=>false,'notalphasys.com.au'=>false,'alphasys.com.au.evil.test'=>false,'example.com'=>false] as $host=>$expected){
  $url='http://'.$host.'/';check(asuc_domain_allowed($new)===$expected,'host '.$host.' network '.(int)$network);
 }
}
$network=false;$url='https://example.com/';
$groups=asuc_catalogue_groups(asuc_registry(),$cache['plugins'],[]);
check(!isset($groups['beta']['custom-fixture'])&&!isset($groups['available']['custom-fixture']),'restricted card hidden');
$url='http://localhost:10411/';$groups=asuc_catalogue_groups(asuc_registry(),$cache['plugins'],[]);
check(isset($groups['beta']['custom-fixture'])||isset($groups['available']['custom-fixture']),'localhost displays restricted card');
$changed=$feed;$i=count($changed['plugins'])-1;$changed['plugins'][$i]['allowed_domains']=['other.example'];$cache=asuc_validate_catalogue($changed);$url='https://other.example/';
check(asuc_domain_allowed($new),'catalogue changes domain without controller release');
foreach(['owner'=>'other-owner','author'=>'Other Brand','file'=>'../escape.php','asset'=>'../escape.zip','repo'=>'../repo','allowed_domains'=>['https://alphasys.com.au'],'include_subdomains'=>'true'] as $key=>$bad){
 $changed=$feed;$changed['plugins'][$i][$key]=$bad;check(is_wp_error(asuc_validate_catalogue($changed)),'reject invalid '.$key);
}
$changed=$feed;$changed['plugins'][$i]['file']=$feed['plugins'][0]['file'];$changed['plugins'][$i]['slug']=$feed['plugins'][0]['slug'];$changed['plugins'][$i]['asset']=$feed['plugins'][0]['asset'];check(is_wp_error(asuc_validate_catalogue($changed)),'reject identity collision');
$cache=[];$url='https://other.example/';check(!asuc_domain_allowed($new),'cold cache hides unknown uninstalled availability');
mkdir(WP_PLUGIN_DIR.'/custom-fixture');$path=WP_PLUGIN_DIR.'/'.$new['file'];file_put_contents($path,"<?php\n/*\n * Allowed Domains: alphasys.com.au\n * Allow Subdomains: true\n */\n");
check(!asuc_domain_allowed($new),'cold installed header blocks unapproved domain');$url='https://sub.alphasys.com.au/';check(asuc_domain_allowed($new),'cold installed header allows subdomain');
$url='http://localhost:1234/';check(asuc_domain_allowed($new),'localhost overrides installed restriction');
unlink($path);rmdir(dirname($path));rmdir(WP_PLUGIN_DIR);
echo "PASS all checks, zero HTTP functions available\n";
