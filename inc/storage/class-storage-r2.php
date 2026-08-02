<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once GNN_FILEHUB_PATH . 'inc/storage/class-storage-interface.php';

/**
 * Class FileHub_Storage_R2
 * Cloudflare R2 S3 SigV4 Storage driver using WordPress Core HTTP API (wp_remote_request).
 */
class FileHub_Storage_R2 implements FileHub_Storage_Interface {

    private $account_id;
    private $access_key;
    private $secret_key;
    private $bucket;
    private $region = 'auto';

    public function __construct( string $account_id = '', string $access_key = '', string $secret_key = '', string $bucket = '' ) {
        $this->account_id = $account_id ?: get_option( 'filehub_r2_account_id', '' );
        $this->access_key = $access_key ?: get_option( 'filehub_r2_access_key', '' );
        $this->secret_key = $secret_key ?: get_option( 'filehub_r2_secret_key', '' );
        $this->bucket     = $bucket     ?: get_option( 'filehub_r2_bucket', '' );
    }

    /**
     * Get Endpoint Hostname
     */
    private function get_host(): string {
        return sprintf( '%s.r2.cloudflarestorage.com', $this->account_id );
    }

    /**
     * Sign AWS SigV4 Headers
     */
    private function get_sigv4_headers( string $method, string $uri, string $payload_hash, array $extra_headers = array() ): array {
        $host       = $this->get_host();
        $service    = 's3';
        $region     = $this->region;
        $amz_date   = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );

        $canonical_headers = "host:" . $host . "\n" . "x-amz-content-sha256:" . $payload_hash . "\n" . "x-amz-date:" . $amz_date . "\n";
        $signed_headers    = "host;x-amz-content-sha256;x-amz-date";

        $canonical_request = implode( "\n", array(
            $method,
            $uri,
            '', // Canonical query string
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ) );

        $credential_scope = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';
        $string_to_sign   = implode( "\n", array(
            'AWS4-HMAC-SHA256',
            $amz_date,
            $credential_scope,
            hash( 'sha256', $canonical_request ),
        ) );

        $k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $this->secret_key, true );
        $k_region  = hash_hmac( 'sha256', $region, $k_date, true );
        $k_service = hash_hmac( 'sha256', $service, $k_region, true );
        $k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        $signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        return array_merge( $extra_headers, array(
            'Host'                 => $host,
            'x-amz-date'           => $amz_date,
            'x-amz-content-sha256' => $payload_hash,
            'Authorization'        => $authorization,
        ) );
    }

    /**
     * Upload File to Cloudflare R2
     */
    public function upload_file( array $file, int $user_id ) {
        if ( empty( $this->account_id ) || empty( $this->access_key ) || empty( $this->secret_key ) || empty( $this->bucket ) ) {
            return new WP_Error( 'filehub_r2_config_missing', __( 'Cloudflare R2 API bilgileri eksik.', 'gnn-filehub' ) );
        }

        $filename      = sanitize_file_name( $file['name'] );
        $r2_key        = 'uploads/' . $user_id . '/' . time() . '-' . $filename;
        $uri           = '/' . $this->bucket . '/' . $r2_key;
        $file_contents = file_get_contents( $file['tmp_name'] );
        $payload_hash  = hash( 'sha256', $file_contents );

        $headers = $this->get_sigv4_headers( 'PUT', $uri, $payload_hash, array(
            'Content-Type' => $file['type'] ?: 'application/octet-stream',
        ) );

        $url      = 'https://' . $this->get_host() . $uri;
        $response = wp_remote_request( $url, array(
            'method'  => 'PUT',
            'headers' => $headers,
            'body'    => $file_contents,
            'timeout' => 60,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'filehub_r2_upload_failed', __( 'Cloudflare R2 yükleme hatası HTTP ' . $code, 'gnn-filehub' ) );
        }

        return array(
            'storage_driver' => 'r2',
            'storage_key'    => $r2_key,
            'file_name'      => $filename,
            'file_size'      => filesize( $file['tmp_name'] ),
        );
    }

    /**
     * Delete File from Cloudflare R2
     */
    public function delete_file( string $file_identifier ) {
        $uri          = '/' . $this->bucket . '/' . ltrim( $file_identifier, '/' );
        $payload_hash = hash( 'sha256', '' );
        $headers      = $this->get_sigv4_headers( 'DELETE', $uri, $payload_hash );
        $url          = 'https://' . $this->get_host() . $uri;

        $response = wp_remote_request( $url, array(
            'method'  => 'DELETE',
            'headers' => $headers,
            'timeout' => 30,
        ) );

        return ! is_wp_error( $response );
    }

    /**
     * Download Stream from R2
     */
    public function get_download_stream( string $file_identifier, string $mime_type = 'application/octet-stream', string $original_name = '' ) {
        $uri          = '/' . $this->bucket . '/' . ltrim( $file_identifier, '/' );
        $payload_hash = hash( 'sha256', '' );
        $headers      = $this->get_sigv4_headers( 'GET', $uri, $payload_hash );
        $url          = 'https://' . $this->get_host() . $uri;

        $response = wp_remote_request( $url, array(
            'method'  => 'GET',
            'headers' => $headers,
            'timeout' => 60,
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            status_header( 404 );
            wp_die( esc_html__( 'Bulut dosyası indirilemedi.', 'gnn-filehub' ) );
        }

        $body          = wp_remote_retrieve_body( $response );
        $download_name = ! empty( $original_name ) ? $original_name : basename( $file_identifier );

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
