<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface FileHub_Storage_Interface
 * Storage engine driver contract.
 */
interface FileHub_Storage_Interface {

    /**
     * Upload file to target storage
     *
     * @param array $file     Standard $_FILES item array.
     * @param int   $user_id  Target user ID.
     * @return array|WP_Error Array with storage metadata on success, WP_Error on failure.
     */
    public function upload_file( array $file, int $user_id );

    /**
     * Delete file from target storage
     *
     * @param string $file_identifier Storage key, path, or file ID.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_file( string $file_identifier );

    /**
     * Stream file download directly to browser
     *
     * @param string $file_identifier Storage key, path, or file ID.
     * @param string $mime_type       MIME content type.
     * @param string $original_name   Original filename.
     * @return void
     */
    public function get_download_stream( string $file_identifier, string $mime_type = 'application/octet-stream', string $original_name = '' );

    /**
     * Get direct or proxy URL for file download
     *
     * @param string $file_identifier Storage key, path, or file ID.
     * @return string Download URL.
     */
    public function get_file_url( string $file_identifier ): string;
}
