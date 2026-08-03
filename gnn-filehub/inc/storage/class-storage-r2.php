<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once GNN_FILEHUB_PATH . 'inc/storage/class-storage-interface.php';
require_once GNN_FILEHUB_PATH . 'inc/class-filehub-attachment.php';

/**
 * Class FileHub_Storage_R2
 * Cloudflare R2 S3 SigV4 Storage driver. Uploads/downloads stream directly to/from disk
 * (raw cURL, falling back to WP_Http) instead of buffering whole files in PHP memory, so
 * memory usage stays flat regardless of file size.
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
     * Build a Presigned PUT URL (Query-String SigV4) for Direct Browser → R2 Upload
     * Unlike the header-based signing used elsewhere in this class, a presigned URL embeds the
     * signature in the query string so the browser can PUT the file straight to R2 with a plain
     * fetch() — the file never passes through our own PHP server at all, which is how large
     * files stay fast and don't hit PHP's memory/execution-time limits.
     */
    private function get_presigned_put_url( string $uri, int $expires_seconds = 3600 ): string {
        $host              = $this->get_host();
        $service           = 's3';
        $region            = $this->region;
        $amz_date          = gmdate( 'Ymd\THis\Z' );
        $date_stamp        = gmdate( 'Ymd' );
        $credential_scope  = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';

        $query_params = array(
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $this->access_key . '/' . $credential_scope,
            'X-Amz-Date'          => $amz_date,
            'X-Amz-Expires'       => (string) $expires_seconds,
            'X-Amz-SignedHeaders' => 'host',
        );
        ksort( $query_params );
        $canonical_query_string = http_build_query( $query_params, '', '&', PHP_QUERY_RFC3986 );

        $canonical_headers = 'host:' . $host . "\n";
        $signed_headers    = 'host';
        // Browser-uploaded presigned PUTs can't be content-hashed ahead of time.
        $payload_hash      = 'UNSIGNED-PAYLOAD';

        $canonical_request = implode( "\n", array(
            'PUT',
            $uri,
            $canonical_query_string,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ) );

        $string_to_sign = implode( "\n", array(
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

        return 'https://' . $host . $uri . '?' . $canonical_query_string . '&X-Amz-Signature=' . $signature;
    }

    /**
     * Get a Presigned Upload URL for a New Object (Direct Browser Upload)
     *
     * @return array{upload_url:string,key:string}|WP_Error
     */
    public function get_presigned_upload_url( string $filename, int $user_id, int $expires_seconds = 3600 ) {
        if ( empty( $this->account_id ) || empty( $this->access_key ) || empty( $this->secret_key ) || empty( $this->bucket ) ) {
            return new WP_Error( 'filehub_r2_config_missing', __( 'Cloudflare R2 API bilgileri eksik.', 'gnn-filehub' ) );
        }

        $r2_key = 'uploads/' . $user_id . '/' . time() . '-' . $filename;
        $uri    = '/' . $this->bucket . '/' . $r2_key;

        return array(
            'upload_url' => $this->get_presigned_put_url( $uri, $expires_seconds ),
            'key'        => $r2_key,
        );
    }

    /**
     * Confirm an Object Was Actually Uploaded & Read Back its Real Size
     * Called after a direct browser upload finishes, before we trust the client's word for it —
     * quota accounting and the attachment record use this server-verified size, not whatever
     * the browser claimed before the upload started.
     *
     * @return array{file_size:int,content_type:string}|WP_Error
     */
    public function verify_uploaded_object( string $key ) {
        if ( empty( $this->account_id ) || empty( $this->access_key ) || empty( $this->secret_key ) || empty( $this->bucket ) ) {
            return new WP_Error( 'filehub_r2_config_missing', __( 'Cloudflare R2 API bilgileri eksik.', 'gnn-filehub' ) );
        }

        $uri          = '/' . $this->bucket . '/' . ltrim( $key, '/' );
        $payload_hash = hash( 'sha256', '' );
        $headers      = $this->get_sigv4_headers( 'HEAD', $uri, $payload_hash );
        $url          = 'https://' . $this->get_host() . $uri;

        $response = wp_remote_request( $url, array(
            'method'  => 'HEAD',
            'headers' => $headers,
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return new WP_Error( 'filehub_r2_object_missing', __( 'Yüklenen dosya R2\'de bulunamadı. CORS ayarlarınızı kontrol edin.', 'gnn-filehub' ) );
        }

        return array(
            'file_size'    => (int) wp_remote_retrieve_header( $response, 'content-length' ),
            'content_type' => wp_remote_retrieve_header( $response, 'content-type' ) ?: 'application/octet-stream',
        );
    }

    /**
     * Upload File to Cloudflare R2
     */
    public function upload_file( array $file, int $user_id ) {
        if ( empty( $this->account_id ) || empty( $this->access_key ) || empty( $this->secret_key ) || empty( $this->bucket ) ) {
            return new WP_Error( 'filehub_r2_config_missing', __( 'Cloudflare R2 API bilgileri eksik.', 'gnn-filehub' ) );
        }

        $filename     = FileHub_Attachment::sanitize_upload_filename( $file['name'] );
        $r2_key       = 'uploads/' . $user_id . '/' . time() . '-' . $filename;
        $uri          = '/' . $this->bucket . '/' . $r2_key;
        $file_size    = filesize( $file['tmp_name'] );
        // hash_file() streams the file internally instead of loading it into a PHP string.
        $payload_hash = hash_file( 'sha256', $file['tmp_name'] );

        $headers = $this->get_sigv4_headers( 'PUT', $uri, $payload_hash, array(
            'Content-Type'   => $file['type'] ?: 'application/octet-stream',
            'Content-Length' => (string) $file_size,
        ) );

        $url    = 'https://' . $this->get_host() . $uri;
        $result = $this->send_streamed_put( $url, $headers, $file['tmp_name'] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( $result['status'] < 200 || $result['status'] >= 300 ) {
            return new WP_Error(
                'filehub_r2_upload_failed',
                sprintf(
                    /* translators: %d: HTTP status code returned by R2 */
                    __( 'Cloudflare R2 yükleme hatası: HTTP %d', 'gnn-filehub' ),
                    $result['status']
                )
            );
        }

        return array(
            'storage_driver' => 'r2',
            'storage_key'    => $r2_key,
            'file_name'      => $filename,
            'file_size'      => $file_size,
        );
    }

    /**
     * Stream a File to a URL via PUT Without Buffering it in PHP Memory
     * Uses raw cURL (CURLOPT_INFILE) so curl reads straight from disk in its own internal
     * chunks. Falls back to loading the file into memory only if the curl extension is
     * unavailable, which is rare on any real WordPress host.
     *
     * @return array{status:int,body:string}|WP_Error
     */
    private function send_streamed_put( string $url, array $headers, string $file_path ) {
        if ( ! function_exists( 'curl_init' ) ) {
            $response = wp_remote_request( $url, array(
                'method'  => 'PUT',
                'headers' => $headers,
                'body'    => file_get_contents( $file_path ),
                'timeout' => 300,
            ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            return array(
                'status' => wp_remote_retrieve_response_code( $response ),
                'body'   => wp_remote_retrieve_body( $response ),
            );
        }

        $fp = fopen( $file_path, 'rb' );
        if ( ! $fp ) {
            return new WP_Error( 'filehub_r2_read_failed', __( 'Yüklenecek dosya okunamadı.', 'gnn-filehub' ) );
        }

        $header_lines = array();
        foreach ( $headers as $key => $value ) {
            $header_lines[] = $key . ': ' . $value;
        }

        $ch = curl_init( $url );
        curl_setopt_array( $ch, array(
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fp,
            CURLOPT_INFILESIZE     => filesize( $file_path ),
            CURLOPT_HTTPHEADER     => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 0, // large files can legitimately take a while
            CURLOPT_SSL_VERIFYPEER => true,
        ) );

        $body       = curl_exec( $ch );
        $status     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_error = curl_error( $ch );
        curl_close( $ch );
        fclose( $fp );

        if ( false === $body ) {
            return new WP_Error( 'filehub_r2_curl_failed', $curl_error ?: __( 'Bağlantı hatası.', 'gnn-filehub' ) );
        }

        return array( 'status' => $status, 'body' => $body );
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
     * Downloads to a temp file first instead of loading the whole body into a PHP string, so
     * memory usage stays flat for large files.
     */
    public function get_download_stream( string $file_identifier, string $mime_type = 'application/octet-stream', string $original_name = '' ) {
        $uri          = '/' . $this->bucket . '/' . ltrim( $file_identifier, '/' );
        $payload_hash = hash( 'sha256', '' );
        $headers      = $this->get_sigv4_headers( 'GET', $uri, $payload_hash );
        $url          = 'https://' . $this->get_host() . $uri;

        $tmp_path = wp_tempnam( 'filehub-r2-download' );

        $response = wp_remote_request( $url, array(
            'method'   => 'GET',
            'headers'  => $headers,
            'timeout'  => 120,
            'stream'   => true,
            'filename' => $tmp_path,
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            @unlink( $tmp_path );
            status_header( 404 );
            wp_die( esc_html__( 'Bulut dosyası indirilemedi.', 'gnn-filehub' ) );
        }

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
        header( 'Content-Length: ' . filesize( $tmp_path ) );

        readfile( $tmp_path );
        @unlink( $tmp_path );
        exit;
    }

    public function get_file_url( string $file_identifier ): string {
        return rest_url( 'filehub/v1/download/' . urlencode( $file_identifier ) );
    }
}
