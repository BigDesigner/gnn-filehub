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
     * Locale-Independent ASCII Uppercase
     * PHP's strtoupper() (and, in the browser, CSS text-transform: uppercase applied to an
     * element under a Turkish lang/locale) respect Turkish casing rules, where lowercase "i"
     * uppercases to the dotted "İ" — correct for real Turkish words, but wrong for brand/driver
     * names like "gdrive" or "r2" that must always render as plain ASCII ("GDRIVE"), not
     * "GDRİVE". Used anywhere a fixed, non-localized identifier needs uppercasing.
     *
     * @param string $str
     * @return string
     */
    public static function ascii_upper( string $str ): string {
        return strtr( $str, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' );
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

        self::maybe_notify_admin_of_upload( $user_id, $file_name, (int) $storage_result['file_size'], $storage_result['storage_driver'] );

        return $attachment_id;
    }

    /**
     * Email the Configured Notification Address on Every Successful Upload
     * Called from the single choke point all three upload paths (single, chunked, R2 direct)
     * funnel through, so it only needs to exist once.
     *
     * @param int    $user_id   Uploading user ID (0 for a guest upload).
     * @param string $file_name Real filename, with extension.
     * @param int    $file_size Size in bytes.
     * @param string $driver    Storage driver key ('local', 'r2', 'gdrive').
     */
    private static function maybe_notify_admin_of_upload( int $user_id, string $file_name, int $file_size, string $driver ): void {
        if ( '1' !== get_option( 'filehub_upload_notify', '1' ) ) {
            return;
        }

        $to = get_option( 'filehub_notify_email' );
        if ( empty( $to ) ) {
            $to = get_option( 'admin_email' );
        }
        if ( empty( $to ) || ! is_email( $to ) ) {
            return;
        }

        $user         = $user_id ? get_userdata( $user_id ) : false;
        $user_display = $user ? $user->display_name : __( 'Misafir', 'gnn-filehub' );
        $user_login   = $user ? $user->user_login : '—';

        // wp_date() formats the current time in the site's own configured timezone (Ayarlar →
        // Genel → Saat Dilimi) instead of a hardcoded offset, so it stays correct if that
        // setting is ever changed.
        $uploaded_at = wp_date( 'd.m.Y H:i (P)' );

        $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

        $subject = sprintf(
            /* translators: 1: site name, 2: uploaded file name */
            __( '[%1$s] Yeni Dosya Yüklendi: %2$s', 'gnn-filehub' ),
            $site_name,
            $file_name
        );

        $body = self::get_upload_notification_email_html( array(
            'site_name'    => $site_name,
            'file_name'    => $file_name,
            'file_size'    => size_format( $file_size ),
            'driver'       => self::ascii_upper( $driver ),
            'user_display' => $user_display,
            'user_login'   => $user_login,
            'user_id'      => $user_id,
            'uploaded_at'  => $uploaded_at,
            'admin_url'    => admin_url( 'admin.php?page=filehub-files' ),
        ) );

        wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    /**
     * Build the HTML Body for the Upload Notification Email
     * Uses inline styles throughout (not the plugin's regular CSS) since email clients don't
     * load external/enqueued stylesheets.
     *
     * @param array $data
     * @return string
     */
    private static function get_upload_notification_email_html( array $data ): string {
        ob_start();
        ?>
<!doctype html>
<html>
<body style="margin:0; padding:0; background:#f1f1f1; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f1f1; padding: 32px 16px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 480px; background:#ffffff; border-radius: 10px; overflow: hidden; border: 1px solid #e2e2e2;">
<tr>
<td style="background:#2271b1; padding: 20px 28px;">
<span style="color:#ffffff; font-size: 18px; font-weight: 600;"><?php echo esc_html( $data['site_name'] ); ?></span>
</td>
</tr>
<tr>
<td style="padding: 28px;">
<p style="margin:0 0 4px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color:#2271b1; font-weight:600;"><?php esc_html_e( 'Yeni Dosya Yüklendi', 'gnn-filehub' ); ?></p>
<h1 style="margin:0 0 20px; font-size: 20px; color:#1d2327; word-break: break-word;"><?php echo esc_html( $data['file_name'] ); ?></h1>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; color:#1d2327;">
<tr>
<td style="padding: 8px 0; border-bottom: 1px solid #f0f0f1; color:#646970; width: 40%;"><?php esc_html_e( 'Kullanıcı', 'gnn-filehub' ); ?></td>
<td style="padding: 8px 0; border-bottom: 1px solid #f0f0f1; font-weight: 600;"><?php echo esc_html( $data['user_display'] ); ?> (<?php echo esc_html( $data['user_login'] ); ?>)</td>
</tr>
<tr>
<td style="padding: 8px 0; border-bottom: 1px solid #f0f0f1; color:#646970;"><?php esc_html_e( 'Kullanıcı ID', 'gnn-filehub' ); ?></td>
<td style="padding: 8px 0; border-bottom: 1px solid #f0f0f1; font-weight: 600;"><?php echo esc_html( $data['user_id'] ); ?></td>
</tr>
<tr>
<td style="padding: 8px 0; border-bottom: 1px solid #f0f0f1; color:#646970;"><?php esc_html_e( 'Dosya Boyutu', 'gnn-filehub' ); ?></td>
<td style="padding: 8px 0; border-bottom: 1px solid #f0f0f1; font-weight: 600;"><?php echo esc_html( $data['file_size'] ); ?></td>
</tr>
<tr>
<td style="padding: 8px 0; border-bottom: 1px solid #f0f0f1; color:#646970;"><?php esc_html_e( 'Depolama Sürücüsü', 'gnn-filehub' ); ?></td>
<td style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
<span style="display:inline-block; padding:2px 8px; border-radius:4px; background:#f0f6fc; color:#2271b1; font-size:12px; font-weight:600; letter-spacing:.02em;"><?php echo esc_html( $data['driver'] ); ?></span>
</td>
</tr>
<tr>
<td style="padding: 8px 0; color:#646970;"><?php esc_html_e( 'Yükleme Zamanı', 'gnn-filehub' ); ?></td>
<td style="padding: 8px 0; font-weight: 600;"><?php echo esc_html( $data['uploaded_at'] ); ?></td>
</tr>
</table>
<a href="<?php echo esc_url( $data['admin_url'] ); ?>" style="display:inline-block; margin-top: 26px; padding: 10px 20px; background:#2271b1; color:#ffffff; text-decoration:none; border-radius:6px; font-size:14px; font-weight:600;"><?php esc_html_e( 'Tüm Dosyaları Görüntüle', 'gnn-filehub' ); ?></a>
</td>
</tr>
<tr>
<td style="padding: 16px 28px; background:#fafafa; border-top:1px solid #f0f0f1;">
<p style="margin:0; font-size:12px; color:#8c8f94;"><?php echo esc_html( sprintf( __( 'Bu e-posta %s tarafından GNN Filehub eklentisi üzerinden otomatik olarak gönderilmiştir.', 'gnn-filehub' ), $data['site_name'] ) ); ?></p>
</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
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
