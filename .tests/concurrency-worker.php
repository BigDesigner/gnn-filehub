<?php
/** Runs only against the explicitly generated disposable runtime. */
ob_start();
$root=realpath(__DIR__.'/runtime');
if(!$root || !is_file($root.'/wordpress/wp-content/mu-plugins/filehub-qa.php'))exit(2);
$_SERVER['HTTP_HOST']='127.0.0.1:9461'; $_SERVER['REQUEST_URI']='/'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['DOCUMENT_ROOT']=$root.'/wordpress';
require $root.'/wordpress/wp-load.php';
$action=$argv[1]??'';
if($action==='prepare') {
    $id=wp_insert_user(array('user_login'=>'fhqa_race_'.substr(wp_generate_uuid4(),0,8),'user_email'=>substr(wp_generate_uuid4(),0,8).'@example.invalid','user_pass'=>wp_generate_password(24),'role'=>'subscriber'));
    update_user_meta($id,'_filehub_custom_quota_mb',1);
    update_option('filehub_storage_driver','local'); update_option('filehub_max_upload_mb',0);
    file_put_contents($root.'/race-fixture.json',wp_json_encode(array('owner'=>$id)));
    ob_end_clean(); echo 'ready'; exit;
}
if($action==='scanner') { update_option('filehub_antivirus_socket',($argv[2]??'')==='on'?'tcp://127.0.0.1:3310':''); ob_end_clean(); echo 'ok'; exit; }
$fixture=json_decode(file_get_contents($root.'/race-fixture.json'),true); wp_set_current_user((int)$fixture['owner']);
if($action==='reserve') {
    $number=(int)($argv[2]??0);
    file_put_contents($root.'/race-ready-'.$number,'ready');
    for($i=0;$i<100 && !is_file($root.'/race-start');$i++)usleep(50000);
    $result=FileHub_Uploads::begin('race.pdf',700000);
    $data=is_wp_error($result)?array('status'=>(int)($result->get_error_data()['status']??500)):array('status'=>200,'id'=>$result['upload_id'],'token'=>$result['upload_token']);
    ob_end_clean(); echo wp_json_encode($data); exit;
}
if($action==='inspect') {
    global $wpdb;
    $bytes=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(bytes),0) FROM '.FileHub_Uploads::table().' WHERE owner=%d',$fixture['owner']));
    ob_end_clean(); echo wp_json_encode(array('reserved'=>$bytes)); exit;
}
exit(2);
