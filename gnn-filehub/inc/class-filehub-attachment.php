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
            'post_status'    => 'private',
            'post_author'    => $user_id,
        );

        global $wpdb;
        $existing=empty($storage_result['upload_id'])?0:(int)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_filehub_upload_id' AND meta_value=%s LIMIT 1",$storage_result['upload_id']));
        if($existing && (get_post_type($existing)!=='attachment' || (int)get_post_field('post_author',$existing)!==$user_id))return new WP_Error('filehub_identity_error',__('Yükleme kimliği çakıştı.','gnn-filehub'));
        if (!$existing && '1' === get_option('filehub_auto_rename','1')) {
            $candidate=$file_name; $counter=1;
            while($wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON p.ID=m.post_id WHERE p.post_author=%d AND m.meta_key='_filehub_file_name' AND m.meta_value=%s LIMIT 1",$user_id,$candidate))) {
                $extension=pathinfo($file_name,PATHINFO_EXTENSION);
                $candidate=pathinfo($file_name,PATHINFO_FILENAME).'_'.($counter++).'.'.$extension;
            }
            $file_name=$candidate;
        }
        $attachment_data['post_title']=$file_name;
        $metadata=array('_filehub_storage_driver'=>$storage_result['storage_driver'],'_filehub_storage_key'=>$storage_result['storage_key'],'_filehub_user_id'=>$user_id,'_filehub_file_size'=>$storage_result['file_size'],'_filehub_download_count'=>0,'_filehub_file_name'=>$file_name,'_filehub_migrated_v2'=>1);
        if(!empty($storage_result['upload_id']))$metadata['_filehub_upload_id']=$storage_result['upload_id'];
        $attachment_data['meta_input']=$metadata;
        $wpdb->query('START TRANSACTION');
        if($existing) {
            $attachment_data['ID']=$existing;
            $attachment_id=wp_update_post($attachment_data,true);
        } else $attachment_id=wp_insert_attachment($attachment_data,false,0,true);
        if(!is_wp_error($attachment_id) && $attachment_id) {
            foreach($metadata as $key=>$value) {
                if((string)get_post_meta($attachment_id,$key,true)!==(string)$value) { $wpdb->query('ROLLBACK'); clean_post_cache($attachment_id); return new WP_Error('filehub_db_error',__('Dosya metaverisi kaydedilemedi.','gnn-filehub')); }
            }
            update_post_meta($attachment_id,'_filehub_complete',1);
            if((int)get_post_meta($attachment_id,'_filehub_complete',true)!==1) { $wpdb->query('ROLLBACK'); clean_post_cache($attachment_id); return new WP_Error('filehub_db_error',__('Dosya tamamlanma kaydı yazılamadı.','gnn-filehub')); }
            $wpdb->query('COMMIT');
        } else {
            $wpdb->query('ROLLBACK');
            return new WP_Error('filehub_db_error',__('Attachment veritabanına kaydedilemedi.','gnn-filehub'));
        }
        if(!$existing)self::maybe_notify_admin_of_upload($user_id,$file_name,(int)$storage_result['file_size'],$storage_result['storage_driver']);

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
            return min($custom_quota_mb, (int)floor(PHP_INT_MAX/MB_IN_BYTES)) * MB_IN_BYTES;
        }

        // Fallback to default 500 MB global quota
        $default_quota_mb = (int) get_option( 'filehub_default_quota_mb', 500 );
        return min(max(0, $default_quota_mb), (int)floor(PHP_INT_MAX/MB_IN_BYTES)) * MB_IN_BYTES;
    }

    /**
     * Get the Configured Maximum Per-File Upload Size (in Bytes)
     *
     * @return int Byte limit, or 0 for unlimited.
     */
    public static function get_max_upload_bytes(): int {
        $max_upload_mb = (int) get_option( 'filehub_max_upload_mb', 0 );
        return $max_upload_mb > 0 ? min($max_upload_mb, (int)floor(PHP_INT_MAX/MB_IN_BYTES)) * MB_IN_BYTES : 0;
    }

    /**
     * Get Per-User File Usage Statistics
     *
     * @param int $user_id
     * @return array
     */
    public static function get_user_stats(int $user_id): array {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT COUNT(DISTINCT p.ID) AS files, COALESCE(SUM(f.meta_value+0),0) AS bytes FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} d ON d.post_id=p.ID AND d.meta_key='_filehub_storage_driver' JOIN {$wpdb->postmeta} f ON f.post_id=p.ID AND f.meta_key='_filehub_file_size' WHERE p.post_type='attachment' AND p.post_status IN ('private','inherit','trash') AND p.post_author=%d",$user_id));
        $bytes=(int)$row->bytes; $quota=self::get_user_quota($user_id);
        return array('file_count'=>(int)$row->files,'total_bytes'=>$bytes,'quota_bytes'=>$quota,'custom_quota_mb'=>(int)get_user_meta($user_id,'_filehub_custom_quota_mb',true),'quota_formatted'=>$quota?size_format($quota):__('Sınırsız','gnn-filehub'),'used_formatted'=>size_format($bytes),'percentage'=>$quota>0?min(100,round($bytes/$quota*100,1)):0);
    }
    public static function get_system_stats(): array {
        global $wpdb;
        $rows=$wpdb->get_results("SELECT d.meta_value AS driver, COUNT(DISTINCT p.ID) AS files, COALESCE(SUM(f.meta_value+0),0) AS bytes, COALESCE(SUM(n.meta_value+0),0) AS downloads FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} d ON d.post_id=p.ID AND d.meta_key='_filehub_storage_driver' JOIN {$wpdb->postmeta} f ON f.post_id=p.ID AND f.meta_key='_filehub_file_size' LEFT JOIN {$wpdb->postmeta} n ON n.post_id=p.ID AND n.meta_key='_filehub_download_count' WHERE p.post_type='attachment' AND p.post_status IN ('private','inherit','trash') GROUP BY d.meta_value");
        $stats=array('total_files'=>0,'total_bytes'=>0,'total_downloads'=>0,'driver_bytes'=>array('local'=>0,'r2'=>0,'gdrive'=>0));
        foreach($rows as $row) {
            $stats['total_files']+=(int)$row->files; $stats['total_bytes']+=(int)$row->bytes; $stats['total_downloads']+=(int)$row->downloads;
            if(isset($stats['driver_bytes'][$row->driver]))$stats['driver_bytes'][$row->driver]=(int)$row->bytes;
        }
        return $stats;
    }
    public static function increment_download_count(int $attachment_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=meta_value+1 WHERE post_id=%d AND meta_key='_filehub_download_count'",$attachment_id));
        wp_cache_delete($attachment_id,'post_meta');
    }
}
