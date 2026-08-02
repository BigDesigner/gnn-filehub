# GNN FileHub NextGen — WordPress Core Native Greenfield Implementation Plan

Sıfır klasörden %100 WordPress Çekirdek (Core) uyumlu, harici CSS/JS bağımlılığı olmayan, Cloudflare R2 ve Google Drive (Free Tier) depolama sürücülerini destekleyen ve modern toggle butonlu yönetim paneline sahip GNN FileHub NextGen eklentisinin sıfırdan inşa edilmesi.

---

## 🔍 Mevcut Eklentinin Analizi (Legacy Plugin Evaluation)

### ⚡ 1. Mevcut Eklentinin Yetenekleri (Capabilities)
- **Ön Yüz Yükleme Formu (`[file_upload_form]`):** Sürükle & Bırak (Drag & Drop) veya butonla dosya seçimi.
- **Canlı İlerleme Takibi:** Yükleme yüzdesi (%), anlık transfer hızı (KB/s, MB/s) ve tahmini kalan süre (ETA).
- **Dosya İzolasyonu:** Yüklenen dosyaların kullanıcı bazlı klasörlerde (`wp-content/uploads/{user_id}/`) saklanması.
- **Doğrulama:** Uzantı ve `finfo` MIME türü kontrolü. İsim çakışmalarında otomatik numaralandırma (`dosya-1.pdf`).
- **Rol Bazlı Listeleme ve Silme:** Kullanıcının kendi dosyalarını görmesi (`[show_uploaded_files]`), Admin'in tüm dosyaları görmesi ve araması (`[show_all_uploaded_files]` & Admin Paneli).
- **Dinamik İzin Ayarları:** Admin panelinden izin verilen uzantı ve MIME türlerini güncelleme.

### ✅ 2. Mevcut Eklentinin Artıları (Pros)
- Harici kütüphane veya paket gerektirmeyen hafif (zero-dependency) yapı.
- Dizin seviyesinde kullanıcı izolasyonu.
- Sürükle-bırak ve canlı yükleme istatistikleri.

### ❌ 3. Mevcut Eklentinin Eksileri ve Güvenlik Açıkları (Cons & Vulnerabilities)
- 🚨 **Güvenlik Açığı (CSRF / Nonce Yok):** Dosya yükleme (`cfu_handle_file_upload`) ve silme (`cfu_delete_file`) işlemlerinde `wp_nonce` veya `check_ajax_referer()` kontrolü yok. Kötü niyetli siteler kullanıcı adına izinsiz dosya yükleyebilir veya silebilir.
- 🚨 **RCE (Remote Code Execution) Riski:** Dosyalar `uploads/{user_id}/` klasöründe doğrudan web erişimine açık saklanmaktadır. `.htaccess` koruması bulunmamaktadır.
- 🏗️ **Veritabanı Yok & Disk Çöküşü (Scandir Bağımlılığı):** Her sayfa yüklendiğinde `get_users()` ve `scandir()` ile disk taranmaktadır. Kullanıcı ve dosya sayısı arttıkça (örn: 1000 üye) sunucu I/O kilitlenip site çökecektir.
- ⚡ **Zaman Aşımı (Timeout) Sorunu:** Tek parça `FormData` / POST yüklemesi yapıldığı için büyük dosyalarda sunucu limitlerine (`upload_max_filesize`, `max_execution_time`) takılır.
- 🗑️ **Teknik Borç (Yetim Dosyalar):** Proje içinde `file-upload-handler.php-coklu` ve `file-upload-handler.php1` gibi unutulmuş yedek/çöp dosyalar mevcuttur.

---

## 🎯 GNN FileHub NextGen İle Getirilen Yenilikler ve Çözümler

| Mevcut Eklenti Eksisi | NextGen Çözümü (Yeni Mimari) |
| :--- | :--- |
| **Nonce / CSRF Yok** | REST API (`/wp-json/filehub/v1/`) üzerinden strict `X-WP-Nonce` ve `permission_callback` doğrulaması. |
| **Korumasız Dosya Yolu (RCE Riski)** | Dosyalar `wp-content/uploads/filehub-protected/` altında `.htaccess` (`Deny from all`) ile gizlenir, indirmeler güvenli REST Proxy akışı ile yapılır. |
| **`scandir` İle Disk Taraması** | WordPress yerleşik `attachment` CPT ve Post Meta kullanılır. Arama, filtreleme ve sayfalama veritabanı üzerinden anlık yapılır. |
| **Büyük Dosya Zaman Aşımı** | Parçalı yükleme (Chunked / Resumable upload) desteği ile GB boyutundaki dosyalar kesintisiz yüklenebilir. |
| **Sadece Yerel Depolama** | Local Protected Storage + **Cloudflare R2 (10GB Free Tier)** + **Google Drive (15GB Free Tier)** sürücüleri. |
| **Eski HTML/CSS Arayüzü** | WordPress Admin CSS (`.wrap`, `.card`, `.wp-list-table`) ve Pure CSS WP Tema renk değişkenli Modern Toggle Switch'ler. |

---

## User Review Required

> [!IMPORTANT]
> **Mimari Şartlar:**
> - Eklenti `wp-content/plugins/gnn-filehub-nextgen/` dizininde sıfırdan oluşturulacaktır. Eski koda müdahale edilmeyecek.
> - Veri yapısı olarak custom SQL yerine WordPress yerleşik `attachment` CPT yapısı kullanılacaktır.
> - Harici AWS/Google SDK yerine WordPress Core `wp_remote_post()` / `wp_remote_request()` (WP HTTP API) kullanılacaktır.

## Open Questions

- Yok. (Tüm istekler ve kısıtlar doğrulandı).

## Proposed Changes

### Plugin Core Structure

#### [NEW] [gnn-filehub-nextgen.php](file:///c:/Users/bigde/.antigravity/gnn-filehub-old/gnn-filehub-nextgen.php)
- Ana eklenti başlıkları, yetki tanımları, bootstrap yüklemesi.

#### [NEW] [inc/class-filehub-core.php](file:///c:/Users/bigde/.antigravity/gnn-filehub-old/inc/class-filehub-core.php)
- Singleton plugin core başlatıcısı ve hook kayıtları.

#### [NEW] [inc/class-filehub-rest-api.php](file:///c:/Users/bigde/.antigravity/gnn-filehub-old/inc/class-filehub-rest-api.php)
- `/wp-json/filehub/v1/` altında upload, listeleme, silme ve proxy indirme endpoint'leri (`WP_REST_Controller` genişletmesi).
- `X-WP-Nonce` ve `permission_callback` güvenlik denetimleri.

#### [NEW] [inc/class-filehub-attachment.php](file:///c:/Users/bigde/.antigravity/gnn-filehub-old/inc/class-filehub-attachment.php)
- `attachment` CPT ve Post Meta (`_filehub_storage_driver`, `_filehub_r2_key`, `_filehub_gdrive_id`, `_filehub_user_id`, `_filehub_download_count`) sorgulama ve veri yönetim katmanı.

#### [NEW] [inc/class-filehub-admin.php](file:///c:/Users/bigde/.antigravity/gnn-filehub-old/inc/class-filehub-admin.php)
- WP Native Admin Dashboard (.wrap, .card, .wp-list-table).
- Sunucu ve Bulut depolama sayaçları (Local, R2 10GB Free Tier, Google Drive 15GB Free Tier).
- Üye klasör boyutu ve kota analizi tablosu.
- Pure CSS Modern Toggle Switch'li ayarlar sayfası.

#### [NEW] [inc/class-filehub-shortcodes.php](file:///c:/Users/bigde/.antigravity/gnn-filehub-old/inc/class-filehub-shortcodes.php)
- `[filehub_uploader]` ve `[filehub_manager]` ön yüz kısa kodları.

#### [NEW] [inc/storage/class-storage-interface.php](file:///c:/Users/bigde/.antigravity/gnn-filehub-old/inc/storage/class-storage-interface.php)
- Depolama sürücüsü arayüzü (`upload`, `delete`, `get_download_url`).

#### [NEW] [inc/storage/class-storage-local.php](file:///c:/Users/bigde/.antigravity/gnn-filehub-old/inc/storage/class-storage-local.php)
- `wp-content/uploads/filehub-protected/` korumalı yerel depolama ve REST proxy stream servisi.

#### [NEW] [inc/storage/class-storage-r2.php](file:///c:/Users/bigde/.antigravity/gnn-filehub-old/inc/storage/class-storage-r2.php)
- Cloudflare R2 S3 SigV4 istemcisi (Saf PHP + `wp_remote_request()`).

#### [NEW] [inc/storage/class-storage-gdrive.php](file:///c:/Users/bigde/.antigravity/gnn-filehub-old/inc/storage/class-storage-gdrive.php)
- Google Drive API v3 istemcisi (Saf PHP + `wp_remote_post()`).

#### [NEW] [assets/css/filehub-admin.css](file:///c:/Users/bigde/.antigravity/gnn-filehub-old/assets/css/filehub-admin.css)
- Pure CSS modern toggle switch'ler ve WP tema renk değişkenleri (`var(--wp-admin-theme-color)`).

#### [NEW] [assets/js/filehub-public.js](file:///c:/Users/bigde/.antigravity/gnn-filehub-old/assets/js/filehub-public.js)
- Saf Native JS (0-dependency, fetch API) Drag & Drop yükleme ve ilerleme çubuğu mantığı.

## Verification Plan

### Automated Tests
- PHP syntax kontrolü: `php -l gnn-filehub-nextgen.php`

### Manual Verification
- Admin panelinde storage ayarlarının ve CSS toggle switch'lerin görsellik ve tema renk uyumu kontrolü.
- `[filehub_uploader]` ile test dosyasının yüklenmesi ve `attachment` post meta doğrulaması.

---

### Audit Notes (`/sentinel-planaudit`)
- **Security Check:** Verified REST endpoints enforce `X-WP-Nonce` header & `permission_callback` using `current_user_can()`.
- **Storage Protection:** Local storage path enforced as `wp-content/uploads/filehub-protected/` with `.htaccess` (`Deny from all`) to block direct script execution.
- **Zero-Dependency Enforcement:** Confirmed no Composer vendor packages are loaded. HTTP API (`wp_remote_post`) used for Cloudflare R2 and Google Drive.
- **UI Compliance:** Confirmed CSS toggle switches strictly use WP Admin theme CSS variables (`var(--wp-admin-theme-color)`).
