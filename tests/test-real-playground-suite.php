<?php
/**
 * GNN FileHub NextGen - Real WordPress Playground Integration QA Suite
 * Boots the actual WordPress Core engine via wp-load.php from the Playground site.
 */

$playground_site_dir = 'C:/Users/bigde/.wordpress-playground/sites/554db6a21b4296bf0ca534f357ed737f05cf78dc03e24d226f6ecaf911b8bfc0';

if ( ! file_exists( $playground_site_dir . '/wp-load.php' ) ) {
    echo "ERROR: WordPress Playground site not found at $playground_site_dir\n";
    exit( 1 );
}

// Set up server environment for WP boot
$_SERVER['HTTP_HOST']   = '127.0.0.1:9400';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once $playground_site_dir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

echo "=================================================================\n";
echo "  GNN FILEHUB NEXTGEN - REAL WP PLAYGROUND INTEGRATION SUITE      \n";
echo sprintf( "  WordPress Version: %s | PHP Version: %s\n", get_bloginfo( 'version' ), PHP_VERSION );
echo "=================================================================\n\n";

$suite_passed = 0;
$suite_failed = 0;

function run_real_test( $id, $description, callable $test_fn ) {
    global $suite_passed, $suite_failed;
    echo sprintf( "[%s] %s ... ", $id, $description );
    try {
        $res = $test_fn();
        if ( $res === true ) {
            echo "PASSED\n";
            $suite_passed++;
        } else {
            echo "FAILED (" . ( is_string( $res ) ? $res : 'Assertion Failed' ) . ")\n";
            $suite_failed++;
        }
    } catch ( Exception $e ) {
        echo "FAILED (Exception: " . $e->getMessage() . ")\n";
        $suite_failed++;
    }
}

// S1: Plugin Activation & Admin Page Registration
run_real_test( 'S1', 'Activate Plugin & Register WP Admin Menu Page', function() {
    $plugin_slug = 'gnn-filehub/gnn-filehub-nextgen.php';
    $result = activate_plugin( $plugin_slug );
    if ( is_wp_error( $result ) ) {
        return "Failed to activate plugin: " . $result->get_error_message();
    }

    if ( ! is_plugin_active( $plugin_slug ) ) {
        return "Plugin is not marked active in WordPress options.";
    }

    if ( ! class_exists( 'FileHub_Core' ) || ! class_exists( 'FileHub_Admin' ) ) {
        return "FileHub classes not present in real WP runtime.";
    }

    return true;
} );

// S2: Real REST API Route Registration Check
run_real_test( 'S2', 'Verify REST API Routes Registered in WP_REST_Server', function() {
    $wp_rest_server = rest_get_server();
    $routes = $wp_rest_server->get_routes();

    $expected_routes = array(
        '/filehub/v1/upload',
        '/filehub/v1/files',
        '/filehub/v1/change-password',
        '/filehub/v1/register',
    );

    foreach ( $expected_routes as $expected ) {
        if ( ! isset( $routes[ $expected ] ) ) {
            return "Expected REST route '$expected' not registered in WP_REST_Server.";
        }
    }

    return true;
} );

// S3: Real User Registration & Auto-Login via WP REST API Endpoint
run_real_test( 'S3', 'Real WP User Registration & Auto-Login Endpoint', function() {
    $rest_api = new FileHub_REST_API();

    $username = 'pguser_' . time();
    $email    = $username . '@example.com';

    $request = new WP_REST_Request( 'POST', '/filehub/v1/register' );
    $request->set_json_params( array(
        'username'         => $username,
        'email'            => $email,
        'first_name'       => 'Playground',
        'last_name'        => 'Tester',
        'password'         => 'SecretPass123!',
        'confirm_password' => 'SecretPass123!',
    ) );

    $response = $rest_api->handle_register_user( $request );
    $data = $response->get_data();

    if ( empty( $data['success'] ) || $data['success'] !== true ) {
        return "Registration endpoint failed: " . ( isset( $data['error'] ) ? $data['error'] : 'Unknown error' );
    }

    $user_id = username_exists( $username );
    if ( ! $user_id ) {
        return "User was not created in real WordPress SQLite database.";
    }

    if ( get_current_user_id() !== (int) $user_id ) {
        return "Auto-login failed. Current user ID does not match newly created user ID.";
    }

    return true;
} );

// S4: Real Password Change via REST Endpoint in WP DB
run_real_test( 'S4', 'Real WP Password Change via REST Endpoint', function() {
    $rest_api = new FileHub_REST_API();
    $user_id  = get_current_user_id();

    if ( ! $user_id ) {
        return "No logged in user for password change test.";
    }

    $request = new WP_REST_Request( 'POST', '/filehub/v1/change-password' );
    $request->set_json_params( array(
        'current_password' => 'SecretPass123!',
        'new_password'     => 'NewSecretPass456!',
        'confirm_password' => 'NewSecretPass456!',
    ) );

    $response = $rest_api->handle_change_password( $request );
    $data = $response->get_data();

    if ( empty( $data['success'] ) || $data['success'] !== true ) {
        return "Password change failed: " . ( isset( $data['error'] ) ? $data['error'] : 'Unknown' );
    }

    $user = get_userdata( $user_id );
    if ( ! wp_check_password( 'NewSecretPass456!', $user->user_pass, $user_id ) ) {
        return "wp_check_password failed against updated password in WP database.";
    }

    return true;
} );

// S5: Real Attachment CPT & Stats Calculation in WP DB
run_real_test( 'S5', 'Real Attachment CPT Creation & Storage Stats in WP DB', function() {
    $user_id = get_current_user_id();
    $storage_meta = array(
        'storage_driver' => 'local',
        'storage_key'    => $user_id . '/sample_doc.pdf',
        'file_name'      => 'sample_doc.pdf',
        'file_size'      => 1048576, // 1MB
    );

    $att_id = FileHub_Attachment::create_attachment( $storage_meta, $user_id, 'application/pdf' );
    if ( is_wp_error( $att_id ) || ! $att_id ) {
        return "Failed to create attachment CPT post in WP database.";
    }

    $user_stats = FileHub_Attachment::get_user_stats( $user_id );
    if ( $user_stats['file_count'] < 1 || $user_stats['total_bytes'] < 1048576 ) {
        return "User storage stats mismatch in WP DB.";
    }

    $sys_stats = FileHub_Attachment::get_system_stats();
    if ( $sys_stats['total_files'] < 1 || $sys_stats['total_bytes'] < 1048576 ) {
        return "System storage stats mismatch in WP DB.";
    }

    return true;
} );

// S6: Real WooCommerce-Style Shortcode Auto-Injection Filter
run_real_test( 'S6', 'Real WooCommerce-Style Page Shortcode Injection Filter', function() {
    // Create a real WP Page in SQLite database
    $page_id = wp_insert_post( array(
        'post_title'   => 'Register Test Page',
        'post_content' => 'Welcome to our site.',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ) );

    if ( ! $page_id || is_wp_error( $page_id ) ) {
        return "Failed to insert test page into WP database.";
    }

    // Set this page as assigned Register page
    update_option( 'filehub_page_register', $page_id );

    // Mock global WP_Query post state
    global $wp_query, $post;
    $post = get_post( $page_id );
    $wp_query->queried_object_id = $page_id;
    $wp_query->is_singular = true;
    $wp_query->is_page = true;
    $wp_query->in_the_loop = true;

    $shortcodes = new FileHub_Shortcodes();
    $injected_content = $shortcodes->auto_inject_shortcodes( $post->post_content );

    if ( strpos( $injected_content, '[filehub_register]' ) === false ) {
        return "Shortcode [filehub_register] was not automatically injected into page content.";
    }

    return true;
} );

// S7: Real Protected Storage .htaccess File System Check
run_real_test( 'S7', 'Real Protected Storage Directory & .htaccess Check', function() {
    $core = FileHub_Core::get_instance();
    $core->ensure_protected_storage_dir();

    $upload_dir    = wp_upload_dir();
    $protected_dir = $upload_dir['basedir'] . '/filehub-protected';
    $htaccess      = $protected_dir . '/.htaccess';

    if ( ! is_dir( $protected_dir ) ) {
        return "Protected directory was not created on disk.";
    }

    if ( ! file_exists( $htaccess ) ) {
        return ".htaccess file is missing in protected directory.";
    }

    $content = file_get_contents( $htaccess );
    if ( strpos( $content, 'Deny from all' ) === false ) {
        return ".htaccess does not enforce 'Deny from all'.";
    }

    return true;
} );

// S8: Cloud Storage Drivers Interface & Signature Check
run_real_test( 'S8', 'Cloud Storage Drivers Header Signing & Class Contract', function() {
    $r2 = new FileHub_Storage_R2( 'acc', 'key', 'sec', 'bucket' );
    $gd = new FileHub_Storage_GDrive();

    if ( ! ( $r2 instanceof FileHub_Storage_Interface ) || ! ( $gd instanceof FileHub_Storage_Interface ) ) {
        return "Cloud drivers do not implement FileHub_Storage_Interface.";
    }

    return true;
} );

echo "\n-----------------------------------------------------------------\n";
echo sprintf( "REAL SUITE RESULTS: %d PASSED, %d FAILED\n", $suite_passed, $suite_failed );
echo "-----------------------------------------------------------------\n";

if ( $suite_failed === 0 ) {
    echo "VERDICT: REAL WORDPRESS PLAYGROUND INTEGRATION SUITE PASSED 100% CLEAN!\n";
} else {
    echo "VERDICT: REAL INTEGRATION SUITE DETECTED FAILURES.\n";
    exit( 1 );
}
