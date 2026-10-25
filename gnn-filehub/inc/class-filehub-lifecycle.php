<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FileHub_Lifecycle {
    public function __construct() {
        add_action('init',array($this,'boot'),1);
        add_action('filehub_cleanup',array($this,'maintain'));
        add_action('template_redirect',array($this,'private_page'));
        add_filter('rest_post_dispatch',array($this,'private_api'),10,3);
        add_action('admin_notices',array($this,'notices'));
        add_action('pre_get_posts',array($this,'hide_files'));
        add_filter('rest_pre_dispatch',array($this,'hide_media_route'),10,3);
        add_filter('map_meta_cap',array($this,'protect_read'),10,4);
        add_filter('pre_delete_attachment',array($this,'before_delete'),10,3);
        add_action('deleted_user',array($this,'after_user_delete'),10,2);
        add_filter('wp_authenticate_user',array($this,'verified_account'),10,2);
        add_action('updated_option',array($this,'configuration_changed'),10,3);
    }
    public function boot() {
        if(get_option('filehub_schema')!=='2')FileHub_Uploads::install();
        if(!wp_next_scheduled('filehub_cleanup'))wp_schedule_event(time()+60,'hourly','filehub_cleanup');
        if(get_option('filehub_migration_version')!=='2')$this->migrate();
    }
    public function private_api($response,$server,$request) {
        if(strpos($request->get_route(),'/filehub/v1/')===0) {
            $response=rest_ensure_response($response);
            if(is_wp_error($response))return $response;
            $response->header('Cache-Control','private, no-store, max-age=0');
            $response->header('Vary','Cookie, X-FileHub-Token');
        }
        return $response;
    }
    public function private_page() {
        if(!is_singular())return;
        $post=get_queried_object(); if(!($post instanceof WP_Post))return;
        $assigned=array_map(function($key){return (int)get_option($key,0);},array('filehub_page_account','filehub_page_uploader','filehub_page_manager','filehub_page_admin_files'));
        $private=in_array((int)$post->ID,$assigned,true);
        if(!$private && strpos($post->post_content,'[filehub_')!==false) {
            foreach(array('account','uploader','manager','admin_files','profile','password_change','login','register') as $tag)if(has_shortcode($post->post_content,'filehub_'.$tag)) { $private=true; break; }
        }
        if($private) {
            if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
            nocache_headers();
            header('Cache-Control: private, no-store, max-age=0');
        }
    }
    public function hide_files($query) {
        if($query->get('filehub_internal'))return;
        $meta=$query->get('meta_query');
        $query->set('meta_query',array('relation'=>'AND',is_array($meta)?$meta:array(),array('key'=>'_filehub_storage_driver','compare'=>'NOT EXISTS')));
    }
    public function hide_media_route($result,$server,$request) {
        if(preg_match('~^/wp/v2/media/(\d+)(?:/|$)~',$request->get_route(),$m) && FileHub_Security::is_filehub((int)$m[1]))return FileHub_Security::error(__('Dosya bulunamadı.','gnn-filehub'),404);
        return $result;
    }
    public function protect_read($caps,$cap,$user_id,$args) {
        if(!in_array($cap,array('read_post','edit_post','delete_post'),true) || empty($args[0]) || !FileHub_Security::is_filehub((int)$args[0]))return $caps;
        $user_id=(int)$user_id;
        $owner=(int)get_post_field('post_author',(int)$args[0]);
        if(!user_can($user_id,'manage_options') && !($user_id>0 && $owner===$user_id))return array('do_not_allow');
        return $cap==='read_post'?array('read'):$caps;
    }
    public function before_delete($delete,$post,$force) {
        if($delete!==null || !FileHub_Security::is_filehub((int)$post->ID))return $delete;
        $driver=FileHub_Security::driver(get_post_meta($post->ID,'_filehub_storage_driver',true));
        $key=get_post_meta($post->ID,'_filehub_storage_key',true);
        global $wpdb;
        $shared=$key?$wpdb->get_var($wpdb->prepare("SELECT d.post_id FROM {$wpdb->postmeta} d JOIN {$wpdb->postmeta} k ON d.post_id=k.post_id WHERE d.post_id<>%d AND d.meta_key='_filehub_storage_driver' AND d.meta_value=%s AND k.meta_key='_filehub_storage_key' AND k.meta_value=%s LIMIT 1",$post->ID,get_post_meta($post->ID,'_filehub_storage_driver',true),$key)):false;
        if($shared)return null; // Legacy duplicate references retain the object until the last record is removed.
        $result=is_wp_error($driver) || !$key ? false : $driver->delete_file($key);
        if(is_wp_error($result) || $result!==true) {
            update_option('filehub_cleanup_error',__('Dosya depolamadan silinemedi; WordPress kaydı korundu.','gnn-filehub'),false);
            return false;
        }
        return null;
    }
    public function after_user_delete($id,$reassign=null) {
        global $wpdb;
        $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_author=%d AND ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_filehub_storage_driver')",(int)$id));
        // Media queries intentionally exclude FileHub. Keep remaining records administratively owned.
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_author=%d WHERE post_author=%d AND ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_filehub_storage_driver')",$reassign?(int)$reassign:0,(int)$id));
        foreach($ids as $file_id) { update_post_meta($file_id,'_filehub_user_id',$reassign?(int)$reassign:0); clean_post_cache($file_id); }
    }
    public function verified_account($user,$password) {
        if(!is_wp_error($user) && get_user_meta($user->ID,'_filehub_pending_email',true))return FileHub_Security::error(__('Girişten önce e-posta adresinizi doğrulayın.','gnn-filehub'),403);
        return $user;
    }
    public function configuration_changed($option,$old,$new) {
        if(strpos($option,'filehub_gdrive_')===0)delete_transient('filehub_gdrive_access_token');
    }
    public function maintain() { FileHub_Uploads::cleanup(); $this->migrate(); $this->clean_legacy_chunks(); }
    public function migrate() {
        $root=FileHub_Security::private_dir();
        if(!is_wp_error($root)) {
            $uploads=wp_upload_dir(); $public=$uploads['basedir'].'/filehub-protected';
            // An atomic same-volume rename removes the entire old public tree immediately.
            if(is_dir($public) && !is_dir($root.'/legacy'))@rename($public,$root.'/legacy');
        }
        $result=FileHub_Security::locked('migration',function() {
            $files=get_posts(array('post_type'=>'attachment','post_status'=>array('inherit','private','trash'),'posts_per_page'=>25,'fields'=>'ids','filehub_internal'=>true,'meta_query'=>array(array('key'=>'_filehub_storage_driver','compare'=>'EXISTS'),array('key'=>'_filehub_migrated_v2','compare'=>'NOT EXISTS'),array('relation'=>'OR',array('key'=>'_filehub_migration_attempt','compare'=>'NOT EXISTS'),array('key'=>'_filehub_migration_attempt','value'=>time()-300,'compare'=>'<','type'=>'NUMERIC')))));
            $ok=true;
            foreach($files as $id) {
                update_post_meta($id,'_filehub_migration_attempt',time());
                if(get_post_meta($id,'_filehub_storage_driver',true)==='local') {
                    $moved=(new FileHub_Storage_Local())->migrate_key(get_post_meta($id,'_filehub_storage_key',true));
                    if(is_wp_error($moved)) { $ok=false; update_option('filehub_migration_error',__('Bazı eski dosyalar özel depolamaya taşınamadı. Özel dizin izinlerini ve eski dosyaları kontrol edin.','gnn-filehub'),false); continue; }
                }
                $updated=wp_update_post(array('ID'=>$id,'post_status'=>get_post_status($id)==='trash'?'trash':'private'),true);
                if(is_wp_error($updated)) { $ok=false; continue; }
                update_post_meta($id,'_filehub_migrated_v2',1);
            }
            if(!$files) {
                global $wpdb;
                $pending=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type='attachment' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id=p.ID AND d.meta_key='_filehub_storage_driver') AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_filehub_migrated_v2')");
                if(!$pending) { update_option('filehub_migration_version','2',false); delete_option('filehub_migration_error'); }
            }
            return $ok;
        });
        return $result;
    }
    private function clean_legacy_chunks() {
        $uploads=wp_upload_dir(); $root=FileHub_Security::private_dir();
        $base=realpath($uploads['basedir'].'/filehub-protected/chunks');
        if(!$base && !is_wp_error($root))$base=realpath($root.'/legacy/chunks');
        if(!$base)return;
        // Only expired legacy part files are removed; never recurse into arbitrary paths/symlinks.
        $count=0;
        foreach(new DirectoryIterator($base) as $entry) {
            if($count>=100)break;
            if($entry->isDot() || $entry->isLink() || !$entry->isDir() || !preg_match('/^\d+_[a-zA-Z0-9_-]+$/D',$entry->getFilename()))continue;
            $dir=$entry->getRealPath(); if(!FileHub_Security::inside($dir,$base))continue;
            foreach(new DirectoryIterator($dir) as $part) {
                if($count++>=100)break;
                if(!$part->isLink() && $part->isFile() && preg_match('/^part_\d+$/D',$part->getFilename()) && $part->getMTime()<time()-DAY_IN_SECONDS)@unlink($part->getPathname());
            }
            @rmdir($dir);
        }
    }
    public function notices() {
        if(!current_user_can('manage_options'))return;
        $root=FileHub_Security::private_dir();
        $messages=array(get_option('filehub_migration_error'),get_option('filehub_cleanup_error'));
        if(is_wp_error($root))$messages[]=$root->get_error_message();
        if(!class_exists('finfo'))$messages[]=__('Dosya yükleyebilmek için PHP Fileinfo etkinleştirilmelidir.','gnn-filehub');
        foreach(array_filter($messages) as $message)echo '<div class="notice notice-error"><p>'.esc_html($message).'</p></div>';
    }
}
