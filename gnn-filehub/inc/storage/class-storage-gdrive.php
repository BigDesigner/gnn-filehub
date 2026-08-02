<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once GNN_FILEHUB_PATH . 'inc/storage/class-storage-interface.php';

/**
 * Class FileHub_Storage_GDrive
 * Google Drive API v3 Storage driver using WordPress Core HTTP API.
 */
class FileHub_Storage_GDrive implements FileHub_Storage_Interface {

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
            return new WP_Error( 'filehub_gdrive_auth_failed', __( 'Google Drive jetonu alınamadı.', 'gnn-filehub' ) );
        }

        set_transient( $transient_key, $data['access_token'], $data['expires_in'] - 60 );
        return $data['access_token'];
    }

    /**
     * Upload File to Google Drive
     */
    public function upload_file( array $file, int $user_id ) {
        $access_token = $this->get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $filename      = sanitize_file_name( $file['name'] );
        $metadata      = array(
            'name' => $filename,
        );

        if ( ! empty( $this->folder_id ) ) {
            $metadata['parents'] = array( $this->folder_id );
        }

        $boundary     = '---------------FileHubBoundary' . md5( time() );
        $file_content = file_get_contents( $file['tmp_name'] );

        $body  = "--" . $boundary . "\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= wp_json_encode( $metadata ) . "\r\n";
        $body .= "--" . $boundary . "\r\n";
        $body .= "Content-Type: " . ( $file['type'] ?: 'application/octet-stream' ) . "\r\n\r\n";
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
            return new WP_Error( 'filehub_gdrive_upload_failed', __( 'Google Drive yükleme başarısız.', 'gnn-filehub' ) );
        }

        return array(
            'storage_driver' => 'gdrive',
            'storage_key'    => $result['id'],
            'file_name'      => $filename,
            'file_size'      => filesize( $file['tmp_name'] ),
        );
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
     */
    public function get_download_stream( string $file_identifier, string $mime_type = 'application/octet-stream', string $original_name = '' ) {
        $access_token = $this->get_access_token();
        if ( is_wp_error( $access_token ) ) {
            status_header( 500 );
            wp_die( esc_html__( 'Google Drive erişim hatası.', 'gnn-filehub' ) );
        }

        $url      = 'https://www.googleapis.com/drive/v3/files/' . urlencode( $file_identifier ) . '?alt=media';
        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'timeout' => 60,
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            status_header( 404 );
            wp_die( esc_html__( 'Google Drive dosyası indirilemedi.', 'gnn-filehub' ) );
        }

        $body          = wp_remote_retrieve_body( $response );
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
        header( 'Content-Length: ' . strlen( $body ) );

        echo $body;
        exit;
    }

    public function get_file_url( string $file_identifier ): string {
        return rest_url( 'filehub/v1/download/' . urlencode( $file_identifier ) );
    }
}
