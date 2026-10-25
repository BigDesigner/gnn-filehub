<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once GNN_FILEHUB_PATH . 'inc/storage/class-storage-interface.php';
require_once GNN_FILEHUB_PATH . 'inc/class-filehub-attachment.php';

/**
 * Class FileHub_Storage_GDrive
 * Google Drive API v3 Storage driver using WordPress Core HTTP API.
 */
class FileHub_Storage_GDrive implements FileHub_Storage_Interface {

    /** Bytes read from disk per PUT when streaming a resumable upload. */
    const RESUMABLE_CHUNK_BYTES = 8 * 1024 * 1024; // 8MB

    private $client_id;
    private $client_secret;
    private $refresh_token;
    private $folder_id;

    public function __construct() {
        $this->client_id     = get_option( 'filehub_gdrive_client_id', '' );
        $this->client_secret = get_option( 'filehub_gdrive_client_secret', '' );
        $this->refresh_token = get_option( 'filehub_gdrive_refresh_token', '' );
        $this->folder_id     = get_option( 'filehub_gdrive_folder_id', '' );
    }

    /**
     * Refresh OAuth2 Access Token
     */
    private function get_access_token() {
        $transient_key = 'filehub_gdrive_token_' . substr(FileHub_Security::configuration_hash('gdrive'),0,24);
        $cached_token  = get_transient( $transient_key );

        if ( $cached_token ) {
            return $cached_token;
        }

        if ( empty( $this->client_id ) || empty( $this->client_secret ) || empty( $this->refresh_token ) ) {
            return new WP_Error( 'filehub_gdrive_config_missing', __( 'Google Drive API bilgileri eksik.', 'gnn-filehub' ) );
        }

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'body' => array(
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $this->refresh_token,
                'grant_type'    => 'refresh_token',
            ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            set_transient($transient_key, $response, 60);
            return $response;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) {
            $error=FileHub_Security::error(__('Google Drive kimlik doğrulaması başarısız.','gnn-filehub'),502);
            set_transient($transient_key,$error,60);
            return $error;
        }

        set_transient( $transient_key, $data['access_token'], max(60, (int)($data['expires_in'] ?? 3600) - 60) );
        return $data['access_token'];
    }

    /**
     * Get (or Create) a Per-User Subfolder Inside the Configured Target Folder
     * Every user's files land in their own "<user_id>" folder instead of a shared pile, mirroring
     * the local driver's per-user directory layout. The resolved folder ID is cached for a day
     * so a normal upload doesn't cost an extra search+create round trip to Google every time.
     *
     * @param int    $user_id
     * @param string $access_token
     * @return string|WP_Error Folder ID, or WP_Error on failure.
     */
    private function get_or_create_user_folder( int $user_id, string $access_token ) {
        $cache_key = 'filehub_gdrive_folder_' . substr(FileHub_Security::configuration_hash('gdrive'),0,24) . '_' . $user_id;
        $cached    = get_transient( $cache_key );
        if ( $cached ) {
            return $cached;
        }

        $parent      = ! empty( $this->folder_id ) ? $this->folder_id : 'root';
        $folder_name = (string) $user_id;

        $query = sprintf(
            "name = '%s' and mimeType = 'application/vnd.google-apps.folder' and '%s' in parents and trashed = false",
            str_replace( "'", "\\'", $folder_name ),
            str_replace( "'", "\\'", $parent )
        );

        $search_url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query( array(
            'q'        => $query,
            'fields'   => 'files(id,name)',
            'pageSize' => 1,
        ) );

        $search_response = wp_remote_get( $search_url, array(
            'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
            'timeout' => 30,
        ) );

        if (is_wp_error($search_response))return $search_response;
        if (wp_remote_retrieve_response_code($search_response)!==200)return FileHub_Security::error(__('Google klasör sorgusu başarısız.','gnn-filehub'),502);
        if ( ! is_wp_error( $search_response ) ) {
            $search_data = json_decode( wp_remote_retrieve_body( $search_response ), true );
            if ( ! empty( $search_data['files'][0]['id'] ) ) {
                $folder_id = $search_data['files'][0]['id'];
                set_transient( $cache_key, $folder_id, DAY_IN_SECONDS );
                return $folder_id;
            }
        }

        // Not found — create it.
        $create_response = wp_remote_post( 'https://www.googleapis.com/drive/v3/files', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json; charset=UTF-8',
            ),
            'body'    => wp_json_encode( array(
                'name'     => $folder_name,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents'  => array( $parent ),
            ) ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $create_response ) ) {
            return $create_response;
        }

        $create_data = json_decode( wp_remote_retrieve_body( $create_response ), true );
        if ( empty( $create_data['id'] ) ) {
            $reason = ! empty( $create_data['error']['message'] ) ? $create_data['error']['message'] : wp_remote_retrieve_response_code( $create_response );
            return new WP_Error(
                'filehub_gdrive_folder_failed',
                sprintf(
                    /* translators: %s: raw error reason returned by Google */
                    __( 'Kullanıcı klasörü oluşturulamadı: %s', 'gnn-filehub' ),
                    $reason
                )
            );
        }

        $folder_id = $create_data['id'];
        set_transient( $cache_key, $folder_id, DAY_IN_SECONDS );
        return $folder_id;
    }

    /**
     * Upload File to Google Drive
     * Small files use a single simple multipart request. Larger files use Google's resumable
     * upload protocol, streamed from disk in bounded chunks — the whole point is that memory
     * usage stays flat (a few MB) no matter how large the file is, instead of loading the
     * entire file into a PHP string, which is what silently exhausted memory on large uploads.
     */
    public function upload_file(array $file,int $user_id) { return FileHub_Security::error(__('Güvenli yükleme oturumu gereklidir.','gnn-filehub'),410); }

    private function session_request(string $url,array $args) {
        $parts=wp_parse_url($url);
        if(!$parts || ($parts['scheme']??'')!=='https' || !in_array($parts['host']??'',array('www.googleapis.com','content.googleapis.com'),true) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port']!==443) || strpos($parts['path']??'','/upload/drive/v3/files')!==0)return FileHub_Security::error(__('Geçersiz Google aktarım adresi.','gnn-filehub'),502);
        return wp_remote_request($url,array_merge(array('redirection'=>0,'timeout'=>60),$args));
    }

    public function transfer_step(string $path,array $s) {
        $token=$this->get_access_token(); if(is_wp_error($token))return $token;
        $t=$s['transfer']??array();
        if(!$t) {
            $folder=$this->get_or_create_user_folder($s['owner'],$token); if(is_wp_error($folder))return $folder;
            $r=wp_remote_post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable',array('headers'=>array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','X-Upload-Content-Type'=>$s['mime'],'X-Upload-Content-Length'=>(string)$s['size']),'body'=>wp_json_encode(array('name'=>$s['filename'],'parents'=>array($folder),'appProperties'=>array('filehub_upload_id'=>$s['id']))),'timeout'=>30,'redirection'=>0));
            if(is_wp_error($r))return $r;
            $url=(string)wp_remote_retrieve_header($r,'location');
            if(!in_array(wp_remote_retrieve_response_code($r),array(200,201),true) || !$url)return FileHub_Security::error(__('Google aktarımı başlatılamadı.','gnn-filehub'),502);
            return array('transfer'=>array('url'=>$url,'offset'=>0));
        }
        // Ask the provider's committed offset on every retry, including a lost final response.
        $r=$this->session_request($t['url'],array('method'=>'PUT','headers'=>array('Authorization'=>'Bearer '.$token,'Content-Length'=>'0','Content-Range'=>'bytes */'.$s['size']),'body'=>''));
        if(is_wp_error($r))return $r;
        $code=wp_remote_retrieve_response_code($r);
        if(in_array($code,array(200,201),true))return $this->transfer_result($r,$t,$s);
        if($code!==308)return FileHub_Security::error(__('Google oturumu doğrulanamadı; yeniden deneyin veya iptal edin.','gnn-filehub'),502);
        $range=(string)wp_remote_retrieve_header($r,'range'); $offset=0;
        if($range && preg_match('/^bytes=0-(\d+)$/D',$range,$m))$offset=(int)$m[1]+1;
        if($offset<0 || $offset>=$s['size'])return FileHub_Security::error(__('Geçersiz Google aktarım ilerlemesi.','gnn-filehub'),502);
        $fp=@fopen($path,'rb'); if(!$fp)return FileHub_Security::error(__('Geçici dosya okunamadı.','gnn-filehub'),500);
        $ok=fseek($fp,$offset)===0; $length=min(self::RESUMABLE_CHUNK_BYTES,$s['size']-$offset); $data=$ok?fread($fp,$length):false; fclose($fp);
        if(false===$data || strlen($data)!==$length)return FileHub_Security::error(__('Geçici dosya eksik.','gnn-filehub'),500);
        $r=$this->session_request($t['url'],array('method'=>'PUT','headers'=>array('Authorization'=>'Bearer '.$token,'Content-Length'=>(string)$length,'Content-Range'=>'bytes '.$offset.'-'.($offset+$length-1).'/'.$s['size']),'body'=>$data));
        if(is_wp_error($r))return $r;
        $code=wp_remote_retrieve_response_code($r);
        if(in_array($code,array(200,201),true))return $this->transfer_result($r,$t,$s);
        if($code!==308)return FileHub_Security::error(__('Google parçası kaydedilemedi; yeniden denenebilir.','gnn-filehub'),502);
        $range=(string)wp_remote_retrieve_header($r,'range');
        if(!preg_match('/^bytes=0-(\d+)$/D',$range,$m) || (int)$m[1]+1!==$offset+$length)return FileHub_Security::error(__('Google parça boyutu doğrulanamadı.','gnn-filehub'),502);
        $t['offset']=$offset+$length;
        return array('transfer'=>$t);
    }
    private function transfer_result($r,array $t,array $s) {
        $data=json_decode(wp_remote_retrieve_body($r),true);
        if(empty($data['id']) || !is_string($data['id']))return FileHub_Security::error(__('Google dosya kaydı doğrulanamadı.','gnn-filehub'),502);
        $t['offset']=$s['size'];
        return array('transfer'=>$t,'result'=>array('storage_driver'=>'gdrive','storage_key'=>$data['id'],'file_name'=>$s['filename'],'file_size'=>$s['size']));
    }
    public function abort_transfer(array $t) {
        $r=$this->session_request($t['url'],array('method'=>'DELETE'));
        if(is_wp_error($r))return $r;
        return in_array(wp_remote_retrieve_response_code($r),array(200,204,404,410),true) ? true : FileHub_Security::error(__('Google aktarımı iptal edilemedi.','gnn-filehub'),502);
    }

    /**
     * Get the Google Account's Real Storage Quota (Not an Assumed Free-Tier Number)
     * Queries Drive's own `about` endpoint so the dashboard reflects whatever plan is actually
     * on the connected account (free 15GB, a paid Google One plan, Workspace, ...) instead of
     * always assuming the free tier. Cached for an hour since it rarely changes and costs an
     * extra API round trip otherwise.
     *
     * @return array|WP_Error {limit: int|null (bytes, null = unlimited), usage: int (bytes)}
     */
    public function get_storage_quota() {
        $cache_key = 'filehub_gdrive_quota_' . substr(FileHub_Security::configuration_hash('gdrive'),0,24);
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $access_token = $this->get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $response = wp_remote_get( 'https://www.googleapis.com/drive/v3/about?fields=storageQuota', array(
            'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['storageQuota'] ) ) {
            $reason = ! empty( $data['error']['message'] ) ? $data['error']['message'] : wp_remote_retrieve_response_code( $response );
            return new WP_Error(
                'filehub_gdrive_quota_failed',
                sprintf(
                    /* translators: %s: raw error reason returned by Google */
                    __( 'Google Drive kota bilgisi alınamadı: %s', 'gnn-filehub' ),
                    $reason
                )
            );
        }

        $result = array(
            // Some plans (rare) report no limit at all — treat as unlimited rather than 0.
            'limit' => isset( $data['storageQuota']['limit'] ) ? (int) $data['storageQuota']['limit'] : null,
            'usage' => isset( $data['storageQuota']['usage'] ) ? (int) $data['storageQuota']['usage'] : 0,
        );

        set_transient( $cache_key, $result, HOUR_IN_SECONDS );
        return $result;
    }

    /**
     * Delete File from Google Drive
     */
    public function delete_file( string $file_identifier ) {
        $access_token = $this->get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $url      = 'https://www.googleapis.com/drive/v3/files/' . urlencode( $file_identifier );
        $response = wp_remote_request( $url, array(
            'method'  => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'timeout' => 30,
        ) );

        if (is_wp_error($response)) return $response;
        return in_array(wp_remote_retrieve_response_code($response), array(200,204,404,410), true) ? true : FileHub_Security::error(__('Google dosyası silinemedi.','gnn-filehub'),502);
    }

    /**
     * Stream File Download from Google Drive
     * Downloads to a temp file on disk first (WP_Http's 'stream' mode), then relays it to the
     * client — this keeps memory flat for large files instead of loading the whole body into a
     * PHP string via wp_remote_retrieve_body(), which has the same out-of-memory risk on
     * download that the old upload path had.
     */
    public function get_download_stream(string $file_identifier,string $mime_type='application/octet-stream',string $original_name='') {
        $token=$this->get_access_token(); if(is_wp_error($token))return $token;
        $root=FileHub_Security::private_dir(); if(is_wp_error($root))return $root;
        $base='https://www.googleapis.com/drive/v3/files/'.rawurlencode($file_identifier);
        $meta=wp_remote_get($base.'?fields=size',array('headers'=>array('Authorization'=>'Bearer '.$token),'timeout'=>30));
        if(is_wp_error($meta) || wp_remote_retrieve_response_code($meta)!==200)return FileHub_Security::error(__('Google dosyası bulunamadı.','gnn-filehub'),502);
        $data=json_decode(wp_remote_retrieve_body($meta),true); $size=(int)($data['size']??0);
        $slot=FileHub_Uploads::reserve_download($size); if(is_wp_error($slot))return $slot;
        $tmp=$slot['path'];
        $r=wp_remote_get($base.'?alt=media',array('headers'=>array('Authorization'=>'Bearer '.$token),'timeout'=>300,'stream'=>true,'filename'=>$tmp,'limit_response_size'=>$size+1,'redirection'=>0));
        if(is_wp_error($r) || wp_remote_retrieve_response_code($r)!==200 || !is_file($tmp) || filesize($tmp)!==$size) { @unlink($tmp); return FileHub_Security::error(__('Google dosyası indirilemedi.','gnn-filehub'),502); }
        return FileHub_Security::stream_file($tmp,$mime_type,$original_name?:$file_identifier,true);
    }
    public function get_file_url(string $file_identifier): string { return FileHub_Security::download_url_for_key('gdrive',$file_identifier); }
}
