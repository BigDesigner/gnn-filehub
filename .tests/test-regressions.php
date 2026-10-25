<?php
/** Real WordPress + disposable database regressions. External HTTP is mocked at the WP boundary. */
ob_start();
$runtime=getenv('FILEHUB_TEST_RUNTIME')?:__DIR__.'/runtime';
$runtime=realpath($runtime);
if(!$runtime || !is_file($runtime.'/wordpress/wp-load.php'))throw new RuntimeException('Run .tests/prepare-runtime.py first.');
define('WP_INSTALLING',true);
$_SERVER['HTTP_HOST']='127.0.0.1:9461'; $_SERVER['SERVER_NAME']='127.0.0.1'; $_SERVER['SERVER_PORT']='9461';
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/'; $_SERVER['DOCUMENT_ROOT']=$runtime.'/wordpress';
require $runtime.'/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
if(!is_blog_installed())wp_install('FileHub isolated QA','fhqa_admin','admin@example.invalid',false,'','Synthetic-Test-Password-2026!');
if(!class_exists('FileHub_Core')) { require dirname(__DIR__).'/gnn-filehub/gnn-filehub-nextgen.php'; gnn_filehub_init(); }
FileHub_Uploads::install();
$admin=new FileHub_Admin(); $admin->register_settings();
$server=rest_get_server();
$results=array();
$suffix=substr(wp_generate_uuid4(),0,8);
$_SERVER['REMOTE_ADDR']='192.0.2.'.hexdec(substr($suffix,0,2));
$owner=wp_insert_user(array('user_login'=>'fhqa_owner_'.$suffix,'user_email'=>'owner_'.$suffix.'@example.invalid','user_pass'=>'Synthetic-Test-Password-2026!','role'=>'subscriber'));
$other=wp_insert_user(array('user_login'=>'fhqa_other_'.$suffix,'user_email'=>'other_'.$suffix.'@example.invalid','user_pass'=>'Synthetic-Test-Password-2026!','role'=>'subscriber'));
$administrator=(int)get_user_by('login','fhqa_admin')->ID;
function expect($ok,$message='Expectation failed') { if(!$ok)throw new RuntimeException($message); }
function call_api($method,$path,$body=null,$token='',$nonce=true) {
    $r=new WP_REST_Request($method,'/filehub/v1/'.$path);
    if($nonce)$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
    if($token)$r->set_header('X-FileHub-Token',$token);
    if($body!==null) { $r->set_header('Content-Type','application/json'); $r->set_body(wp_json_encode($body)); }
    return rest_get_server()->dispatch($r);
}
function fixture(string $name='sample.pdf',string $content="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n") {
    $begin=call_api('POST','uploads',array('filename'=>$name,'size'=>strlen($content)));
    expect($begin->get_status()===200,'Begin: '.wp_json_encode($begin->get_data()));
    $data=$begin->get_data(); $id=$data['upload_id']; $token=$data['upload_token'];
    $file=tempnam($GLOBALS['runtime'],'chunk-'); file_put_contents($file,$content);
    try {
        $result=FileHub_Uploads::operate($id,$token,function($s)use($file){return FileHub_Uploads::append_trusted($s,0,$file);});
        expect(!is_wp_error($result),is_wp_error($result)?$result->get_error_message():'');
    } finally { unlink($file); }
    return array($id,$token);
}
function finish_fixture(string $name='sample.pdf') {
    list($id,$token)=fixture($name);
    $response=call_api('POST','uploads/'.$id.'/complete',null,$token);
    expect($response->get_status()===200,'Complete: '.wp_json_encode($response->get_data()));
    $data=$response->get_data(); expect($data['status']==='done' && $data['attachment_id']>0);
    return array($data['attachment_id'],$id,$token);
}
function test_case($id,$title,$fn) {
    global $owner,$results,$wpdb;
    wp_set_current_user($owner);
    update_option('filehub_storage_driver','local'); update_option('filehub_default_quota_mb',50);
    update_option('filehub_max_upload_mb',0); update_option('filehub_upload_notify','0');
    update_option('filehub_antivirus_socket',''); update_option('filehub_guest_upload','0'); update_option('filehub_allowed_extensions','jpg,jpeg,png,gif,pdf,zip,doc,docx,xlsx,txt');
    try { $fn(); $results[]=array('id'=>$id,'test'=>$title,'passed'=>true); }
    catch(Throwable $e) { $results[]=array('id'=>$id,'test'=>$title,'passed'=>false,'error'=>get_class($e).': '.$e->getMessage()); }
    // Expire only this run's own test sessions; exercise production cleanup instead of deleting arbitrary paths.
    $expired=$wpdb->get_results($wpdb->prepare('SELECT id,payload FROM '.FileHub_Uploads::table().' WHERE owner IN (%d,%d)',$owner,$GLOBALS['other']));
    foreach($expired as $row) { $payload=json_decode($row->payload,true); $payload['expires']=0; $wpdb->update(FileHub_Uploads::table(),array('expires'=>0,'payload'=>wp_json_encode($payload)),array('id'=>$row->id)); }
    FileHub_Uploads::cleanup();
}

test_case('R01','Guest downloads require a real owner or administrator',function() {
    $id=FileHub_Attachment::create_attachment(array('storage_driver'=>'local','storage_key'=>'0/synthetic','file_name'=>'guest.pdf','file_size'=>1),0,'application/pdf');
    wp_set_current_user(0);
    $r=call_api('GET','download/'.$id); expect($r->get_status()===401);
    expect(!FileHub_Security::can_access($id));
});
test_case('R02','Real local session validates, commits once, and denies another user',function() {
    list($id,$session,$token)=finish_fixture();
    expect(get_post_status($id)==='private');
    $again=call_api('POST','uploads/'.$session.'/complete',null,$token)->get_data(); expect($again['attachment_id']===$id);
    wp_set_current_user($GLOBALS['other']); expect(call_api('DELETE','files/'.$id)->get_status()===404);
    expect(call_api('GET','uploads/'.$session,null,$token)->get_status()===404);
});
test_case('R03','Standard media collection and single routes hide FileHub records',function() {
    list($id)=finish_fixture();
    wp_set_current_user(0);
    $single=rest_get_server()->dispatch(new WP_REST_Request('GET','/wp/v2/media/'.$id)); expect($single->get_status()===404);
    $list=rest_get_server()->dispatch(new WP_REST_Request('GET','/wp/v2/media'));
    expect(!in_array($id,array_column($list->get_data(),'id'),true));
    wp_set_current_user($GLOBALS['administrator']);
    $query=new WP_Query(array('post_type'=>'attachment','post_status'=>array('private','inherit'),'posts_per_page'=>-1));
    expect(!in_array($id,wp_list_pluck($query->posts,'ID'),true));
});
test_case('R04','Forged PDF content is rejected even with legacy MIME toggle off',function() {
    update_option('filehub_strict_mime','0'); list($id,$token)=fixture('forged.pdf','not a PDF');
    expect(call_api('POST','uploads/'.$id.'/complete',null,$token)->get_status()===400);
});
test_case('R05','Quota reservations account for open uploads and remaining bytes',function() {
    update_option('filehub_default_quota_mb',1);
    $a=call_api('POST','uploads',array('filename'=>'reserve.pdf','size'=>800000)); expect($a->get_status()===200);
    $b=call_api('POST','uploads',array('filename'=>'reserve2.pdf','size'=>800000)); expect($b->get_status()===413);
    $data=$a->get_data(); expect(call_api('DELETE','uploads/'.$data['upload_id'],null,$data['upload_token'])->get_status()===200);
});
test_case('R06','Legacy upload and R2 presign endpoints are retired',function() {
    foreach(array('upload','upload-chunk','r2-presign','r2-finalize')as $route)expect(call_api('POST',$route,array())->get_status()===410);
});
test_case('R07','Users cannot increase their own quota',function() {
    $_POST=array('filehub_user_quota_nonce'=>wp_create_nonce('filehub_save_user_quota'),'filehub_custom_quota_mb'=>'999999');
    $GLOBALS['admin']->save_user_profile_fields($GLOBALS['owner']);
    expect(!get_user_meta($GLOBALS['owner'],'_filehub_custom_quota_mb',true)); $_POST=array();
});
test_case('R08','Private directory is outside document root; traversal cannot delete a sibling',function() {
    $root=FileHub_Security::private_dir(); expect(!is_wp_error($root) && !FileHub_Security::inside($root,ABSPATH));
    $sibling=$GLOBALS['runtime'].'/private-sibling'; if(!is_dir($sibling))mkdir($sibling);
    file_put_contents($sibling.'/probe','preserve');
    (new FileHub_Storage_Local())->delete_file('../private-sibling/probe'); expect(is_file($sibling.'/probe')); unlink($sibling.'/probe'); rmdir($sibling);
});
test_case('R09','Same-name uploads use distinct physical keys and honor rename setting',function() {
    list($a)=finish_fixture('same.pdf'); list($b)=finish_fixture('same.pdf');
    expect(get_post_meta($a,'_filehub_storage_key',true)!==get_post_meta($b,'_filehub_storage_key',true));
    expect(get_post_meta($a,'_filehub_file_name',true)!==get_post_meta($b,'_filehub_file_name',true));
    update_option('filehub_auto_rename','0'); list($c)=finish_fixture('literal.pdf'); list($d)=finish_fixture('literal.pdf');
    expect(get_post_meta($c,'_filehub_file_name',true)===get_post_meta($d,'_filehub_file_name',true)); update_option('filehub_auto_rename','1');
});
test_case('R10','Invalid chunk index/size and missing uploads fail closed',function() {
    $r=call_api('POST','uploads',array('filename'=>'parts.pdf','size'=>123)); $s=$r->get_data();
    expect(call_api('POST','uploads/'.$s['upload_id'].'/chunks/999',null,$s['upload_token'])->get_status()===400);
    expect(call_api('POST','uploads/'.$s['upload_id'].'/complete',null,$s['upload_token'])->get_status()===409);
});
test_case('R11','Ordinary WordPress media is not a FileHub deletion target',function() {
    $id=wp_insert_attachment(array('post_title'=>'ordinary','post_author'=>$GLOBALS['owner'],'post_status'=>'inherit'));
    expect(call_api('DELETE','files/'.$id)->get_status()===404); expect((bool)get_post($id));
});
test_case('R12','Cloud HTTP 403 preserves DB record; 204 permits deletion',function() {
    update_option('filehub_r2_account_id',str_repeat('a',32)); update_option('filehub_r2_access_key','synthetic'); update_option('filehub_r2_secret_key','synthetic'); update_option('filehub_r2_bucket','synthetic-bucket');
    $id=FileHub_Attachment::create_attachment(array('storage_driver'=>'r2','storage_key'=>'uploads/test','file_name'=>'cloud.pdf','file_size'=>1),$GLOBALS['owner'],'application/pdf');
    $mock=function($pre,$args,$url){return array('response'=>array('code'=>403),'body'=>'','headers'=>array());}; add_filter('pre_http_request',$mock,20,3);
    expect(call_api('DELETE','files/'.$id)->get_status()===503); expect((bool)get_post($id)); remove_filter('pre_http_request',$mock,20);
    $mock=function(){return array('response'=>array('code'=>204),'body'=>'','headers'=>array());}; add_filter('pre_http_request',$mock,20,3);
    expect(call_api('DELETE','files/'.$id)->get_status()===200); expect(!get_post($id)); remove_filter('pre_http_request',$mock,20);
});
test_case('R13','File list page two and server search include old records',function() {
    for($i=0;$i<102;$i++)FileHub_Attachment::create_attachment(array('storage_driver'=>'local','storage_key'=>'synthetic/'.$i,'file_name'=>'search_'.$i.'.pdf','file_size'=>1),$GLOBALS['owner'],'application/pdf');
    $a=call_api('GET','files?unused=1'); // Query params are explicitly set below for real dispatch.
    $r=new WP_REST_Request('GET','/filehub/v1/files'); $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $r->set_param('page',2); $r->set_param('per_page',100);
    $response=rest_get_server()->dispatch($r); expect($response->get_status()===200 && count($response->get_data())>0); expect((int)$response->get_headers()['X-WP-Total']>=102);
    $r->set_param('page',1); $r->set_param('search','search_0.pdf'); $response=rest_get_server()->dispatch($r); expect(count($response->get_data())===1);
});
test_case('R14','Unlimited quota is zero and guest statistics are isolated',function() {
    update_option('filehub_default_quota_mb',0); expect(FileHub_Attachment::get_user_quota($GLOBALS['owner'])===0);
    $guest=FileHub_Attachment::get_user_stats(0); $member=FileHub_Attachment::get_user_stats($GLOBALS['owner']); expect($guest['total_bytes']<$member['total_bytes']);
});
test_case('R15','Profile/registration type errors produce 400 rather than PHP errors',function() {
    expect(call_api('POST','update-profile')->get_status()===400);
    expect(call_api('POST','update-profile',array('new_password'=>array('invalid')))->get_status()===400);
    expect(call_api('POST','uploads',array('filename'=>array('invalid'),'size'=>1))->get_status()===400);
});
test_case('R16','Typed settings normalize extensions and reject arrays',function() {
    expect(sanitize_option('filehub_allowed_extensions',' .PDF, JPG,php')==='pdf,jpg');
    expect(sanitize_option('filehub_storage_driver','invalid')==='local');
    expect(sanitize_option('filehub_default_quota_mb',array('invalid'))===get_option('filehub_default_quota_mb'));
});
test_case('R17','Earlier updater errors are preserved and failed HTTP calls are cached',function() {
    $u=new FileHub_Updater(); $error=new WP_Error('sentinel','preserve');
    expect($u->after_install($error,array('plugin'=>'other/other.php'),array())===$error);
    delete_transient('filehub_github_update_check'); $count=0;
    $mock=function()use(&$count){++$count;return new WP_Error('offline','offline');}; add_filter('pre_http_request',$mock,20,3);
    $method=new ReflectionMethod($u,'get_remote_release'); if(PHP_VERSION_ID<80100)$method->setAccessible(true); $method->invoke($u); $method->invoke($u);
    remove_filter('pre_http_request',$mock,20); expect($count===1,'Expected one cached HTTP failure; got '.$count);
});
test_case('R18','R2 multipart sends bounded native HTTP requests and verifies final size',function() {
    $path=tempnam($GLOBALS['runtime'],'r2-'); file_put_contents($path,'%PDF-synthetic'); $size=filesize($path); $calls=array();
    $mock=function($pre,$args,$url)use(&$calls,$size) {
        $calls[]=array($args['method'],strlen($args['body']??''),$url);
        if($args['method']==='HEAD')return array('response'=>array('code'=>200),'headers'=>array('content-length'=>$size,'content-type'=>'application/pdf'),'body'=>'');
        if(strpos($url,'uploads=')!==false)return array('response'=>array('code'=>200),'headers'=>array(),'body'=>'<InitiateMultipartUploadResult><UploadId>test-upload</UploadId></InitiateMultipartUploadResult>');
        return array('response'=>array('code'=>200),'headers'=>array('etag'=>'"synthetic-etag"'),'body'=>'<CompleteMultipartUploadResult/>');
    }; add_filter('pre_http_request',$mock,20,3);
    try {
        $driver=new FileHub_Storage_R2(); $s=array('id'=>wp_generate_uuid4(),'owner'=>$GLOBALS['owner'],'filename'=>'cloud.pdf','size'=>$size,'mime'=>'application/pdf');
        for($i=0;$i<3;$i++) { $step=$driver->transfer_step($path,$s); expect(!is_wp_error($step),is_wp_error($step)?$step->get_error_message():''); $s['transfer']=$step['transfer']; }
        expect(isset($step['result']) && $step['result']['file_size']===$size); expect(count($calls)===4);
    } finally { remove_filter('pre_http_request',$mock,20); unlink($path); }
});
test_case('R19','Google folder cache is bound to account/parent configuration',function() {
    update_option('filehub_gdrive_folder_id','new-parent'); set_transient('filehub_gdrive_user_folder_'.$GLOBALS['owner'],'old-child',DAY_IN_SECONDS);
    $calls=0; $mock=function()use(&$calls){++$calls;return new WP_Error('offline','expected');}; add_filter('pre_http_request',$mock,20,3);
    try { $driver=new FileHub_Storage_GDrive(); $m=new ReflectionMethod($driver,'get_or_create_user_folder'); if(PHP_VERSION_ID<80100)$m->setAccessible(true); $m->invoke($driver,$GLOBALS['owner'],'synthetic'); expect($calls>0); }
    finally { remove_filter('pre_http_request',$mock,20); }
});
test_case('R20','Failed/cancelled sessions retain reservations until cleanup succeeds',function() {
    list($id,$token)=fixture('cancel.pdf'); expect(call_api('DELETE','uploads/'.$id,null,$token)->get_status()===200);
    expect(call_api('GET','uploads/'.$id,null,$token)->get_status()===404);
});
test_case('R21','Registration policy hook runs and unverified accounts cannot log in',function() {
    update_option('users_can_register',1); wp_set_current_user(0);
    $seen=false; $filter=function($e)use(&$seen){$seen=true;$e->add('policy','Blocked by test policy');return $e;}; add_filter('registration_errors',$filter);
    $data=array('username'=>'policy_'.$GLOBALS['suffix'],'email'=>'policy_'.$GLOBALS['suffix'].'@example.invalid','password'=>'Synthetic-Test-Password!','confirm_password'=>'Synthetic-Test-Password!');
    $r=call_api('POST','register',$data); expect($seen && $r->get_status()>=400); remove_filter('registration_errors',$filter);
    $r=call_api('POST','register',$data); expect($r->get_status()===200,wp_json_encode($r->get_data()));
    $user=get_user_by('login',$data['username']); expect((bool)get_user_meta($user->ID,'_filehub_pending_email',true));
    $result=wp_authenticate($data['username'],$data['password']); expect(is_wp_error($result));
});
test_case('R22','Private legacy migration preserves content and removes public copy',function() {
    $uploads=wp_upload_dir(); $dir=$uploads['basedir'].'/filehub-protected/'.$GLOBALS['owner']; wp_mkdir_p($dir);
    file_put_contents($dir.'/legacy.pdf','legacy-data');
    $result=(new FileHub_Storage_Local())->migrate_key($GLOBALS['owner'].'/legacy.pdf');
    expect(!is_wp_error($result),is_wp_error($result)?$result->get_error_message():''); expect(file_get_contents($result)==='legacy-data'); expect(!is_file($dir.'/legacy.pdf'));
});
test_case('R23','Download counter increments atomically and all statistics use real SQL',function() {
    list($id)=finish_fixture('count.pdf'); FileHub_Attachment::increment_download_count($id); FileHub_Attachment::increment_download_count($id);
    expect((int)get_post_meta($id,'_filehub_download_count',true)===2); expect(FileHub_Attachment::get_system_stats()['total_downloads']>=2);
});
test_case('R24','Bad upload nonce is rejected by the actual route permission callback',function() {
    expect(call_api('POST','uploads',array('filename'=>'none.pdf','size'=>10),'',false)->get_status()===403);
});
test_case('R25','Password changes explicitly require client session reload',function() {
    $r=call_api('POST','update-profile',array('current_password'=>'Synthetic-Test-Password-2026!','new_password'=>'Changed-Synthetic-Password!','confirm_password'=>'Changed-Synthetic-Password!'));
    expect($r->get_status()===200 && $r->get_data()['reload']===true);
});

test_case('R26','Google resumable offset recovers a lost completion without creating a second file',function() {
    update_option('filehub_gdrive_client_id','synthetic'); update_option('filehub_gdrive_client_secret','synthetic'); update_option('filehub_gdrive_refresh_token','synthetic');
    $path=tempnam($GLOBALS['runtime'],'drive-'); file_put_contents($path,'drive-content'); $size=filesize($path); $done=false; $starts=0;
    $mock=function($pre,$args,$url)use(&$done,&$starts) {
        $r=array('response'=>array('code'=>200),'headers'=>array(),'body'=>'');
        if(strpos($url,'oauth2.googleapis.com')!==false) { $r['body']=wp_json_encode(array('access_token'=>'synthetic-token','expires_in'=>3600)); return $r; }
        if(strpos($url,'uploadType=resumable')!==false) { ++$starts; $r['headers']['location']='https://www.googleapis.com/upload/drive/v3/files?upload_id=synthetic'; return $r; }
        if(strpos($url,'upload_id=')!==false) {
            if(($args['headers']['Content-Length']??'')==='0' && !$done) { $r['response']['code']=308; return $r; }
            $done=true; $r['body']=wp_json_encode(array('id'=>'one-drive-file')); return $r;
        }
        $r['body']=wp_json_encode(array('files'=>array(array('id'=>'folder')))); return $r;
    };
    add_filter('pre_http_request',$mock,20,3);
    try {
        $d=new FileHub_Storage_GDrive(); $s=array('id'=>wp_generate_uuid4(),'owner'=>$GLOBALS['owner'],'filename'=>'test.pdf','size'=>$size,'mime'=>'application/pdf');
        $start=$d->transfer_step($path,$s); expect(!is_wp_error($start)); $s['transfer']=$start['transfer'];
        $a=$d->transfer_step($path,$s); expect(isset($a['result']));
        $b=$d->transfer_step($path,$s); expect($a['result']['storage_key']===$b['result']['storage_key'] && $starts===1);
    } finally { remove_filter('pre_http_request',$mock,20); unlink($path); }
});
test_case('R27','Download staging reservations prevent unbounded parallel disk use',function() {
    $a=FileHub_Uploads::reserve_download(100); $b=FileHub_Uploads::reserve_download(100); $c=FileHub_Uploads::reserve_download(100);
    expect(!is_wp_error($a) && !is_wp_error($b) && is_wp_error($c) && $c->get_error_data()['status']===429);
    FileHub_Uploads::release_download($a['id']); FileHub_Uploads::release_download($b['id']);
});
test_case('R28','A lowered quota is rechecked before committing a previously reserved file',function() {
    $content="%PDF-1.4\n".str_repeat('x',1500000); list($id,$token)=fixture('large.pdf',substr($content,0,300));
    // Lower quota below already committed synthetic files, without mutating a staged file.
    update_user_meta($GLOBALS['owner'],'_filehub_custom_quota_mb',1);
    $seed=FileHub_Attachment::create_attachment(array('storage_driver'=>'local','storage_key'=>'synthetic/quota','file_name'=>'quota.pdf','file_size'=>1048576),$GLOBALS['owner'],'application/pdf');
    expect(call_api('POST','uploads/'.$id.'/complete',null,$token)->get_status()===413);
    delete_user_meta($GLOBALS['owner'],'_filehub_custom_quota_mb');
});

test_case('R29','Partial attachment metadata is repaired idempotently before commit',function() {
    $upload=wp_generate_uuid4();
    $id=wp_insert_attachment(array('post_title'=>'partial','post_author'=>$GLOBALS['owner'],'post_status'=>'private','meta_input'=>array('_filehub_upload_id'=>$upload,'_filehub_storage_driver'=>'local')));
    $data=array('storage_driver'=>'local','storage_key'=>'synthetic/repaired','file_name'=>'repaired.pdf','file_size'=>100,'upload_id'=>$upload);
    $repaired=FileHub_Attachment::create_attachment($data,$GLOBALS['owner'],'application/pdf'); expect($repaired===$id);
    expect((int)get_post_meta($id,'_filehub_file_size',true)===100 && (int)get_post_meta($id,'_filehub_complete',true)===1);
});
test_case('R30','Antivirus endpoints are restricted to explicit local services',function() {
    expect(sanitize_option('filehub_antivirus_socket','https://attacker.invalid')==='');
    expect(sanitize_option('filehub_antivirus_socket','tcp://127.0.0.1:3310')==='tcp://127.0.0.1:3310');
});

test_case('R31','WordPress editor capabilities cannot take over another members FileHub file',function() {
    $id=FileHub_Attachment::create_attachment(array('storage_driver'=>'local','storage_key'=>'synthetic/role','file_name'=>'role.pdf','file_size'=>100),$GLOBALS['owner'],'application/pdf');
    wp_update_user(array('ID'=>$GLOBALS['other'],'role'=>'editor'));
    try { expect(!user_can($GLOBALS['other'],'edit_post',$id)); expect(!user_can($GLOBALS['other'],'delete_post',$id)); expect(!user_can($GLOBALS['other'],'read_post',$id)); expect(user_can($GLOBALS['administrator'],'edit_post',$id)); }
    finally { wp_update_user(array('ID'=>$GLOBALS['other'],'role'=>'subscriber')); }
});
test_case('R32','Retained trash files still count against real storage quota',function() {
    $id=FileHub_Attachment::create_attachment(array('storage_driver'=>'local','storage_key'=>'synthetic/trash','file_name'=>'trash.pdf','file_size'=>987),$GLOBALS['owner'],'application/pdf');
    $before=FileHub_Attachment::get_user_stats($GLOBALS['owner'])['total_bytes'];
    wp_update_post(array('ID'=>$id,'post_status'=>'trash'));
    expect(FileHub_Attachment::get_user_stats($GLOBALS['owner'])['total_bytes']===$before);
});

update_option('active_plugins',array('gnn-filehub/gnn-filehub-nextgen.php'));
update_option('template','filehub-qa'); update_option('stylesheet','filehub-qa');
$page=get_page_by_path('filehub-qa');
$page_id=$page?$page->ID:wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'FileHub QA','post_name'=>'filehub-qa','post_content'=>'[filehub_uploader][filehub_manager][filehub_account]'));
file_put_contents($runtime.'/http-fixtures.json',wp_json_encode(array('username'=>get_userdata($owner)->user_login,'password'=>'Changed-Synthetic-Password!','owner'=>$owner,'page'=>$page_id)));
$noise=ob_get_clean();
$failed=count(array_filter($results,function($r){return !$r['passed'];}));
$report=array('wordpress'=>get_bloginfo('version'),'php'=>PHP_VERSION,'mode'=>'real WordPress, fresh SQLite database, mocked external HTTP','passed'=>count($results)-$failed,'failed'=>$failed,'tests'=>$results);
file_put_contents($runtime.'/regression-results.json',wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
if($noise)fwrite(STDERR,substr($noise,0,2000));
exit($failed?1:0);
