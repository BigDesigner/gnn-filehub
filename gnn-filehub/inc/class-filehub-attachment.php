<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FileHub_Attachment
 * WordPress Native Attachment CPT & Post Meta management layer.
 */
class FileHub_Attachment {

    /**
     * Sanitize an Uploaded File Name for Safe, Predictable Storage
     * Transliterates Turkish characters to their ASCII equivalents, replaces whitespace with
     * underscores, then runs the result through WordPress's own sanitize_file_name() for
     * everything else (stripping special characters, etc.).
     *
     * @param string $filename Original, user-supplied file name.
     * @return string
     */
    public static function sanitize_upload_filename( string $filename ): string {
        $turkish_map = array(
            'ç' => 'c', 'Ç' => 'C',
            'ğ' => 'g', 'Ğ' => 'G',
            'ı' => 'i', 'İ' => 'I',
            'ö' => 'o', 'Ö' => 'O',
            'ş' => 's', 'Ş' => 'S',
            'ü' => 'u', 'Ü' => 'U',
        );

        $filename = strtr( $filename, $turkish_map );
        $filename = preg_replace( '/\s+/', '_', trim( $filename ) );

        return sanitize_file_name( $filename );
    }

    /**
     * Register Uploaded File in WordPress Attachment CPT
     *
     * @param array  $storage_result Result array from storage engine driver.
     * @param int    $user_id        Uploading user ID.
     * @param string $mime_type      MIME content type.
     * @return int|WP_Error Attachment Post ID or WP_Error.
     */
    public static function create_attachment( array $storage_result, int $user_id, string $mime_type = '' ) {
        $file_name = sanitize_file_name( $storage_result['file_name'] );

        $attachment_data = array(
            'post_mime_type' => $mime_type ?: 'application/octet-stream',
            'post_title'     => pathinfo( $file_name, PATHINFO_FILENAME ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => $user_id,
        );

        $attachment_id = wp_insert_attachment( $attachment_data );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return new WP_Error( 'filehub_db_error', __( 'Attachment veritabanına kaydedilemedi.', 'gnn-filehub' ) );
        }

        update_post_meta( $attachment_id, '_filehub_storage_driver', $storage_result['storage_driver'] );
        update_post_meta( $attachment_id, '_filehub_storage_key', $storage_result['storage_key'] );
        update_post_meta( $attachment_id, '_filehub_user_id', $user_id );
        update_post_meta( $attachment_id, '_filehub_file_size', $storage_result['file_size'] );
        update_post_meta( $attachment_id, '_filehub_download_count', 0 );
        // post_title strips the extension (PATHINFO_FILENAME above), so the real filename
        // with its extension is kept separately for downloads/listing.
        update_post_meta( $attachment_id, '_filehub_file_name', $file_name );

        return $attachment_id;
    }

    /**
     * Get Effective Storage Quota (in Bytes) for a User
     * Supports Per-User Custom Quota User Meta Override (_filehub_custom_quota_mb)
     *
     * @param int $user_id
     * @return int Quota limit in bytes.
     */
    public static function get_user_quota( int $user_id ): int {
        $custom_quota_mb = (int) get_user_meta( $user_id, '_filehub_custom_quota_mb', true );

        if ( $custom_quota_mb > 0 ) {
            return $custom_quota_mb * 1024 * 1024;
        }

        // Fallback to default 500 MB global quota
        $default_quota_mb = (int) get_option( 'filehub_default_quota_mb', 500 );
        return ( $default_quota_mb > 0 ? $default_quota_mb : 500 ) * 1024 * 1024;
    }

    /**
     * Get the Configured Maximum Per-File Upload Size (in Bytes)
     *
     * @return int Byte limit, or 0 for unlimited.
     */
    public static function get_max_upload_bytes(): int {
        $max_upload_mb = (int) get_option( 'filehub_max_upload_mb', 0 );
        return $max_upload_mb > 0 ? $max_upload_mb * 1024 * 1024 : 0;
    }

    /**
     * Get Per-User File Usage Statistics
     *
     * @param int $user_id
     * @return array
     */
    public static function get_user_stats( int $user_id ): array {
        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'author'         => $user_id,
            'meta_key'       => '_filehub_storage_driver',
            'fields'         => 'ids',
        );

        $attachments = get_posts( $query_args );
        $total_bytes = 0;
        $total_files = count( $attachments );

        foreach ( $attachments as $att_id ) {
            $size = (int) get_post_meta( $att_id, '_filehub_file_size', true );
            $total_bytes += $size;
        }

        $quota_bytes     = self::get_user_quota( $user_id );
        $custom_quota_mb = (int) get_user_meta( $user_id, '_filehub_custom_quota_mb', true );

        return array(
            'file_count'      => $total_files,
            'total_bytes'     => $total_bytes,
            'quota_bytes'     => $quota_bytes,
            'custom_quota_mb' => $custom_quota_mb,
            'quota_formatted' => size_format( $quota_bytes ),
            'used_formatted'  => size_format( $total_bytes ),
            'percentage'      => $quota_bytes > 0 ? min( 100, round( ( $total_bytes / $quota_bytes ) * 100, 1 ) ) : 0,
        );
    }

    /**
     * Get Total System Storage Statistics across Drivers
     *
     * @return array
     */
    public static function get_system_stats(): array {
        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'meta_key'       => '_filehub_storage_driver',
        );

        $attachments  = get_posts( $query_args );
        $total_files  = count( $attachments );
        $total_bytes  = 0;
        $driver_bytes = array(
            'local'  => 0,
            'r2'     => 0,
            'gdrive' => 0,
        );
        $total_downloads = 0;

        foreach ( $attachments as $post ) {
            $driver    = get_post_meta( $post->ID, '_filehub_storage_driver', true ) ?: 'local';
            $size      = (int) get_post_meta( $post->ID, '_filehub_file_size', true );
            $downloads = (int) get_post_meta( $post->ID, '_filehub_download_count', true );

            $total_bytes += $size;
            $total_downloads += $downloads;

            if ( isset( $driver_bytes[ $driver ] ) ) {
                $driver_bytes[ $driver ] += $size;
            }
        }

        return array(
            'total_files'     => $total_files,
            'total_bytes'     => $total_bytes,
            'driver_bytes'    => $driver_bytes,
            'total_downloads' => $total_downloads,
        );
    }

    /**
     * Increment Attachment Download Count
     *
     * @param int $attachment_id
     */
    public static function increment_download_count( int $attachment_id ) {
        $current = (int) get_post_meta( $attachment_id, '_filehub_download_count', true );
        update_post_meta( $attachment_id, '_filehub_download_count', $current + 1 );
    }
}
