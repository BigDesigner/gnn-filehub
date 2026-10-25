<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded native-HTTP multipart transfer. No reusable client PUT credentials are issued. */
class FileHub_Storage_R2 implements FileHub_Storage_Interface {
    private $account_id, $access_key, $secret_key, $bucket;
    public function __construct(string $account_id='',string $access_key='',string $secret_key='',string $bucket='') {
        $this->account_id=$account_id?:get_option('filehub_r2_account_id','');
        $this->access_key=$access_key?:get_option('filehub_r2_access_key','');
        $this->secret_key=$secret_key?:get_option('filehub_r2_secret_key','');
        $this->bucket=$bucket?:get_option('filehub_r2_bucket','');
    }
    private function request(string $method,string $key,array $query=array(),string $body='',array $extra=array(),array $transport=array()) {
        if(!preg_match('/^[a-f0-9]{32}$/D',$this->account_id) || !$this->access_key || !$this->secret_key || !preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D',$this->bucket))return FileHub_Security::error(__('Cloudflare R2 ayarları geçersiz.','gnn-filehub'),503);
        $host=$this->account_id.'.r2.cloudflarestorage.com';
        $uri='/'.rawurlencode($this->bucket).'/'.implode('/',array_map('rawurlencode',explode('/',ltrim($key,'/'))));
        ksort($query); $qs=http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $date=gmdate('Ymd'); $amz=gmdate('Ymd\THis\Z'); $hash=hash('sha256',$body);
        $headers=array('host'=>$host,'x-amz-content-sha256'=>$hash,'x-amz-date'=>$amz);
        foreach($extra as $k=>$v)$headers[strtolower($k)]=$v;
        ksort($headers); $canonical='';
        foreach($headers as $k=>$v)$canonical.=$k.':'.trim($v)."\n";
        $signed=implode(';',array_keys($headers));
        $scope=$date.'/auto/s3/aws4_request';
        $canonical_request=implode("\n",array($method,$uri,$qs,$canonical,$signed,$hash));
        $signing=hash_hmac('sha256','aws4_request',hash_hmac('sha256','s3',hash_hmac('sha256','auto',hash_hmac('sha256',$date,'AWS4'.$this->secret_key,true),true),true),true);
        $signature=hash_hmac('sha256',"AWS4-HMAC-SHA256\n".$amz."\n".$scope."\n".hash('sha256',$canonical_request),$signing);
        $headers['Authorization']='AWS4-HMAC-SHA256 Credential='.$this->access_key.'/'.$scope.', SignedHeaders='.$signed.', Signature='.$signature;
        return wp_remote_request('https://'.$host.$uri.($qs!==''?'?'.$qs:''),array_merge(array('method'=>$method,'headers'=>$headers,'body'=>$body,'timeout'=>60,'redirection'=>0),$transport));
    }
    public function get_presigned_upload_url(string $filename,int $user_id,int $expires_seconds=3600) { return FileHub_Security::error(__('Doğrudan yükleme kaldırıldı; güvenli yükleme oturumu kullanın.','gnn-filehub'),410); }
    public function upload_file(array $file,int $user_id) { return FileHub_Security::error(__('Güvenli yükleme oturumu gereklidir.','gnn-filehub'),410); }
    public function verify_uploaded_object(string $key) {
        $r=$this->request('HEAD',$key); if(is_wp_error($r))return $r;
        if(wp_remote_retrieve_response_code($r)!==200)return FileHub_Security::error(__('Bulut dosyası doğrulanamadı.','gnn-filehub'),502);
        return array('file_size'=>(int)wp_remote_retrieve_header($r,'content-length'),'content_type'=>(string)wp_remote_retrieve_header($r,'content-type'));
    }
    public function transfer_step(string $path,array $session) {
        $t=$session['transfer']??array();
        if(!$t) {
            $key='uploads/'.$session['owner'].'/'.$session['id'];
            $r=$this->request('POST',$key,array('uploads'=>''),'',array('Content-Type'=>$session['mime']));
            if(is_wp_error($r))return $r;
            $xml=@simplexml_load_string(wp_remote_retrieve_body($r),'SimpleXMLElement',LIBXML_NONET);
            if(wp_remote_retrieve_response_code($r)!==200 || !$xml || empty($xml->UploadId))return FileHub_Security::error(__('R2 aktarımı başlatılamadı.','gnn-filehub'),502);
            return array('transfer'=>array('key'=>$key,'upload_id'=>(string)$xml->UploadId,'offset'=>0,'parts'=>array()));
        }
        if($t['offset']<$session['size']) {
            $fp=@fopen($path,'rb');
            if(!$fp)return FileHub_Security::error(__('Geçici dosya okunamadı.','gnn-filehub'),500);
            $ok=fseek($fp,$t['offset'])===0; $data=$ok?fread($fp,min(8*MB_IN_BYTES,$session['size']-$t['offset'])):false; fclose($fp);
            if(false===$data || strlen($data)!==min(8*MB_IN_BYTES,$session['size']-$t['offset']))return FileHub_Security::error(__('Geçici dosya eksik.','gnn-filehub'),500);
            $part=count($t['parts'])+1;
            $r=$this->request('PUT',$t['key'],array('partNumber'=>$part,'uploadId'=>$t['upload_id']),$data);
            if(is_wp_error($r))return $r;
            $etag=(string)wp_remote_retrieve_header($r,'etag');
            if(wp_remote_retrieve_response_code($r)!==200 || !$etag)return FileHub_Security::error(__('R2 parçası kaydedilemedi; yeniden denenebilir.','gnn-filehub'),502);
            $t['parts'][]=$etag; $t['offset']+=strlen($data);
            return array('transfer'=>$t);
        }
        $xml='<CompleteMultipartUpload>';
        foreach($t['parts'] as $i=>$etag)$xml.='<Part><PartNumber>'.($i+1).'</PartNumber><ETag>'.htmlspecialchars($etag,ENT_XML1|ENT_QUOTES,'UTF-8').'</ETag></Part>';
        $xml.='</CompleteMultipartUpload>';
        $r=$this->request('POST',$t['key'],array('uploadId'=>$t['upload_id']),$xml,array('Content-Type'=>'application/xml'));
        // HEAD also reconciles a successful completion whose HTTP response was lost.
        $meta=$this->verify_uploaded_object($t['key']);
        if(is_wp_error($meta) || $meta['file_size']!==$session['size'])return is_wp_error($r)?$r:FileHub_Security::error(__('R2 tamamlanması doğrulanamadı.','gnn-filehub'),502);
        return array('transfer'=>$t,'result'=>array('storage_driver'=>'r2','storage_key'=>$t['key'],'file_name'=>$session['filename'],'file_size'=>$meta['file_size']));
    }
    public function abort_transfer(array $t) {
        $r=$this->request('DELETE',$t['key'],array('uploadId'=>$t['upload_id']));
        if(is_wp_error($r))return $r;
        $code=wp_remote_retrieve_response_code($r);
        if(!in_array($code,array(200,204,404),true))return FileHub_Security::error(__('R2 oturumu iptal edilemedi.','gnn-filehub'),502);
        return $this->delete_file($t['key']);
    }
    public function delete_file(string $file_identifier) {
        $r=$this->request('DELETE',$file_identifier); if(is_wp_error($r))return $r;
        return in_array(wp_remote_retrieve_response_code($r),array(200,204,404),true) ? true : FileHub_Security::error(__('R2 dosyası silinemedi.','gnn-filehub'),502);
    }
    public function get_download_stream(string $file_identifier,string $mime_type='application/octet-stream',string $original_name='') {
        $root=FileHub_Security::private_dir(); if(is_wp_error($root))return $root;
        $meta=$this->verify_uploaded_object($file_identifier); if(is_wp_error($meta))return $meta;
        $slot=FileHub_Uploads::reserve_download($meta['file_size']); if(is_wp_error($slot))return $slot;
        $tmp=$slot['path'];
        $r=$this->request('GET',$file_identifier,array(),'',array(),array('stream'=>true,'filename'=>$tmp,'timeout'=>300,'limit_response_size'=>$meta['file_size']+1));
        if(is_wp_error($r) || wp_remote_retrieve_response_code($r)!==200 || !is_file($tmp) || filesize($tmp)!==$meta['file_size']) { @unlink($tmp); return FileHub_Security::error(__('Bulut dosyası indirilemedi.','gnn-filehub'),502); }
        return FileHub_Security::stream_file($tmp,$mime_type,$original_name?:basename($file_identifier),true);
    }
    public function get_file_url(string $file_identifier): string { return FileHub_Security::download_url_for_key('r2',$file_identifier); }
}
