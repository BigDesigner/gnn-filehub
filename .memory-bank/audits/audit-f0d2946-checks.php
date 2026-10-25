<?php
/** Isolated audit reproductions. Real plugin classes, simulated WordPress/HTTP boundaries.
 * No WordPress bootstrap, real accounts, credentials, database or network are used.
 * Run: php .memory-bank/audits/audit-f0d2946-checks.php
 * PASS means the reported defect was reproduced, NOT that the product is secure.
 */
define('ABSPATH', __DIR__ . '/');
define('GNN_FILEHUB_PATH', dirname(__DIR__, 2) . '/gnn-filehub/');
define('GNN_FILEHUB_FILE', GNN_FILEHUB_PATH . 'gnn-filehub-nextgen.php');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
$auditRoot = sys_get_temp_dir() . '/filehub-audit-' . bin2hex(random_bytes(8));
mkdir($auditRoot);
$options = $posts = $meta = $userMeta = $transients = $httpCalls = [];
$uid = 7;
$remoteStatus = 200;
$lastQuery = [];
class WP_REST_Controller {}
class WP_Error {
    public function __construct(public $code, public $message = '', public $data = []) {}
    public function get_error_message() { return $this->message; }
}
class WP_REST_Response {
    public function __construct(public $data, public $status = 200) {}
}
class AuditRequest {
    public function __construct(public $params = [], public $files = []) {}
    public function get_json_params() { return $this->params; }
    public function get_param($key) { return $this->params[$key] ?? null; }
    public function get_file_params() { return $this->files; }
}
function add_action(...$args) {}
function add_filter(...$args) {}
function __($s, ...$args) { return $s; }
function esc_html__($s, ...$args) { return $s; }
function is_wp_error($x) { return $x instanceof WP_Error; }
function absint($x) { return abs((int)$x); }
function sanitize_text_field($x) { return trim(strip_tags((string)$x)); }
function sanitize_file_name($x) { return basename($x); }
function get_option($k, $default = false) { return $GLOBALS['options'][$k] ?? $default; }
function get_user_meta($id, $k, $single = true) { return $GLOBALS['userMeta'][$id][$k] ?? ''; }
function update_user_meta($id, $k, $v) { $GLOBALS['userMeta'][$id][$k] = $v; }
function delete_user_meta($id, $k) { unset($GLOBALS['userMeta'][$id][$k]); }
function get_post_meta($id, $k, $single = true) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function wp_insert_attachment($data) {
    $id = count($GLOBALS['posts']) + 1;
    $GLOBALS['posts'][$id] = (object) array_merge($data, ['ID' => $id, 'post_type' => 'attachment', 'post_parent' => 0]);
    return $id;
}
function wp_delete_attachment($id, $force) { unset($GLOBALS['posts'][$id], $GLOBALS['meta'][$id]); return true; }
function get_posts($args) {
    $GLOBALS['lastQuery'] = $args;
    $found = array_filter($GLOBALS['posts'], function($p) use ($args) {
        return isset($GLOBALS['meta'][$p->ID]['_filehub_storage_driver'])
            && (empty($args['author']) || $p->post_author == $args['author']);
    });
    if (($args['posts_per_page'] ?? -1) > 0) $found = array_slice($found, 0, $args['posts_per_page']);
    return ($args['fields'] ?? '') === 'ids' ? array_map(fn($p) => $p->ID, $found) : array_values($found);
}
function get_current_user_id() { return $GLOBALS['uid']; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function current_user_can($cap, ...$args) { return $cap === 'edit_user' && ($args[0] ?? 0) === get_current_user_id(); }
function wp_verify_nonce(...$args) { return true; }
function wp_create_nonce(...$args) { return 'synthetic-nonce'; }
function add_query_arg(...$args) { return 'https://audit.invalid/download'; }
function rest_url($s = '') { return 'https://audit.invalid/wp-json/' . $s; }
function size_format($v) { return (string)$v; }
function get_userdata($id) { return (object)['display_name' => 'Synthetic']; }
function get_the_title($id) { return get_post($id)->post_title; }
function get_the_date(...$args) { return '2026-10-24'; }
function wp_upload_dir() { return ['basedir' => $GLOBALS['auditRoot']]; }
function wp_mkdir_p($p) { return is_dir($p) || mkdir($p, 0700, true); }
function wp_tempnam($prefix) { return tempnam($GLOBALS['auditRoot'], $prefix); }
function status_header($s) { $GLOBALS['httpStatus'] = $s; }
function wp_die($message) { throw new RuntimeException($message); }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $ttl) { $GLOBALS['transients'][$k] = $v; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); }
function plugin_basename($p) { return 'gnn-filehub/gnn-filehub-nextgen.php'; }
function get_bloginfo($s) { return 'synthetic'; }
function home_url() { return 'https://audit.invalid'; }
function wp_remote_request($url, $args = []) {
    $GLOBALS['httpCalls'][] = [$url, $args['method'] ?? 'GET'];
    return ['response' => ['code' => $GLOBALS['remoteStatus']], 'headers' => ['content-length' => 4, 'content-type' => 'application/pdf']];
}
function wp_remote_get($url, $args = []) { return wp_remote_request($url, $args); }
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_header($r, $k) { return $r['headers'][$k] ?? ''; }
function wp_remote_retrieve_body($r) { return '{}'; }
require GNN_FILEHUB_PATH . 'inc/class-filehub-rest-api.php';
require GNN_FILEHUB_PATH . 'inc/class-filehub-admin.php';
require GNN_FILEHUB_PATH . 'inc/class-filehub-updater.php';
$api = new FileHub_REST_API();
$checks = [];
function reset_case() {
    $GLOBALS['options'] = ['filehub_upload_notify' => '0', 'filehub_default_quota_mb' => 1, 'filehub_strict_mime' => '1',
        'filehub_r2_account_id' => 'synthetic', 'filehub_r2_access_key' => 'synthetic', 'filehub_r2_secret_key' => 'synthetic', 'filehub_r2_bucket' => 'synthetic'];
    foreach (['posts','meta','userMeta','transients','httpCalls'] as $key) $GLOBALS[$key] = [];
    $GLOBALS['uid'] = 7; $GLOBALS['remoteStatus'] = 200;
}
function record_case($id, $description, $fn) {
    reset_case();
    try { $ok = $fn() === true; $detail = ''; }
    catch (Throwable $e) { $ok = false; $detail = get_class($e) . ': ' . $e->getMessage(); }
    $GLOBALS['checks'][] = compact('id', 'description', 'ok', 'detail');
}
function seed_file($size = 4, $driver = 'local', $key = '7/test.pdf') {
    return FileHub_Attachment::create_attachment(['storage_driver' => $driver, 'storage_key' => $key, 'file_name' => 'test.pdf', 'file_size' => $size], get_current_user_id(), 'application/pdf');
}
function fixture($body = 'DATA') {
    $tmp = tempnam($GLOBALS['auditRoot'], 'source-'); file_put_contents($tmp, $body);
    return ['name' => 'test.pdf', 'tmp_name' => $tmp, 'type' => 'application/pdf', 'size' => strlen($body), 'error' => 0];
}
record_case('T01', 'Single upload succeeds even when quota is full', function() use ($api) {
    seed_file(1048576);
    return $api->handle_upload(new AuditRequest([], ['file' => fixture()]))->status === 200;
});
record_case('T02', 'Chunk merge exceeds remaining quota', function() use ($api) {
    seed_file(1048575);
    $r = $api->handle_upload_chunk(new AuditRequest(['file_id'=>'quota-check','chunk_index'=>0,'total_chunks'=>1,'filename'=>'test.pdf','total_size'=>4], ['chunk'=>fixture()]));
    return $r->status === 200 && FileHub_Attachment::get_user_stats(7)['total_bytes'] > 1048576;
});
record_case('T03', 'Strict MIME accepts plain text named PDF', function() use ($api) {
    return $api->handle_upload(new AuditRequest([], ['file'=>fixture('This is not a PDF')]))->status === 200;
});
record_case('T04', 'R2 finalize permits duplicate records and forbidden filename', function() use ($api) {
    $GLOBALS['options']['filehub_storage_driver'] = 'r2';
    $req = new AuditRequest(['key'=>'uploads/7/already.pdf','filename'=>'renamed.exe','mime_type'=>'application/octet-stream']);
    $a=$api->handle_r2_finalize($req); $b=$api->handle_r2_finalize($req);
    return $a->status === 200 && $b->status === 200 && count($GLOBALS['posts']) === 2;
});
record_case('T05', 'Same-second R2 uploads share the same key', function() {
    $r2=new FileHub_Storage_R2();
    for ($i=0;$i<3;$i++) {
        $a=$r2->get_presigned_upload_url('same.pdf', 7); $b=$r2->get_presigned_upload_url('same.pdf', 7);
        if ($a['key'] === $b['key']) return true;
    }
    return false;
});
record_case('T06', 'Cloud HTTP 403 still removes DB record and reports success', function() use ($api) {
    $id=seed_file(4, 'r2', 'uploads/7/test.pdf'); $GLOBALS['remoteStatus']=403;
    $r=$api->handle_delete_file(['id'=>$id]);
    return $r->data['success'] && !isset($GLOBALS['posts'][$id]) && count($GLOBALS['httpCalls'])===1;
});
record_case('T07', 'User with edit-self permission can raise own quota', function() {
    $_POST=['filehub_user_quota_nonce'=>'synthetic', 'filehub_custom_quota_mb'=>'999999'];
    (new FileHub_Admin())->save_user_profile_fields(7);
    return get_user_meta(7, '_filehub_custom_quota_mb')===999999;
});
record_case('T08', 'Anonymous guest download passes ownership and reaches storage', function() use ($api) {
    $GLOBALS['uid']=0; $id=seed_file(4, 'r2', 'uploads/0/test.pdf'); $GLOBALS['remoteStatus']=404;
    try { $api->handle_download_file(['id'=>$id]); } catch (RuntimeException $e) {}
    return count($GLOBALS['httpCalls'])===1;
});
record_case('T09', 'Zero unlimited quota actually returns 500 MB', function() {
    $GLOBALS['options']['filehub_default_quota_mb']=0;
    return FileHub_Attachment::get_user_quota(7)===500*1024*1024;
});
record_case('T10', 'Missing profile JSON throws TypeError', function() use ($api) {
    try { $api->handle_update_profile(new AuditRequest(null)); }
    catch (TypeError $e) { return true; }
    return false;
});
record_case('T11', 'Out-of-range chunk index is saved', function() use ($api) {
    $r=$api->handle_upload_chunk(new AuditRequest(['file_id'=>'invalid-index','chunk_index'=>999,'total_chunks'=>2,'filename'=>'test.pdf'], ['chunk'=>fixture()]));
    return $r->status===200 && is_file($GLOBALS['auditRoot'].'/filehub-protected/chunks/7_invalid-index/part_999');
});
record_case('T12', 'Failed chunk copy still returns chunk_saved', function() use ($api) {
    $r=$api->handle_upload_chunk(new AuditRequest(['file_id'=>'missing-source','chunk_index'=>0,'total_chunks'=>2,'filename'=>'test.pdf'], ['chunk'=>['tmp_name'=>$GLOBALS['auditRoot'].'/absent', 'type'=>'application/pdf','error'=>1]]));
    return $r->status===200 && $r->data['status']==='chunk_saved';
});
record_case('T13', 'Updater false cache repeats failed network calls', function() {
    $GLOBALS['remoteStatus']=503; $up=new FileHub_Updater();
    $method=new ReflectionMethod($up, 'get_remote_release');
    $method->invoke($up); $method->invoke($up);
    return count($GLOBALS['httpCalls'])===2;
});
record_case('T14', 'Google Drive parent change reuses old cached user folder', function() {
    $GLOBALS['options']['filehub_gdrive_folder_id']='NEW_PARENT';
    $GLOBALS['transients']['filehub_gdrive_user_folder_7']='OLD_CHILD';
    $driver=new FileHub_Storage_GDrive();
    return (new ReflectionMethod($driver, 'get_or_create_user_folder'))->invoke($driver, 7, 'synthetic')==='OLD_CHILD';
});
record_case('T15', 'File listing ignores page two', function() use ($api) {
    seed_file(); $api->handle_list_files(new AuditRequest(['page'=>2]));
    return !isset($GLOBALS['lastQuery']['paged']) && !isset($GLOBALS['lastQuery']['offset']);
});
record_case('T16', 'Delete endpoint accepts ordinary non-FileHub media', function() use ($api) {
    $id=wp_insert_attachment(['post_author'=>7, 'post_title'=>'ordinary media']);
    return $api->handle_delete_file(['id'=>$id])->data['success'] && !isset($GLOBALS['posts'][$id]);
});
record_case('T17', 'Path prefix check accepts sibling storage directory', function() {
    $outside=$GLOBALS['auditRoot'].'/filehub-protected-sibling'; mkdir($outside);
    file_put_contents($outside.'/probe.txt','synthetic');
    (new FileHub_Storage_Local())->delete_file('../filehub-protected-sibling/probe.txt');
    return !file_exists($outside.'/probe.txt');
});
record_case('T18', 'Updater discards an earlier installation error for another plugin', function() {
    $error=new WP_Error('synthetic_install_failure', 'Synthetic failure');
    $result=(new FileHub_Updater())->after_install($error, ['plugin'=>'other/other.php'], ['destination'=>'synthetic']);
    return !is_wp_error($result);
});
// Cleanup is restricted to the unique, newly-created audit fixture directory.
$resolved=realpath($auditRoot);
if ($resolved && str_starts_with(basename($resolved), 'filehub-audit-') && dirname($resolved)===realpath(sys_get_temp_dir())) {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($resolved);
}
echo json_encode(['mode'=>'isolated real-class reproductions, simulated WordPress and HTTP', 'reproduced'=>count(array_filter($checks, fn($c)=>$c['ok'])), 'total'=>count($checks), 'checks'=>$checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
exit(count(array_filter($checks, fn($c)=>!$c['ok'])) ? 1 : 0);
