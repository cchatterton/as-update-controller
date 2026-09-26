<?php
/** Disposable WordPress with both controllers active. */
wp_set_current_user(1);
function ui_assert($ok,$message){if(!$ok){throw new RuntimeException($message);}echo "PASS: $message\n";}
$requests=0;
$deny=function()use(&$requests){$requests++;return new WP_Error('test_http','Unexpected HTTP while rendering');};
add_filter('pre_http_request',$deny,10,3);
foreach(['tnuc','asuc'] as $prefix){
 $get=$prefix.'_get';$put=$prefix.'_put';$render=$prefix.'_render_admin';$before=$get('batch');$setup=$get('setup_pending');
 try{
  $put('setup_pending',true);$put('batch',['id'=>'completed-fixture','status'=>'complete','items'=>[]]);
  foreach(['installed','catalogue','settings'] as $tab){
   $_GET['tab']=$tab;ob_start();$render();$html=ob_get_clean();
   foreach(['Latest operation','Set up managed updates','Check status','<h2>Recovery</h2>','Update management','card-mark','card-author','A legacy compatibility bridge suppresses'] as $noise){ui_assert(strpos($html,$noise)===false,"$prefix $tab omits $noise");}
   ui_assert(substr_count($html,'data-check=""')===1,"$prefix $tab has one page check button");
   ui_assert(strpos($html,'<dialog')!==false && strpos($html,'aria-labelledby="'.$prefix.'-dialog-title"')!==false,"$prefix $tab accessible progress dialog");
   if($tab==='installed'){
    ui_assert(strpos($html,'<th scope="col">Installed</th><th scope="col">Available</th><th scope="col">GitHub</th>')!==false,"$prefix separate version and repository columns");
    ui_assert(strpos($html,'id="'.$prefix.'-update-selected" disabled')!==false,"$prefix updates disabled before selection");
   }
  }
  $put('batch',['id'=>'running-fixture','status'=>'running','items'=>[]]);$_GET['tab']='installed';ob_start();$render();$html=ob_get_clean();ui_assert(strpos($html,'data-resume="running-fixture"')!==false,"$prefix interrupted batch remains resumable");
  $put('batch',['id'=>'partial-fixture','status'=>'partial','items'=>[]]);ob_start();$render();$html=ob_get_clean();ui_assert(strpos($html,'data-results=""')!==false,"$prefix failures remain discoverable without verbose history");
 }finally{$put('batch',$before);$put('setup_pending',$setup);}
}
remove_filter('pre_http_request',$deny,10);unset($_GET['tab']);ui_assert($requests===0,'all revised screens perform zero metadata HTTP');
