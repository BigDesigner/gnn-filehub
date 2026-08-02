<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once GNN_FILEHUB_PATH . 'inc/storage/class-storage-interface.php';

/**
 * Class FileHub_Storage_Local
 * Local Protected Storage driver with .htaccess isolation and REST proxy stream delivery.
 */
class FileHub_Storage_Local implements FileHub_Storage_Interface {

    /**
     * Get Base Protected Storage Directory Path
     * @return string
     */
    private function get_protected_base_dir(): string {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/filehub-protected';
    }

    /**
     * Upload File to Local Protected Path
     *
     * @param array $file
     * @param int   $user_id
     * @return array|WP_Error
     */
    public function upload_file( array $file, int $user_id ) {
        $tmp_name = ! empty( $file['tmp_name'] ) ? $file['tmp_name'] : '';

        // Single-shot uploads arrive as a genuine PHP upload (is_uploaded_file() is true).
        // Chunked uploads are merged server-side beforehand into a temp file we created
        // ourselves in sys_get_temp_dir() (see FileHub_REST_API::merge_uploaded_chunks()),
        // so it will never pass is_uploaded_file() — accept it instead by confirming it
        // really does live inside the system temp directory, not an arbitrary path.
        $is_real_upload   = $tmp_name && is_uploaded_file( $tmp_name );
        $real_tmp_dir     = realpath( sys_get_temp_dir() );
        $real_tmp_name    = $tmp_name ? realpath( $tmp_name ) : false;
        $is_assembled_tmp = ! $is_real_upload && $real_tmp_name && $real_tmp_dir && 0 === strpos( $real_tmp_name, $real_tmp_dir );

        if ( ! $is_real_upload && ! $is_assembled_tmp ) {
            return new WP_Error( 'filehub_invalid_upload', __( 'Geçersiz dosya yükleme isteği.', 'gnn-filehub' ) );
        }

        $base_dir = $this->get_protected_base_dir();
        $user_dir = $base_dir . '/' . $user_id;

        if ( ! file_exists( $user_dir ) ) {
            wp_mkdir_p( $user_dir );
        }

        $filename      = sanitize_file_name( $file['name'] );
        $file_info     = pathinfo( $filename );
        $name_part     = $file_info['filename'];
        $extension     = isset( $file_info['extension'] ) ? '.' . strtolower( $file_info['extension'] ) : '';
        
        $counter       = 1;
        $unique_name   = $filename;
        $target_path   = $user_dir . '/' . $unique_name;

        // Collision avoidance
        while ( file_exists( $target_path ) ) {
            $unique_name = $name_part . '-' . $counter . $extension;
            $target_path = $user_dir . '/' . $unique_name;
            $counter++;
        }

        if ( $is_real_upload ) {
            $moved = move_uploaded_file( $tmp_name, $target_path );
        } else {
            $moved = @rename( $tmp_name, $target_path );
            if ( ! $moved ) {
                $moved = @copy( $tmp_name, $target_path );
                if ( $moved ) {
                    @unlink( $tmp_name );
                }
            }
        }

        if ( ! $moved ) {
            return new WP_Error( 'filehub_move_failed', __( 'Dosya korumalı dizine taşınamadı.', 'gnn-filehub' ) );
        }

        $relative_key = $user_id . '/' . $unique_name;

        return array(
            'storage_driver' => 'local',
            'storage_key'    => $relative_key,
            'file_name'      => $unique_name,
            'file_size'      => filesize( $target_path ),
            'file_path'      => $target_path,
        );
    }

    /**
     * Delete Local Protected File
     *
     * @param string $file_identifier Relative storage key e.g. "user_id/filename.ext"
     * @return bool|WP_Error
     */
    public function delete_file( string $file_identifier ) {
        $clean_key   = ltrim( sanitize_text_field( $file_identifier ), '/\\' );
        $target_path = $this->get_protected_base_dir() . '/' . $clean_key;

        // Prevent directory traversal
        $real_target = realpath( $target_path );
        $real_base   = realpath( $this->get_protected_base_dir() );

        if ( $real_target && $real_base && strpos( $real_target, $real_base ) === 0 && file_exists( $real_target ) ) {
            if ( @unlink( $real_target ) ) {
                return true;
            }
            return new WP_Error( 'filehub_delete_failed', __( 'Dosya sistemden silinemedi.', 'gnn-filehub' ) );
        }

        return true; // Already gone or non-existent
    }

    /**
     * Stream File Download
     *
     * @param string $file_identifier
     * @param string $mime_type
     * @param string $original_name
     * @return void
     */
    public function get_download_stream( string $file_identifier, string $mime_type = 'application/octet-stream', string $original_name = '' ) {
        $clean_key   = ltrim( sanitize_text_field( $file_identifier ), '/\\' );
        $target_path = $this->get_protected_base_dir() . '/' . $clean_key;

        $real_target = realpath( $target_path );
        $real_base   = realpath( $this->get_protected_base_dir() );

        if ( ! $real_target || ! $real_base || strpos( $real_target, $real_base ) !== 0 || ! file_exists( $real_target ) ) {
            status_header( 404 );
            wp_die( esc_html__( 'İstenen dosya bulunamadı.', 'gnn-filehub' ) );
        }

        $download_name = ! empty( $original_name ) ? $original_name : basename( $real_target );

        if ( ob_get_level() ) {
            ob_end_clean();
        }

        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: ' . $mime_type );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $download_name ) . '"' );
        header( 'Expires: 0' );
        header( 'Cache-Control: must-revalidate' );
        header( 'Pragma: public' );
        header( 'Content-Length: ' . filesize( $real_target ) );

        readfile( $real_target );
        exit;
    }

    /**
     * Get REST Proxy Download URL
     *
     * @param string $file_identifier
     * @return string
     */
    public function get_file_url( string $file_identifier ): string {
        return rest_url( 'filehub/v1/download/' . urlencode( $file_identifier ) );
    }
}
