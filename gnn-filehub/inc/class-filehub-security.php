<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared security primitives. No filesystem path or credential is accepted from a client. */
class FileHub_Security {
    private static $leases = array();
    public static function error( string $message, int $status = 400 ) {
        return new WP_Error( 'filehub_error', $message, array( 'status' => $status ) );
    }

    public static function inside( string $path, string $base ): bool {
        $path = wp_normalize_path( $path );
        $base = trailingslashit( wp_normalize_path( $base ) );
        if ( '\\' === DIRECTORY_SEPARATOR ) { $path = strtolower( $path ); $base = strtolower( $base ); }
        return strncmp( $path, $base, strlen( $base ) ) === 0;
    }

    public static function private_dir() {
        if(PHP_INT_SIZE<8)return self::error(__('64 bit PHP gereklidir.','gnn-filehub'),503);
        if(empty($_SERVER['DOCUMENT_ROOT']) && !defined('GNN_FILEHUB_PRIVATE_DIR'))return self::error(__('Web kökü doğrulanamadı; GNN_FILEHUB_PRIVATE_DIR yapılandırılmalıdır.','gnn-filehub'),503);
        $default = dirname( untrailingslashit( ABSPATH ) ) . '/gnn-filehub-private-' . substr( hash( 'sha256', home_url() . get_current_blog_id() ), 0, 12 );
        $path = defined( 'GNN_FILEHUB_PRIVATE_DIR' ) ? GNN_FILEHUB_PRIVATE_DIR : $default;
        if ( ! is_string( $path ) || ! path_is_absolute( $path ) ) { return self::error( __( 'Özel depolama yolu geçersiz.', 'gnn-filehub' ), 503 ); }
        $parent=realpath(dirname($path));
        if ($parent) $path=$parent . '/' . basename($path);
        if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) { return self::error( __( 'Web kökü dışında özel depolama oluşturulamadı. GNN_FILEHUB_PRIVATE_DIR yapılandırılmalı.', 'gnn-filehub' ), 503 ); }
        $real = realpath( $path );
        $roots = array( realpath( ABSPATH ) );
        if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) { $roots[] = realpath( $_SERVER['DOCUMENT_ROOT'] ); }
        foreach ( array_filter( $roots ) as $root ) {
            if ( $real === $root || self::inside( $real . '/', $root ) ) { return self::error( __( 'Özel depolama web kökü içinde olamaz.', 'gnn-filehub' ), 503 ); }
        }
        if (!$real) return self::error(__('Özel depolama bulunamadı.','gnn-filehub'),503);
        static $checked=array();
        if (!isset($checked[$real])) {
            $probe=$real.'/.probe-'.bin2hex(random_bytes(8)); $fp=@fopen($probe,'xb');
            if (!$fp) return self::error(__('Özel depolama yazılabilir değil.','gnn-filehub'),503);
            $ok=fwrite($fp,'1')===1 && fflush($fp); fclose($fp); @unlink($probe);
            if (!$ok) return self::error(__('Özel depolamaya yazılamadı.','gnn-filehub'),503);
            $checked[$real]=true;
        }
        @chmod( $real, 0700 );
        return $real;
    }

    /** Atomic unique-option lease, with compare-and-delete to avoid deleting another holder. */
    public static function locked( string $resource, callable $callback ) {
        global $wpdb;
        $key = '_filehub_lock_' . hash( 'sha256', $resource );
        $lease = wp_json_encode( array( 'token' => wp_generate_uuid4(), 'expires' => time() + 600 ) );
        if ( $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')",$key,$lease)) !== 1 ) {
            $old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $key ) );
            $data = json_decode( (string) $old, true );
            if ( ! empty( $data['expires'] ) && $data['expires'] < time() ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $key, $old ) );
                wp_cache_delete( $key, 'options' );
            }
            if ( $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')",$key,$lease)) !== 1 ) { return self::error( __( 'İşlem sürüyor; lütfen yeniden deneyin.', 'gnn-filehub' ), 409 ); }
        }
        self::$leases[$key]=$lease;
        try { return $callback(); }
        finally {
            $current=self::$leases[$key]??$lease; unset(self::$leases[$key]);
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $key, $current ) );
            wp_cache_delete( $key, 'options' );
        }
    }

    public static function heartbeat(): bool {
        global $wpdb;
        foreach(self::$leases as $key=>$lease) {
            $data=json_decode($lease,true);
            if($data['expires']>time()+120)continue;
            $data['expires']=time()+600; $renewed=wp_json_encode($data);
            if($wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",$renewed,$key,$lease))!==1)return false;
            self::$leases[$key]=$renewed;
        }
        return true;
    }
    public static function copy_stream($in,$out,int $size): bool {
        $bytes=0;
        while(!feof($in)) {
            if(!self::heartbeat())return false;
            $data=fread($in,MB_IN_BYTES); if(false===$data || ($data==='' && !feof($in)))return false;
            $offset=0;
            while($offset<strlen($data)) { $written=fwrite($out,substr($data,$offset)); if(!$written)return false; $offset+=$written; }
            $bytes+=strlen($data); if($bytes>$size)return false;
        }
        return $bytes===$size && fflush($out);
    }
    public static function file_hash(string $path) {
        $fp=@fopen($path,'rb'); if(!$fp)return false; $hash=hash_init('sha256');
        while(!feof($fp)) {
            if(!self::heartbeat()) { fclose($fp); return false; }
            $data=fread($fp,MB_IN_BYTES); if(false===$data) { fclose($fp); return false; } hash_update($hash,$data);
        }
        fclose($fp); return hash_final($hash);
    }

    public static function rate_limit( string $action, int $limit, int $window ) {
        $identity = get_current_user_id() ? 'user:' . get_current_user_id() : 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $key = 'filehub_rate_' . hash_hmac( 'sha256', $action . $identity, wp_salt( 'nonce' ) );
        return self::locked( $key, function() use ( $key, $limit, $window ) {
            $data = get_transient( $key );
            if ( ! is_array( $data ) || $data['until'] <= time() ) { $data = array( 'count' => 0, 'until' => time() + $window ); }
            if ( $data['count'] >= $limit ) { return self::error( __( 'Çok fazla istek; daha sonra yeniden deneyin.', 'gnn-filehub' ), 429 ); }
            ++$data['count'];
            if(!set_transient($key,$data,max(1,$data['until']-time())))return self::error(__('İstek sınırı kaydedilemedi; yeniden deneyin.','gnn-filehub'),503);
            return true;
        } );
    }

    public static function password_length(string $password): int { return preg_match_all('/./us',$password) ?: 0; }

    public static function allowed_extensions(): array {
        $raw = get_option( 'filehub_allowed_extensions', 'jpg,jpeg,png,gif,pdf,zip,doc,docx,xlsx' );
        $parts = explode( ',', is_string( $raw ) ? strtolower( $raw ) : '' );
        $safe = array( 'jpg','jpeg','png','gif','webp','pdf','zip','doc','docx','xls','xlsx','ppt','pptx','txt','csv','mp3','mp4' );
        return array_values( array_intersect( array_map( function( $v ) { return ltrim( trim( $v ), '.' ); }, $parts ), $safe ) );
    }

    public static function valid_filename( $name ) {
        if ( ! is_string( $name ) || strlen( $name ) > 240 ) { return self::error( __( 'Geçersiz dosya adı.', 'gnn-filehub' ) ); }
        $name = FileHub_Attachment::sanitize_upload_filename( $name );
        if ( ! $name || ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), self::allowed_extensions(), true ) ) {
            return self::error( __( 'Bu dosya uzantısına izin verilmiyor.', 'gnn-filehub' ) );
        }
        return $name;
    }

    /** MIME validation is mandatory; the old cosmetic switch cannot disable this boundary. */
    public static function validate_file( string $path, string $filename, int $expected ) {
        clearstatcache( true, $path );
        if ( ! is_file( $path ) || filesize( $path ) !== $expected || $expected < 1 ) { return self::error( __( 'Dosya boyutu doğrulanamadı.', 'gnn-filehub' ) ); }
        if ( ! class_exists( 'finfo' ) ) { return self::error( __( 'Sunucuda PHP Fileinfo etkinleştirilmelidir.', 'gnn-filehub' ), 503 ); }
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        $types = array(
            'jpg'=>array('image/jpeg'), 'jpeg'=>array('image/jpeg'), 'png'=>array('image/png'), 'gif'=>array('image/gif'), 'webp'=>array('image/webp'),
            'pdf'=>array('application/pdf'), 'zip'=>array('application/zip','application/x-zip-compressed'),
            'doc'=>array('application/msword','application/x-ole-storage','application/CDFV2'),
            'xls'=>array('application/vnd.ms-excel','application/x-ole-storage','application/CDFV2'),
            'ppt'=>array('application/vnd.ms-powerpoint','application/x-ole-storage','application/CDFV2'),
            'docx'=>array('application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'),
            'xlsx'=>array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'),
            'pptx'=>array('application/vnd.openxmlformats-officedocument.presentationml.presentation','application/zip'),
            'txt'=>array('text/plain'), 'csv'=>array('text/plain','text/csv','application/csv'), 'mp3'=>array('audio/mpeg'), 'mp4'=>array('video/mp4','application/mp4'),
        );
        $mime = ( new finfo( FILEINFO_MIME_TYPE ) )->file( $path );
        if ( ! isset( $types[$ext] ) || ! in_array( $mime, $types[$ext], true ) ) { return self::error( __( 'Dosya içeriği uzantısıyla eşleşmiyor.', 'gnn-filehub' ) ); }
        if ( in_array( $ext, array('docx','xlsx','pptx'), true ) ) {
            if ( ! class_exists( 'ZipArchive' ) ) { return self::error( __( 'Office belgelerini doğrulamak için PHP Zip etkinleştirilmelidir.', 'gnn-filehub' ), 503 ); }
            $zip = new ZipArchive();
            if ( true !== $zip->open( $path ) ) { return self::error( __( 'Geçersiz Office belgesi.', 'gnn-filehub' ) ); }
            $entry = array('docx'=>'word/document.xml','xlsx'=>'xl/workbook.xml','pptx'=>'ppt/presentation.xml')[$ext];
            $valid = false !== $zip->locateName( '[Content_Types].xml' ) && false !== $zip->locateName( $entry );
            $zip->close();
            if ( ! $valid ) { return self::error( __( 'Geçersiz Office belgesi.', 'gnn-filehub' ) ); }
            $mime = $types[$ext][0];
        }
        $antivirus=self::scan_antivirus($path); if(is_wp_error($antivirus))return $antivirus;
        $scan = apply_filters( 'filehub_scan_file', true, $path, $filename, $mime );
        if ( true !== $scan ) { return is_wp_error( $scan ) ? $scan : self::error( __( 'Dosya içerik taraması tarafından reddedildi.', 'gnn-filehub' ) ); }
        return $mime;
    }

    public static function scan_antivirus(string $path) {
        $address=get_option('filehub_antivirus_socket','');
        if($address==='')return true;
        if(!in_array($address,array('tcp://127.0.0.1:3310','unix:///var/run/clamav/clamd.ctl'),true) || !function_exists('stream_socket_client'))return self::error(__('Antivirüs bağlantısı geçersiz.','gnn-filehub'),503);
        $stream=@stream_socket_client($address,$errno,$error,5);
        if(!$stream)return self::error(__('Antivirüs hizmetine erişilemiyor; dosya yayımlanmadı.','gnn-filehub'),503);
        stream_set_timeout($stream,30);
        if(function_exists('set_time_limit'))@set_time_limit(150);
        $file=@fopen($path,'rb'); $deadline=microtime(true)+120;
        $write=function($data)use($stream,$deadline) {
            $offset=0;
            while($offset<strlen($data) && microtime(true)<$deadline) {
                $n=@fwrite($stream,substr($data,$offset)); if(!$n)return false; $offset+=$n;
            }
            return $offset===strlen($data);
        };
        $ok=$file && $write("zINSTREAM\0");
        while($ok && !feof($file)) {
            if(!self::heartbeat()) { $ok=false; break; }
            $data=fread($file,128*1024);
            if(false===$data) { $ok=false; break; }
            if($data!=='')$ok=$write(pack('N',strlen($data)).$data);
        }
        if($file)fclose($file);
        if($ok)$ok=$write(pack('N',0));
        $response='';
        while($ok && strlen($response)<4096 && microtime(true)<$deadline && !feof($stream)) {
            $part=fread($stream,1024); if(false===$part || $part==='')break;
            $response.=$part; if(strpos($response,chr(0))!==false || strpos($response,"\n")!==false)break;
        }
        fclose($stream);
        if($ok && preg_match('/:\s*OK\s*$/D',trim($response,"\0\r\n ")))return true;
        if(strpos($response,'FOUND')!==false)return self::error(__('Antivirüs dosyada zararlı içerik tespit etti.','gnn-filehub'));
        return self::error(__('Antivirüs taraması tamamlanamadı; dosya yayımlanmadı.','gnn-filehub'),503);
    }

    public static function is_filehub( int $id ): bool {
        return 'attachment' === get_post_type( $id ) && (bool) get_post_meta( $id, '_filehub_storage_driver', true );
    }

    public static function can_access( int $id ): bool {
        $post = get_post( $id );
        if(get_post_meta($id,'_filehub_upload_id',true) && (int)get_post_meta($id,'_filehub_complete',true)!==1)return false;
        return $post && self::is_filehub( $id ) && ( current_user_can( 'manage_options' ) || ( get_current_user_id() > 0 && (int) $post->post_author === get_current_user_id() && current_user_can( 'read' ) ) );
    }

    public static function download_url_for_key(string $driver,string $key): string {
        global $wpdb;
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT d.post_id FROM {$wpdb->postmeta} d JOIN {$wpdb->postmeta} k ON d.post_id=k.post_id WHERE d.meta_key='_filehub_storage_driver' AND d.meta_value=%s AND k.meta_key='_filehub_storage_key' AND k.meta_value=%s LIMIT 1",$driver,$key));
        return $id && self::can_access($id)?add_query_arg('_wpnonce',wp_create_nonce('wp_rest'),rest_url('filehub/v1/download/'.$id)):'';
    }

    public static function driver( string $name ) {
        switch ( $name ) {
            case 'local': return new FileHub_Storage_Local();
            case 'r2': return new FileHub_Storage_R2();
            case 'gdrive': return new FileHub_Storage_GDrive();
        }
        return self::error( __( 'Geçersiz depolama sürücüsü.', 'gnn-filehub' ), 503 );
    }

    public static function configuration_hash( string $driver ): string {
        $keys = array('filehub_storage_driver','filehub_r2_account_id','filehub_r2_access_key','filehub_r2_secret_key','filehub_r2_bucket','filehub_gdrive_client_id','filehub_gdrive_client_secret','filehub_gdrive_refresh_token','filehub_gdrive_folder_id');
        $values = array( $driver );
        foreach ( $keys as $key ) { $values[] = get_option( $key, '' ); }
        return hash_hmac( 'sha256', wp_json_encode( $values ), wp_salt() );
    }

    public static function stream_file( string $path, string $mime, string $name, bool $temporary = false ) {
        $fp = @fopen( $path, 'rb' );
        if ( ! $fp ) { return self::error( __( 'Dosya okunamadı.', 'gnn-filehub' ), 404 ); }
        while ( ob_get_level() ) { ob_end_clean(); }
        nocache_headers();
        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'Content-Type: ' . ( preg_match( '~^[a-z0-9.+-]+/[a-z0-9.+-]+$~i', $mime ) ? $mime : 'application/octet-stream' ) );
        header( "Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode( $name ) );
        header( 'Content-Length: ' . filesize( $path ) );
        if(function_exists('set_time_limit'))@set_time_limit(0);
        $deadline=microtime(true)+1800;
        $ok = true;
        while ( microtime(true)<$deadline && ! feof( $fp ) && ! connection_aborted() ) {
            $chunk = fread( $fp, 1024 * 1024 );
            if ( false === $chunk ) { $ok = false; break; }
            echo $chunk;
        }
        $complete = $ok && feof( $fp ) && ! connection_aborted();
        fclose( $fp );
        if ( $temporary ) { @unlink( $path ); }
        if ( $complete ) { do_action( 'filehub_stream_complete' ); }
        exit;
    }
    public static function client_config(): array {
        return array('rest_url'=>esc_url_raw(rest_url()),'api_url'=>esc_url_raw(rest_url('filehub/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'user_id'=>get_current_user_id(),'strings'=>array(
            'show'=>__('Göster','gnn-filehub'),
            'hide'=>__('Gizle','gnn-filehub'),
            'uploading'=>__('Yükleniyor','gnn-filehub'),
            'processing'=>__('Dosya işleniyor; sayfayı açık tutun.','gnn-filehub'),
            'done'=>__('Yükleme tamamlandı.','gnn-filehub'),
            'chooseAgain'=>__('Devam etmek için aynı dosyayı yeniden seçin.','gnn-filehub'),
            'cancelled'=>__('Yükleme iptal edildi.','gnn-filehub'),
            'cancel'=>__('İptal','gnn-filehub'),
            'busy'=>__('Önce mevcut yüklemenin bitmesini bekleyin.','gnn-filehub'),
            'error'=>__('İşlem başarısız.','gnn-filehub'),
            'network'=>__('Bağlantı hatası; yeniden deneyin.','gnn-filehub'),
            'loading'=>__('Yükleniyor…','gnn-filehub'),
            'empty'=>__('Dosya bulunamadı.','gnn-filehub'),
            'file'=>__('Dosya adı','gnn-filehub'),
            'size'=>__('Boyut','gnn-filehub'),
            'driver'=>__('Sürücü','gnn-filehub'),
            'owner'=>__('Kullanıcı','gnn-filehub'),
            'downloads'=>__('İndirme','gnn-filehub'),
            'actions'=>__('İşlem','gnn-filehub'),
            'download'=>__('İndir','gnn-filehub'),
            'delete'=>__('Sil','gnn-filehub'),
            'confirm'=>__('Onayla?','gnn-filehub'),
            'previous'=>__('Önceki','gnn-filehub'),
            'next'=>__('Sonraki','gnn-filehub'),
            'preview'=>__('Önizle','gnn-filehub'),
            'close'=>__('Kapat','gnn-filehub'),
            'previewLimit'=>__('Önizleme yalnızca 8 MB altındaki resim ve PDF dosyaları için kullanılabilir.','gnn-filehub'),
            'updating'=>__('Güncelleniyor…','gnn-filehub'),
            'registering'=>__('Kayıt işlemi yapılıyor…','gnn-filehub'),
            'retry'=>__('Yeniden dene','gnn-filehub'),
            'saved'=>__('Bilgiler güncellendi.','gnn-filehub'),
            'refresh'=>__('Oturum değişti; sayfa yenileniyor.','gnn-filehub'),
            'failedFiles'=>__('Bazı dosyalar yüklenemedi.','gnn-filehub'),
        ));
    }
}
