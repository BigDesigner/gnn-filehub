<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FileHub_Storage_Local implements FileHub_Storage_Interface {
    private function same_content(string $a,string $b): bool {
        $first=FileHub_Security::file_hash($a); $second=FileHub_Security::file_hash($b);
        return is_string($first) && is_string($second) && hash_equals($first,$second);
    }
    private function prepare_parent(string $path) {
        $root=FileHub_Security::private_dir(); if(is_wp_error($root))return false;
        if(!wp_mkdir_p(dirname($path)))return false;
        $parent=realpath(dirname($path)); $base=realpath($root.'/objects');
        return $parent && $base && ($parent===$base || FileHub_Security::inside($parent,$base));
    }

    private function resolve( string $key, bool $existing = true ) {
        $root=FileHub_Security::private_dir();
        if(is_wp_error($root))return $root;
        if(!$key || strpos($key,chr(0))!==false || strpos($key,':')!==false || strpos($key,chr(92))!==false || $key[0]==='/' || array_intersect(explode('/',$key),array('','..','.')))return FileHub_Security::error(__('Geçersiz depolama anahtarı.','gnn-filehub'));
        $base=$root.'/objects';
        if(!is_dir($base) && !wp_mkdir_p($base))return FileHub_Security::error(__('Depolama oluşturulamadı.','gnn-filehub'),503);
        $path=$base.'/'.$key;
        if(!$existing)return $path;
        $real=realpath($path);
        return $real && is_file($real) && FileHub_Security::inside($real,realpath($base)) ? $real : FileHub_Security::error(__('Dosya bulunamadı.','gnn-filehub'),404);
    }

    public function upload_file( array $file, int $user_id ) {
        $root=FileHub_Security::private_dir(); if(is_wp_error($root))return $root;
        $source=realpath($file['tmp_name']??'');
        if(!$source || (!is_uploaded_file($source) && !FileHub_Security::inside($source,$root.'/sessions')))return FileHub_Security::error(__('Geçersiz yükleme kaynağı.','gnn-filehub'));
        $session=$file['session_id']??wp_generate_uuid4();
        if(!is_string($session) || !preg_match('/^[a-f0-9-]{36}$/D',$session))return FileHub_Security::error(__('Geçersiz yükleme oturumu.','gnn-filehub'));
        $key=$user_id.'/'.$session;
        $target=$this->resolve($key,false); if(is_wp_error($target))return $target;
        if(!$this->prepare_parent($target))return FileHub_Security::error(__('Depolama oluşturulamadı.','gnn-filehub'),503);
        // Deterministic session key makes retries safe; exclusive creation prevents overwrite.
        $fp=@fopen($target,'xb');
        if(!$fp) {
            if(!is_file($target) || filesize($target)!==filesize($source) || !$this->same_content($source,$target))return FileHub_Security::error(__('Depolama anahtarı çakıştı.','gnn-filehub'),409);
        } else {
            $in=@fopen($source,'rb');
            $ok=$in && FileHub_Security::copy_stream($in,$fp,filesize($source));
            if($in)fclose($in); fclose($fp);
            if(!$ok) { @unlink($target); return FileHub_Security::error(__('Dosya kaydedilemedi.','gnn-filehub'),500); }
        }
        @chmod($target,0600);
        return array('storage_driver'=>'local','storage_key'=>$key,'file_name'=>FileHub_Attachment::sanitize_upload_filename($file['name']),'file_size'=>filesize($target));
    }

    /** Preserve file IDs/keys while copying legacy web files into private storage. */
    public function migrate_key( string $key ) {
        $target=$this->resolve($key,false); if(is_wp_error($target))return $target;
        $uploads=wp_upload_dir(); $private=FileHub_Security::private_dir(); if(is_wp_error($private))return $private;
        $public=$uploads['basedir'].'/filehub-protected';
        $legacy=is_file($public.'/'.$key)?$public:$private.'/legacy';
        $old=realpath($legacy.'/'.$key); $base=realpath($legacy);
        if(!$old || !$base || !is_file($old) || !FileHub_Security::inside($old,$base))return FileHub_Security::error(__('Eski yerel dosya bulunamadı.','gnn-filehub'),404);
        if(!$this->prepare_parent($target))return FileHub_Security::error(__('Özel depolamaya geçiş yapılamadı.','gnn-filehub'),503);
        $out=@fopen($target,'xb');
        if($out) {
            $in=@fopen($old,'rb'); $ok=$in && FileHub_Security::copy_stream($in,$out,filesize($old));
            if($in)fclose($in); fclose($out);
            if(!$ok) { @unlink($target); return FileHub_Security::error(__('Dosya geçişi tamamlanamadı.','gnn-filehub'),500); }
        }
        $real=realpath($target);
        $private=FileHub_Security::private_dir();
        if(!$real || is_wp_error($private) || !FileHub_Security::inside($real,$private) || !$this->same_content($old,$real))return FileHub_Security::error(__('Geçiş bütünlüğü doğrulanamadı.','gnn-filehub'),500);
        @chmod($real,0600);
        if(!unlink($old))return FileHub_Security::error(__('Eski herkese açık dosya kaldırılamadı.','gnn-filehub'),503);
        $alternate=$private.'/legacy/'.$key;
        if(is_file($alternate) && $alternate!==$old && $this->same_content($alternate,$real))@unlink($alternate);
        clearstatcache();
        return $real;
    }

    public function delete_file( string $file_identifier ) {
        $migration=$this->migrate_key($file_identifier);
        if(is_wp_error($migration) && (int)($migration->get_error_data()['status']??500)!==404)return $migration;
        $path=$this->resolve($file_identifier);
        if(is_wp_error($path)) {
            // A legacy file must also be removed; never return success just because the new path is absent.
            $migrated=$this->migrate_key($file_identifier);
            if(is_wp_error($migrated))return (int)($migrated->get_error_data()['status']??500)===404 ? true : $migrated;
            $path=$migrated;
        }
        return @unlink($path) ? true : FileHub_Security::error(__('Dosya silinemedi.','gnn-filehub'),503);
    }

    public function get_download_stream( string $file_identifier, string $mime_type='application/octet-stream', string $original_name='' ) {
        $path=$this->resolve($file_identifier);
        if(is_wp_error($path))$path=$this->migrate_key($file_identifier);
        return is_wp_error($path) ? $path : FileHub_Security::stream_file($path,$mime_type,$original_name?:basename($path));
    }

    public function get_file_url( string $file_identifier ): string { return FileHub_Security::download_url_for_key('local',$file_identifier); }
}
