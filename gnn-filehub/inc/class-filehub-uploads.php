<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Persistent reservations and resumable uploads. All writes serialize per owner, including guests. */
class FileHub_Uploads {
    const TTL = 21600;

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'filehub_uploads'; }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta( "CREATE TABLE $table (
            id varchar(36) NOT NULL,
            owner bigint(20) unsigned NOT NULL,
            bytes bigint(20) unsigned NOT NULL DEFAULT 0,
            expires bigint(20) unsigned NOT NULL,
            payload longtext NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_expiry (owner,expires),
            KEY expiry (expires)
        ) " . $wpdb->get_charset_collate() . ';' );
        $downloads=$wpdb->prefix.'filehub_downloads';
        dbDelta("CREATE TABLE $downloads (
            id varchar(36) NOT NULL,
            owner bigint(20) unsigned NOT NULL,
            bytes bigint(20) unsigned NOT NULL,
            expires bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY expiry (expires)
        ) ".$wpdb->get_charset_collate().';');
        update_option( 'filehub_schema', '2', false );
    }

    public static function chunk_size(): int {
        $php_limit = min( wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ), wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ) );
        return max( 16384, min( 1024 * 1024, $php_limit > 0 ? (int) floor( $php_limit / 2 ) : 1024 * 1024 ) );
    }

    private static function save( array $s ): bool {
        global $wpdb;
        return false !== $wpdb->update( self::table(), array( 'payload'=>wp_json_encode($s), 'bytes'=>$s['status']==='done' ? 0 : $s['size'], 'expires'=>$s['expires'] ), array('id'=>$s['id']) );
    }

    public static function load( string $id, string $token ) {
        global $wpdb;
        if ( ! preg_match('/^[a-f0-9-]{36}$/D', $id) ) { return FileHub_Security::error( __('Yükleme bulunamadı.', 'gnn-filehub'), 404 ); }
        $raw = $wpdb->get_var( $wpdb->prepare('SELECT payload FROM '.self::table().' WHERE id=%s', $id) );
        $s = json_decode( (string)$raw, true );
        if ( ! is_array($s) || $s['owner'] !== get_current_user_id() || ! hash_equals( $s['token_hash'], hash('sha256',$token) ) ) {
            return FileHub_Security::error( __('Yükleme bulunamadı.', 'gnn-filehub'), 404 );
        }
        if ( $s['expires'] < time() ) { return FileHub_Security::error( __('Yükleme oturumunun süresi doldu.', 'gnn-filehub'), 410 ); }
        return $s;
    }

    public static function status( array $s, bool $include_received = true ): array {
        $data = array('success'=>true,'upload_id'=>$s['id'],'status'=>$s['status'],'chunk_size'=>$s['chunk_size'],'total_chunks'=>$s['chunks'],'received'=>$include_received?array_map('intval',array_keys($s['received'])):array(),'received_hashes'=>$include_received?$s['received']:array(),'expires'=>$s['expires']);
        if ( isset($s['attachment_id']) ) { $data['attachment_id']=$s['attachment_id']; }
        if ( isset($s['transfer']['offset']) ) { $data['transferred']=$s['transfer']['offset']; }
        return $data;
    }

    public static function begin( $filename, $size ) {
        global $wpdb;
        $filename = FileHub_Security::valid_filename($filename);
        if ( is_wp_error($filename) ) { return $filename; }
        if ( ! is_int($size) || $size < 1 ) { return FileHub_Security::error( __('Geçersiz dosya boyutu.', 'gnn-filehub') ); }
        if(!class_exists('finfo'))return FileHub_Security::error(__('Sunucuda PHP Fileinfo etkinleştirilmelidir.','gnn-filehub'),503);
        if(in_array(strtolower(pathinfo($filename,PATHINFO_EXTENSION)),array('docx','xlsx','pptx'),true) && !class_exists('ZipArchive'))return FileHub_Security::error(__('Office belgeleri için PHP Zip etkinleştirilmelidir.','gnn-filehub'),503);
        $max = FileHub_Attachment::get_max_upload_bytes();
        $staging = max( 1, (int) apply_filters('filehub_staging_limit_bytes', 10 * GB_IN_BYTES) );
        if ( ($max > 0 && $size > $max) || $size > $staging ) { return FileHub_Security::error( __('Dosya yükleme sınırını aşıyor.', 'gnn-filehub'), 413 ); }
        $root = FileHub_Security::private_dir();
        if ( is_wp_error($root) ) { return $root; }
        $uid = get_current_user_id();
        return FileHub_Security::locked('owner:'.$uid, function() use ($filename,$size,$uid,$root,$staging,$wpdb) {
            $table=self::table();
            // Expired sessions retain their reservation until their on-disk/cloud data is cleaned.
            $row=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(bytes),0) AS reserved FROM $table WHERE owner=%d AND bytes>0",$uid));
            if ( (int)$row->n >= 3 ) { return FileHub_Security::error( __('En fazla üç açık yükleme olabilir; eski yüklemeyi iptal edin.', 'gnn-filehub'), 429 ); }
            $stats=FileHub_Attachment::get_user_stats($uid);
            if ( $stats['quota_bytes'] > 0 && $stats['total_bytes'] + (int)$row->reserved + $size > $stats['quota_bytes'] ) { return FileHub_Security::error( __('Depolama kotanız yetersiz.', 'gnn-filehub'), 413 ); }
            $downloads=$wpdb->prefix.'filehub_downloads';
            $reserved=(int)$wpdb->get_var("SELECT COALESCE(SUM(bytes),0) FROM $table")+(int)$wpdb->get_var("SELECT COALESCE(SUM(bytes),0) FROM $downloads");
            $site_cap=(int)apply_filters('filehub_site_staging_limit_bytes', 20 * GB_IN_BYTES);
            $free=@disk_free_space($root);
            if ( (int)$row->reserved+$size>$staging || $reserved+$size>$site_cap || false===$free || $free < $reserved+$size+16*MB_IN_BYTES ) {
                return FileHub_Security::error( __('Geçici depolama kapasitesi yetersiz.', 'gnn-filehub'), 507 );
            }
            // A separate global lease closes the cross-user reservation race.
            return FileHub_Security::locked('staging', function() use($filename,$size,$uid,$root,$wpdb,$table,$site_cap) {
                if ((int)$wpdb->get_var("SELECT COALESCE(SUM(bytes),0) FROM $table")+(int)$wpdb->get_var("SELECT COALESCE(SUM(bytes),0) FROM {$wpdb->prefix}filehub_downloads")+$size>$site_cap) { return FileHub_Security::error(__('Geçici depolama kapasitesi yetersiz.','gnn-filehub'),507); }
                $id=wp_generate_uuid4(); $token=bin2hex(random_bytes(32));
                $dir=$root.'/sessions/'.$id;
                if (!wp_mkdir_p($dir)) { return FileHub_Security::error(__('Geçici klasör oluşturulamadı.','gnn-filehub'),503); }
                $driver=get_option('filehub_storage_driver','local');
                if (!in_array($driver,array('local','r2','gdrive'),true)) { return FileHub_Security::error(__('Geçersiz sürücü.','gnn-filehub')); }
                $s=array('id'=>$id,'owner'=>$uid,'token_hash'=>hash('sha256',$token),'filename'=>$filename,'size'=>$size,'chunk_size'=>self::chunk_size(),'chunks'=>(int)ceil($size/self::chunk_size()),'received'=>array(),'status'=>'receiving','expires'=>time()+self::TTL,'driver'=>$driver,'config'=>FileHub_Security::configuration_hash($driver));
                if(false===$wpdb->insert($table,array('id'=>$id,'owner'=>$uid,'bytes'=>$size,'expires'=>$s['expires'],'payload'=>wp_json_encode($s)))) { @rmdir($dir); return FileHub_Security::error(__('Yükleme oturumu kaydedilemedi.','gnn-filehub'),500); }
                return array_merge(self::status($s,false),array('upload_token'=>$token));
            });
        });
    }

    private static function path( array $s ) {
        $root=FileHub_Security::private_dir();
        if(is_wp_error($root)) { return $root; }
        $dir=realpath($root.'/sessions/'.$s['id']);
        if(!$dir || !FileHub_Security::inside($dir,$root)) { return FileHub_Security::error(__('Geçici dosya bulunamadı.','gnn-filehub'),404); }
        return $dir.'/content';
    }

    public static function operate( string $id, string $token, callable $fn ) {
        return FileHub_Security::locked('owner:'.get_current_user_id(),function() use($id,$token,$fn) {
            $s=self::load($id,$token);
            return is_wp_error($s) ? $s : $fn($s);
        });
    }

    public static function append( array $s, $index, array $file ) {
        if($s['status']!=='receiving') { return FileHub_Security::error(__('Yükleme artık parça kabul etmiyor.','gnn-filehub'),409); }
        if(!is_int($index) || $index<0 || $index >= $s['chunks']) { return FileHub_Security::error(__('Geçersiz parça indeksi.','gnn-filehub')); }
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) { return FileHub_Security::error(__('Geçersiz parça yüklemesi.','gnn-filehub')); }
        return self::append_trusted($s,$index,$file['tmp_name']);
    }

    /** Internal seam also exercised by filesystem regression tests; not exposed as a REST path. */
    public static function append_trusted( array $s, int $index, string $source ) {
        if($index<0 || $index >= $s['chunks'] || $s['status']!=='receiving') { return FileHub_Security::error(__('Geçersiz parça.','gnn-filehub')); }
        $expected=min($s['chunk_size'],$s['size']-$index*$s['chunk_size']);
        if(!is_file($source) || filesize($source)!==$expected) { return FileHub_Security::error(__('Parça boyutu hatalı.','gnn-filehub')); }
        $hash=hash_file('sha256',$source);
        if(isset($s['received'][$index])) {
            return hash_equals($s['received'][$index],$hash) ? self::status($s,false) : FileHub_Security::error(__('Aynı parça farklı içerikle gönderildi.','gnn-filehub'),409);
        }
        $path=self::path($s);
        if(is_wp_error($path)) { return $path; }
        $out=@fopen($path,'c+b'); $in=@fopen($source,'rb');
        if(!$out || !$in) { if($out)fclose($out); if($in)fclose($in); return FileHub_Security::error(__('Parça yazılamadı.','gnn-filehub'),500); }
        $ok=fseek($out,$index*$s['chunk_size'])===0 && stream_copy_to_stream($in,$out)===$expected && fflush($out);
        fclose($in); fclose($out);
        if(!$ok) { return FileHub_Security::error(__('Parça yazılamadı.','gnn-filehub'),500); }
        $s['received'][$index]=$hash;
        if(!self::save($s)) { return FileHub_Security::error(__('Parça kaydı yazılamadı.','gnn-filehub'),500); }
        return self::status($s,false);
    }

    private static function reconcile(array $s) {
        global $wpdb;
        $id=$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_filehub_upload_id' AND meta_value=%s LIMIT 1",$s['id']));
        if($id && FileHub_Security::is_filehub((int)$id) && (int)get_post_meta($id,'_filehub_complete',true)===1 && (int)get_post_meta($id,'_filehub_file_size',true)===$s['size'] && (int)get_post_field('post_author',$id)===$s['owner']) {
            $s['status']='done'; $s['attachment_id']=(int)$id;
            if(!self::save($s))return FileHub_Security::error(__('Tamamlanma kaydı yazılamadı.','gnn-filehub'),500);
            $path=self::path($s); if(!is_wp_error($path)) { @unlink($path); @rmdir(dirname($path)); }
            return self::status($s,false);
        }
        return null;
    }
    public static function complete( array $s ) {
        global $wpdb;
        if($s['status']==='done') { return self::status($s,false); }
        $reconciled=self::reconcile($s); if($reconciled!==null)return $reconciled;
        if($s['config']!==FileHub_Security::configuration_hash($s['driver'])) { return FileHub_Security::error(__('Depolama ayarları değişti; yüklemeyi iptal edip yeniden başlatın.','gnn-filehub'),409); }
        $name=FileHub_Security::valid_filename($s['filename']); if(is_wp_error($name))return $name;
        $quota=FileHub_Attachment::get_user_stats($s['owner']); $maximum=FileHub_Attachment::get_max_upload_bytes();
        if(($maximum>0 && $s['size']>$maximum) || ($quota['quota_bytes']>0 && $quota['total_bytes']+$s['size']>$quota['quota_bytes']))return FileHub_Security::error(__('Güncel yükleme veya kota sınırı aşılıyor.','gnn-filehub'),413);
        if(count($s['received'])!==$s['chunks']) { return FileHub_Security::error(__('Dosyanın bütün parçaları henüz alınmadı.','gnn-filehub'),409); }
        $path=self::path($s); if(is_wp_error($path))return $path;
        if(!isset($s['mime'])) {
            $mime=FileHub_Security::validate_file($path,$s['filename'],$s['size']);
            if(is_wp_error($mime))return $mime;
            $s['mime']=$mime; $s['status']='processing';
            if(!self::save($s))return FileHub_Security::error(__('Yükleme kaydedilemedi.','gnn-filehub'),500);
        }
        $driver=FileHub_Security::driver($s['driver']); if(is_wp_error($driver))return $driver;
        if(!isset($s['result'])) {
            if($s['driver']==='local') {
                $result=$driver->upload_file(array('tmp_name'=>$path,'name'=>$s['filename'],'type'=>$s['mime'],'size'=>$s['size'],'error'=>0,'session_id'=>$s['id']),$s['owner']);
            } else {
                $step=$driver->transfer_step($path,$s);
                if(is_wp_error($step))return $step;
                $s['transfer']=$step['transfer'];
                if(isset($step['result']))$s['result']=$step['result'];
                if(!self::save($s))return FileHub_Security::error(__('Aktarım durumu kaydedilemedi.','gnn-filehub'),500);
                if(!isset($s['result']))return self::status($s,false);
                $result=$s['result'];
            }
            if(is_wp_error($result))return $result;
            $s['result']=$result;
            if(!self::save($s))return FileHub_Security::error(__('Aktarım kaydı yazılamadı.','gnn-filehub'),500);
        }
        // The upload ID is persisted on the attachment to reconcile a response lost after commit.
        $result=$s['result']; $result['upload_id']=$s['id'];
        $id=FileHub_Attachment::create_attachment($result,$s['owner'],$s['mime']);
        if(is_wp_error($id)) {
            // Keep the reservation and result for a retry; never discard an untracked cloud object.
            return $id;
        }
        $s['status']='done'; $s['attachment_id']=$id;
        if(!self::save($s))return FileHub_Security::error(__('Tamamlanma kaydı yazılamadı; yeniden deneyin.','gnn-filehub'),500);
        @unlink($path); @rmdir(dirname($path));
        return self::status($s,false);
    }

    public static function cancel( array $s ) {
        global $wpdb;
        if($s['status']==='done')return self::status($s,false);
        $reconciled=self::reconcile($s); if($reconciled!==null)return $reconciled;
        if($s['driver']!=='local' && (!empty($s['transfer']) || isset($s['result'])) && $s['config']!==FileHub_Security::configuration_hash($s['driver'])) { return FileHub_Security::error(__('Eski depolama ayarları geri yüklenmeden bulut oturumu temizlenemez.','gnn-filehub'),409); }
        $driver=FileHub_Security::driver($s['driver']);
        if(is_wp_error($driver))return $driver;
        if(isset($s['result'])) {
            $deleted=$driver->delete_file($s['result']['storage_key']);
            if(is_wp_error($deleted) || !$deleted)return FileHub_Security::error(__('Bulut dosyası silinemedi; rezervasyon korundu.','gnn-filehub'),503);
        } elseif(!empty($s['transfer']) && method_exists($driver,'abort_transfer')) {
            $deleted=$driver->abort_transfer($s['transfer']);
            if(is_wp_error($deleted) || !$deleted)return FileHub_Security::error(__('Bulut oturumu temizlenemedi.','gnn-filehub'),503);
        }
        $path=self::path($s);
        if(!is_wp_error($path)) {
            if(is_file($path) && !unlink($path))return FileHub_Security::error(__('Geçici dosya silinemedi.','gnn-filehub'),503);
            @rmdir(dirname($path));
        }
        if(false===$wpdb->delete(self::table(),array('id'=>$s['id'])))return FileHub_Security::error(__('Oturum silinemedi.','gnn-filehub'),500);
        return array('success'=>true,'status'=>'cancelled');
    }

    public static function reserve_download(int $size) {
        global $wpdb;
        $root=FileHub_Security::private_dir(); if(is_wp_error($root))return $root;
        return FileHub_Security::locked('staging',function()use($size,$root,$wpdb) {
            $table=$wpdb->prefix.'filehub_downloads'; $owner=get_current_user_id();
            if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE owner=%d",$owner))>=2)return FileHub_Security::error(__('En fazla iki indirme aynı anda işlenebilir.','gnn-filehub'),429);
            $reserved=(int)$wpdb->get_var("SELECT COALESCE(SUM(bytes),0) FROM $table")+(int)$wpdb->get_var('SELECT COALESCE(SUM(bytes),0) FROM '.self::table());
            if($size<1 || $reserved+$size>(int)apply_filters('filehub_site_staging_limit_bytes',20*GB_IN_BYTES) || @disk_free_space($root)<$reserved+$size+16*MB_IN_BYTES)return FileHub_Security::error(__('İndirme için geçici alan yetersiz.','gnn-filehub'),507);
            $id=wp_generate_uuid4();
            if(false===$wpdb->insert($table,array('id'=>$id,'owner'=>$owner,'bytes'=>$size,'expires'=>time()+HOUR_IN_SECONDS)))return FileHub_Security::error(__('İndirme rezervasyonu oluşturulamadı.','gnn-filehub'),500);
            register_shutdown_function(function()use($id){self::release_download($id);});
            return array('id'=>$id,'path'=>$root.'/download-'.$id);
        });
    }
    public static function release_download(string $id) {
        global $wpdb;
        if(!preg_match('/^[a-f0-9-]{36}$/D',$id))return;
        $root=FileHub_Security::private_dir(); if(is_wp_error($root))return;
        $path=$root.'/download-'.$id;
        if(is_file($path) && !@unlink($path))return;
        $wpdb->delete($wpdb->prefix.'filehub_downloads',array('id'=>$id));
    }
    public static function cleanup() {
        global $wpdb;
        $expired=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}filehub_downloads WHERE expires<%d LIMIT 25",time()));
        foreach($expired as $id)self::release_download($id);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT payload FROM '.self::table().' WHERE expires<%d ORDER BY expires LIMIT 25',time()));
        foreach($rows as $row) {
            $s=json_decode($row->payload,true); if(!is_array($s))continue;
            FileHub_Security::locked('owner:'.$s['owner'],function()use($s,$wpdb) {
                $fresh=json_decode((string)$wpdb->get_var($wpdb->prepare('SELECT payload FROM '.self::table().' WHERE id=%s',$s['id'])),true);
                if(!$fresh || $fresh['expires']>=time())return;
                if($fresh['status']==='done') { $wpdb->delete(self::table(),array('id'=>$fresh['id'])); return; }
                $result=self::cancel($fresh);
                if(is_wp_error($result))update_option('filehub_cleanup_error',__('Süresi dolmuş bulut oturumları temizlenemedi; depolama ayarlarını kontrol edin.','gnn-filehub'),false);
            });
        }
    }
}
