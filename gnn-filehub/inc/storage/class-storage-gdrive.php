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

    /** Files at or under this size use the simple one-request multipart upload. */
    const SIMPLE_UPLOAD_MAX_BYTES = 8 * 1024 * 1024; // 8MB

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
        $transient_key = 'filehub_gdrive_access_token';
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
            return $response;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) {
            $reason = ! empty( $data['error_description'] ) ? $data['error_description'] : ( ! empty( $data['error'] ) ? $data['error'] : wp_remote_retrieve_response_code( $response ) );
            return new WP_Error(
                'filehub_gdrive_auth_failed',
                sprintf(
                    /* translators: %s: raw error reason returned by Google */
                    __( 'Google Drive jetonu alınamadı: %s', 'gnn-filehub' ),
                    $reason
                )
            );
        }

        set_transient( $transient_key, $data['access_token'], $data['expires_in'] - 60 );
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
        $cache_key = 'filehub_gdrive_user_folder_' . $user_id;
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
    public function upload_file( array $file, int $user_id ) {
        $access_token = $this->get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $user_folder_id = $this->get_or_create_user_folder( $user_id, $access_token );
        if ( is_wp_error( $user_folder_id ) ) {
            return $user_folder_id;
        }

        $filename  = FileHub_Attachment::sanitize_upload_filename( $file['name'] );
        $file_size = filesize( $file['tmp_name'] );
        $mime_type = $file['type'] ?: 'application/octet-stream';
        $metadata  = array(
            'name'    => $filename,
            'parents' => array( $user_folder_id ),
        );

        if ( $file_size <= self::SIMPLE_UPLOAD_MAX_BYTES ) {
            $result = $this->upload_simple( $file['tmp_name'], $metadata, $mime_type, $access_token );
        } else {
            $result = $this->upload_resumable( $file['tmp_name'], $metadata, $mime_type, $access_token, $file_size );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return array(
            'storage_driver' => 'gdrive',
            'storage_key'    => $result['id'],
            'file_name'      => $filename,
            'file_size'      => $file_size,
        );
    }

    /**
     * Simple One-Request Multipart Upload (Small Files)
     *
     * @return array|WP_Error Decoded Drive file resource (with 'id'), or WP_Error.
     */
    private function upload_simple( string $tmp_path, array $metadata, string $mime_type, string $access_token ) {
        $boundary     = '---------------FileHubBoundary' . md5( uniqid( '', true ) );
        $file_content = file_get_contents( $tmp_path );

        $body  = "--" . $boundary . "\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= wp_json_encode( $metadata ) . "\r\n";
        $body .= "--" . $boundary . "\r\n";
        $body .= "Content-Type: " . $mime_type . "\r\n\r\n";
        $body .= $file_content . "\r\n";
        $body .= "--" . $boundary . "--\r\n";

        $response = wp_remote_post( 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'multipart/related; boundary=' . $boundary,
            ),
            'body'    => $body,
            'timeout' => 60,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $result = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $result['id'] ) ) {
            $reason = ! empty( $result['error']['message'] ) ? $result['error']['message'] : wp_remote_retrieve_response_code( $response );
            return new WP_Error(
                'filehub_gdrive_upload_failed',
                sprintf(
                    /* translators: %s: raw error reason returned by Google */
                    __( 'Google Drive yükleme başarısız: %s', 'gnn-filehub' ),
                    $reason
                )
            );
        }

        return $result;
    }

    /**
     * Resumable Upload, Streamed From Disk in Bounded Chunks (Large Files)
     *
     * @return array|WP_Error Decoded Drive file resource (with 'id'), or WP_Error.
     */
    private function upload_resumable( string $tmp_path, array $metadata, string $mime_type, string $access_token, int $file_size ) {
        $init_response = wp_remote_post( 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable', array(
            'headers' => array(
                'Authorization'            => 'Bearer ' . $access_token,
                'Content-Type'             => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type'    => $mime_type,
                'X-Upload-Content-Length'  => (string) $file_size,
            ),
            'body'    => wp_json_encode( $metadata ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $init_response ) ) {
            return $init_response;
        }

        $session_url = wp_remote_retrieve_header( $init_response, 'location' );
        if ( empty( $session_url ) ) {
            $body   = json_decode( wp_remote_retrieve_body( $init_response ), true );
            $reason = ! empty( $body['error']['message'] ) ? $body['error']['message'] : wp_remote_retrieve_response_code( $init_response );
            return new WP_Error(
                'filehub_gdrive_resumable_init_failed',
                sprintf(
                    /* translators: %s: raw error reason returned by Google */
                    __( 'Google Drive yükleme oturumu başlatılamadı: %s', 'gnn-filehub' ),
                    $reason
                )
            );
        }

        $handle = fopen( $tmp_path, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'filehub_gdrive_read_failed', __( 'Yüklenecek dosya okunamadı.', 'gnn-filehub' ) );
        }

        $offset = 0;
        $final_result = null;

        while ( ! feof( $handle ) ) {
            $chunk = fread( $handle, self::RESUMABLE_CHUNK_BYTES );
            if ( false === $chunk ) {
                fclose( $handle );
                return new WP_Error( 'filehub_gdrive_read_failed', __( 'Yüklenecek dosya okunamadı.', 'gnn-filehub' ) );
            }

            $chunk_len = strlen( $chunk );
            if ( 0 === $chunk_len ) {
                break;
            }

            $range_end = $offset + $chunk_len - 1;

            $put_response = wp_remote_request( $session_url, array(
                'method'  => 'PUT',
                'headers' => array(
                    'Content-Length' => (string) $chunk_len,
                    'Content-Range'  => sprintf( 'bytes %d-%d/%d', $offset, $range_end, $file_size ),
                ),
                'body'    => $chunk,
                'timeout' => 120,
            ) );

            if ( is_wp_error( $put_response ) ) {
                fclose( $handle );
                return $put_response;
            }

            $status = wp_remote_retrieve_response_code( $put_response );

            if ( in_array( $status, array( 200, 201 ), true ) ) {
                $final_result = json_decode( wp_remote_retrieve_body( $put_response ), true );
                break;
            }

            if ( 308 !== $status ) {
                fclose( $handle );
                $body   = json_decode( wp_remote_retrieve_body( $put_response ), true );
                $reason = ! empty( $body['error']['message'] ) ? $body['error']['message'] : $status;
                return new WP_Error(
                    'filehub_gdrive_resumable_chunk_failed',
                    sprintf(
                        /* translators: %s: raw error reason returned by Google */
                        __( 'Google Drive yükleme parçası başarısız: %s', 'gnn-filehub' ),
                        $reason
                    )
                );
            }

            $offset += $chunk_len;
        }

        fclose( $handle );

        if ( empty( $final_result['id'] ) ) {
            return new WP_Error( 'filehub_gdrive_upload_failed', __( 'Google Drive yükleme tamamlanamadı.', 'gnn-filehub' ) );
        }

        return $final_result;
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

        return ! is_wp_error( $response );
    }

    /**
     * Stream File Download from Google Drive
     * Downloads to a temp file on disk first (WP_Http's 'stream' mode), then relays it to the
     * client — this keeps memory flat for large files instead of loading the whole body into a
     * PHP string via wp_remote_retrieve_body(), which has the same out-of-memory risk on
     * download that the old upload path had.
     */
    public function get_download_stream( string $file_identifier, string $mime_type = 'application/octet-stream', string $original_name = '' ) {
        $access_token = $this->get_access_token();
        if ( is_wp_error( $access_token ) ) {
            status_header( 500 );
            wp_die( esc_html__( 'Google Drive erişim hatası.', 'gnn-filehub' ) );
        }

        $tmp_path = wp_tempnam( 'filehub-gdrive-download' );
        $url      = 'https://www.googleapis.com/drive/v3/files/' . urlencode( $file_identifier ) . '?alt=media';
        $response = wp_remote_get( $url, array(
            'headers'  => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'timeout'  => 120,
            'stream'   => true,
            'filename' => $tmp_path,
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            @unlink( $tmp_path );
            status_header( 404 );
            wp_die( esc_html__( 'Google Drive dosyası indirilemedi.', 'gnn-filehub' ) );
        }

        $download_name = ! empty( $original_name ) ? $original_name : 'downloaded-file';

        if ( ob_get_level() ) {
            ob_end_clean();
        }

        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: ' . $mime_type );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $download_name ) . '"' );
        header( 'Expires: 0' );
        header( 'Cache-Control: must-revalidate' );
        header( 'Pragma: public' );
        header( 'Content-Length: ' . filesize( $tmp_path ) );

        readfile( $tmp_path );
        @unlink( $tmp_path );
        exit;
    }

    public function get_file_url( string $file_identifier ): string {
        return rest_url( 'filehub/v1/download/' . urlencode( $file_identifier ) );
    }
}
