<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FileHub_REST_API extends WP_REST_Controller {
    protected $namespace = 'filehub/v1';
    public function __construct() { add_action('rest_api_init', array($this,'register_routes')); }

    public function register_routes() {
        $this->route('/uploads','POST','begin_upload','check_upload_permission');
        $id='/(?P<id>[a-f0-9-]{36})';
        $this->route('/uploads'.$id,'GET','upload_status','check_session_permission');
        $this->route('/uploads'.$id,'DELETE','cancel_upload','check_session_permission');
        $this->route('/uploads'.$id.'/chunks/(?P<index>\d+)','POST','upload_chunk','check_upload_permission');
        $this->route('/uploads'.$id.'/complete','POST','complete_upload','check_upload_permission');
        foreach(array('/upload','/upload-chunk','/r2-presign','/r2-finalize') as $path) $this->route($path,'POST','retired_upload','check_upload_permission');
        $this->route('/files','GET','handle_list_files','check_user_permission');
        $this->route('/files/(?P<id>\d+)','DELETE','handle_delete_file','check_file_permission');
        $this->route('/download/(?P<id>\d+)','GET','handle_download_file','check_file_permission');
        $this->route('/update-profile','POST','handle_update_profile','check_user_permission');
        $this->route('/register','POST','handle_register_user','check_session_permission');
        register_rest_route($this->namespace,'/verify-email',array('methods'=>'GET','callback'=>array($this,'verify_email'),'permission_callback'=>'__return_true'));
    }
    private function route($path,$methods,$callback,$permission) {
        register_rest_route($this->namespace,$path,array('methods'=>$methods,'callback'=>array($this,$callback),'permission_callback'=>array($this,$permission)));
    }
    public function check_session_permission($request) {
        $nonce=$request->get_header('X-WP-Nonce') ?: $request->get_param('_wpnonce');
        return is_string($nonce) && wp_verify_nonce($nonce,'wp_rest') ? true : FileHub_Security::error(__('Güvenlik doğrulaması başarısız; sayfayı yenileyin.','gnn-filehub'),403);
    }
    public function check_user_permission($request) {
        if(!is_user_logged_in() || !current_user_can('read'))return FileHub_Security::error(__('Bu işlem için giriş yapmalısınız.','gnn-filehub'),401);
        return $this->check_session_permission($request);
    }
    public function check_upload_permission($request) {
        $nonce=$this->check_session_permission($request); if(is_wp_error($nonce))return $nonce;
        if(!is_user_logged_in() && get_option('filehub_guest_upload','0')!=='1')return FileHub_Security::error(__('Yükleme yapmak için giriş yapmalısınız.','gnn-filehub'),401);
        if(is_user_logged_in() && (!current_user_can('read') || get_user_meta(get_current_user_id(),'_filehub_pending_email',true)))return FileHub_Security::error(__('Önce e-posta adresinizi doğrulayın.','gnn-filehub'),403);
        return true;
    }
    public function check_file_permission($request) {
        $allowed=$this->check_user_permission($request); if(is_wp_error($allowed))return $allowed;
        return FileHub_Security::can_access((int)$request['id']) ? true : FileHub_Security::error(__('Dosya bulunamadı.','gnn-filehub'),404);
    }
    public function retired_upload($request) { return FileHub_Security::error(__('Yükleme yöntemi güncellendi; sayfayı yenileyin.','gnn-filehub'),410); }
    private function json_strings($request,array $keys) {
        $data=$request->get_json_params();
        if(!is_array($data) || strlen($request->get_body())>16384)return FileHub_Security::error(__('Geçerli bir JSON nesnesi gönderin.','gnn-filehub'));
        foreach($keys as $key)if(isset($data[$key]) && (!is_string($data[$key]) || strlen($data[$key])>4096))return FileHub_Security::error(__('Geçersiz alan türü veya uzunluğu.','gnn-filehub'));
        foreach(array('display_name'=>250,'username'=>60,'email'=>254,'first_name'=>100,'last_name'=>100) as $key=>$limit)if(isset($data[$key]) && is_string($data[$key]) && FileHub_Security::password_length($data[$key])>$limit)return FileHub_Security::error(__('Alan çok uzun.','gnn-filehub'));
        return $data;
    }
    public function begin_upload($request) {
        $data=$request->get_json_params();
        if(!is_array($data) || !isset($data['filename'],$data['size']))return FileHub_Security::error(__('Dosya adı ve boyutu gereklidir.','gnn-filehub'));
        $rate=FileHub_Security::rate_limit('uploads',30,HOUR_IN_SECONDS); if(is_wp_error($rate))return $rate;
        return FileHub_Uploads::begin($data['filename'],$data['size']);
    }
    private function session($request,$fn) {
        $token=$request->get_header('X-FileHub-Token');
        if(!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token))return FileHub_Security::error(__('Yükleme bulunamadı.','gnn-filehub'),404);
        return FileHub_Uploads::operate((string)$request['id'],$token,$fn);
    }
    public function upload_status($request) { return $this->session($request,array('FileHub_Uploads','status')); }
    public function cancel_upload($request) { return $this->session($request,array('FileHub_Uploads','cancel')); }
    public function upload_chunk($request) {
        $rate=FileHub_Security::rate_limit('chunks',600,MINUTE_IN_SECONDS); if(is_wp_error($rate))return $rate;
        return $this->session($request,function($s)use($request) {
            $files=$request->get_file_params();
            if(!isset($files['chunk']) || !is_array($files['chunk']))return FileHub_Security::error(__('Dosya parçası gereklidir.','gnn-filehub'));
            return FileHub_Uploads::append($s,(int)$request['index'],$files['chunk']);
        });
    }
    public function complete_upload($request) { $rate=FileHub_Security::rate_limit('transfer',180,MINUTE_IN_SECONDS); if(is_wp_error($rate))return $rate; return $this->session($request,array('FileHub_Uploads','complete')); }
    public function handle_list_files($request) {
        $admin=current_user_can('manage_options');
        $scope=$admin && $request->get_param('scope')==='all' ? 'all' : 'own';
        $page=$request->get_param('page')??1; $per=$request->get_param('per_page')??25;
        if(!ctype_digit((string)$page) || !ctype_digit((string)$per) || (int)$page<1 || (int)$page>100000 || (int)$per<1 || (int)$per>100)return FileHub_Security::error(__('Geçersiz sayfa bilgisi.','gnn-filehub'));
        $search=$request->get_param('search')??'';
        if(!is_string($search) || strlen($search)>200)return FileHub_Security::error(__('Geçersiz arama.','gnn-filehub'));
        $args=array('post_type'=>'attachment','post_status'=>array('private','inherit','trash'),'posts_per_page'=>(int)$per,'paged'=>(int)$page,'orderby'=>array('date'=>'DESC','ID'=>'DESC'),'meta_key'=>'_filehub_storage_driver','meta_query'=>array('relation'=>'OR',array('key'=>'_filehub_upload_id','compare'=>'NOT EXISTS'),array('key'=>'_filehub_complete','value'=>1)),'filehub_internal'=>true,'suppress_filters'=>false);
        if($scope==='own')$args['author__in']=array(get_current_user_id());
        $search_filter=function($sql,$query)use($search) {
            if(!$query->get('filehub_search'))return $sql;
            global $wpdb;
            $like='%'.$wpdb->esc_like($search).'%';
            return $wpdb->prepare(" AND ({$wpdb->posts}.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} fm WHERE fm.post_id={$wpdb->posts}.ID AND fm.meta_key='_filehub_file_name' AND fm.meta_value LIKE %s) OR EXISTS (SELECT 1 FROM {$wpdb->users} fu WHERE fu.ID={$wpdb->posts}.post_author AND fu.display_name LIKE %s)) ",$like,$like,$like);
        };
        if($search!=='') { $args['filehub_search']=true; add_filter('posts_search',$search_filter,10,2); }
        try { $query=new WP_Query($args); } finally { remove_filter('posts_search',$search_filter,10); }
        $data=array();
        foreach($query->posts as $post) {
            $id=$post->ID; $author=get_userdata($post->post_author); $size=(int)get_post_meta($id,'_filehub_file_size',true);
            $data[]=array('id'=>$id,'title'=>get_the_title($id),'file_name'=>get_post_meta($id,'_filehub_file_name',true)?:get_the_title($id),'file_size'=>size_format($size),'size_bytes'=>$size,'mime_type'=>$post->post_mime_type,'driver'=>FileHub_Attachment::ascii_upper(get_post_meta($id,'_filehub_storage_driver',true)),'download_count'=>(int)get_post_meta($id,'_filehub_download_count',true),'author_id'=>(int)$post->post_author,'author_name'=>$author?$author->display_name:__('Misafir','gnn-filehub'),'created_at'=>get_the_date('Y-m-d H:i',$id),'download_url'=>$this->build_download_url($id),'can_delete'=>$admin || (int)$post->post_author===get_current_user_id());
        }
        $response=new WP_REST_Response($data,200);
        $response->header('X-WP-Total',(int)$query->found_posts); $response->header('X-WP-TotalPages',(int)$query->max_num_pages);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
    public function handle_delete_file($request) {
        $id=(int)$request['id'];
        if(!FileHub_Security::can_access($id))return FileHub_Security::error(__('Dosya bulunamadı.','gnn-filehub'),404);
        return FileHub_Security::locked('owner:'.(int)get_post_field('post_author',$id),function()use($id) {
            return wp_delete_attachment($id,true) ? array('success'=>true) : FileHub_Security::error(__('Dosya silinemedi; kayıt korundu.','gnn-filehub'),503);
        });
    }
    private function build_download_url(int $id): string { return add_query_arg('_wpnonce',wp_create_nonce('wp_rest'),rest_url('filehub/v1/download/'.$id)); }
    public function handle_download_file($request) {
        $id=(int)$request['id'];
        if(!FileHub_Security::can_access($id))return FileHub_Security::error(__('Dosya bulunamadı.','gnn-filehub'),404);
        $driver=FileHub_Security::driver(get_post_meta($id,'_filehub_storage_driver',true)); if(is_wp_error($driver))return $driver;
        $count=function()use($id) { FileHub_Attachment::increment_download_count($id); };
        add_action('filehub_stream_complete',$count);
        $result=$driver->get_download_stream(get_post_meta($id,'_filehub_storage_key',true),get_post_mime_type($id),get_post_meta($id,'_filehub_file_name',true)?:get_the_title($id));
        remove_action('filehub_stream_complete',$count);
        return $result;
    }
    public function handle_update_profile( $request ) {
        $params = $this->json_strings($request, array('display_name','current_password','new_password','confirm_password'));
        if (is_wp_error($params)) return $params;
        $has_display_name = array_key_exists( 'display_name', $params );
        $display_name     = $has_display_name ? sanitize_text_field( (string) $params['display_name'] ) : '';
        $wants_password_change = array_key_exists( 'new_password', $params )
            || array_key_exists( 'confirm_password', $params )
            || array_key_exists( 'current_password', $params );
        $current_password = isset( $params['current_password'] ) ? $params['current_password'] : '';
        $new_password      = isset( $params['new_password'] ) ? $params['new_password'] : '';
        $confirm_password  = isset( $params['confirm_password'] ) ? $params['confirm_password'] : '';

        if ( ! $has_display_name && ! $wants_password_change ) {
            return new WP_REST_Response( array( 'error' => __( 'Güncellenecek bir bilgi gönderilmedi.', 'gnn-filehub' ) ), 400 );
        }

        $user = wp_get_current_user();

        if ( $has_display_name && '' === $display_name ) {
            return new WP_REST_Response( array( 'error' => __( 'Görünen ad boş bırakılamaz.', 'gnn-filehub' ) ), 400 );
        }

        if ( $wants_password_change ) {
            if ( empty( $current_password ) || empty( $new_password ) || empty( $confirm_password ) ) {
                return new WP_REST_Response( array( 'error' => __( 'Şifrenizi değiştirmek için tüm şifre alanlarını doldurun.', 'gnn-filehub' ) ), 400 );
            }

            if ( $new_password !== $confirm_password ) {
                return new WP_REST_Response( array( 'error' => __( 'Yeni şifreler birbiriyle eşleşmiyor.', 'gnn-filehub' ) ), 400 );
            }

            if ( FileHub_Security::password_length($new_password) < 12 ) {
                return new WP_REST_Response( array( 'error' => __( 'Şifre en az 12 karakter olmalıdır.', 'gnn-filehub' ) ), 400 );
            }

            if ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
                return new WP_REST_Response( array( 'error' => __( 'Mevcut şifreniz yanlış.', 'gnn-filehub' ) ), 400 );
            }
        }

        if ( $has_display_name ) {
            $updated = wp_update_user( array( 'ID' => $user->ID, 'display_name' => $display_name ) );
            if (is_wp_error($updated)) return $updated;
        }

        if ( $wants_password_change ) {
            wp_set_password( $new_password, $user->ID );
            wp_set_current_user( $user->ID );
            wp_set_auth_cookie( $user->ID );
        }

        $response = array(
            'success' => true,
            'reload' => $wants_password_change,
            'message' => $wants_password_change
                ? __( 'Şifreniz başarıyla güncellendi.', 'gnn-filehub' )
                : __( 'Bilgileriniz başarıyla güncellendi.', 'gnn-filehub' ),
        );

        if ( $has_display_name ) {
            $response['display_name'] = $display_name;
        }

        return new WP_REST_Response( $response, 200 );
    }


    public function handle_register_user($request) {
        if(is_user_logged_in())return FileHub_Security::error(__('Zaten giriş yapmış durumdasınız.','gnn-filehub'));
        if(!get_option('users_can_register'))return FileHub_Security::error(__('Siteye yeni üye kaydı kapalıdır.','gnn-filehub'),403);
        $rate=FileHub_Security::rate_limit('register',6,HOUR_IN_SECONDS); if(is_wp_error($rate))return $rate;
        $p=$this->json_strings($request,array('username','email','first_name','last_name','password','confirm_password')); if(is_wp_error($p))return $p;
        $username=sanitize_user(trim($p['username']??''),true); $email=sanitize_email(trim($p['email']??''));
        $password=$p['password']??'';
        if(!$username || strlen($username)>60 || !validate_username($username) || !is_email($email) || strlen($email)>254 || FileHub_Security::password_length($password)<12 || $password!==($p['confirm_password']??''))return FileHub_Security::error(__('Geçerli kullanıcı adı, e-posta ve en az 12 karakterlik eşleşen şifreler gereklidir.','gnn-filehub'));
        if(username_exists($username) || email_exists($email))return FileHub_Security::error(__('Bu bilgilerle kayıt oluşturulamıyor.','gnn-filehub'));
        $errors=new WP_Error();
        do_action('register_post',$username,$email,$errors);
        $errors=apply_filters('registration_errors',$errors,$username,$email);
        if(is_wp_error($errors) && $errors->has_errors())return $errors;
        $first=sanitize_text_field($p['first_name']??''); $last=sanitize_text_field($p['last_name']??'');
        $token=bin2hex(random_bytes(32));
        $id=wp_insert_user(array('user_login'=>$username,'user_email'=>$email,'user_pass'=>$password,'first_name'=>$first,'last_name'=>$last,'display_name'=>trim($first.' '.$last)?:$username,'role'=>get_option('default_role','subscriber'),'meta_input'=>array('_filehub_pending_email'=>1,'_filehub_email_token'=>hash('sha256',$token),'_filehub_email_expires'=>time()+DAY_IN_SECONDS)));
        if(is_wp_error($id))return $id;
        $url=add_query_arg(array('user'=>$id,'token'=>$token),rest_url('filehub/v1/verify-email'));
        $sent=wp_mail($email,__('E-posta adresinizi doğrulayın','gnn-filehub'),sprintf(__('Hesabınızı etkinleştirmek için bu bağlantıyı 24 saat içinde açın: %s','gnn-filehub'),$url));
        if(!$sent) {
            require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($id);
            return FileHub_Security::error(__('Doğrulama e-postası gönderilemedi; daha sonra yeniden deneyin.','gnn-filehub'),503);
        }
        return array('success'=>true,'message'=>__('Kaydınız alındı. E-postanızdaki doğrulama bağlantısını açtıktan sonra giriş yapabilirsiniz.','gnn-filehub'));
    }
    public function verify_email($request) {
        $uid=$request->get_param('user'); $token=$request->get_param('token');
        if(!is_scalar($uid) || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token))return FileHub_Security::error(__('Geçersiz doğrulama bağlantısı.','gnn-filehub'));
        $id=absint($uid);
        return FileHub_Security::locked('verify:'.$id,function()use($id,$token) {
            $hash=get_user_meta($id,'_filehub_email_token',true);
            if(!is_string($hash) || !$hash || !hash_equals($hash,hash('sha256',$token)) || (int)get_user_meta($id,'_filehub_email_expires',true)<time())return FileHub_Security::error(__('Doğrulama bağlantısı geçersiz veya süresi dolmuş.','gnn-filehub'));
            delete_user_meta($id,'_filehub_pending_email'); delete_user_meta($id,'_filehub_email_token'); delete_user_meta($id,'_filehub_email_expires');
            return new WP_REST_Response(array('success'=>true,'message'=>__('E-posta doğrulandı. Hesap sayfasından giriş yapabilirsiniz.','gnn-filehub')),200);
        });
    }
}
