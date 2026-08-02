# GNN FileHub — WP Core Native & Modern Yönetim Paneli Şartnamesi

**Tarih:** 2026-08-02  
**Konsept:** %100 WordPress Çekirdek (Core) Uyumlu, Harici Paket Gerektirmeyen, Modern Toggle Butonlu ve Depolama Analizli Yönetim Paneli  
**İlke:** WordPress Admin CSS standartları (`.wrap`, `.card`, `.wp-list-table`, `.form-table`) ve CSS Değişkenleri (`var(--wp-admin-theme-color)`) kullanılarak modern kartlar, toggle switch'ler ve depolama sayaçları tasarımı. Dışarıdan ağır CSS/JS kütüphaneleri **alınmaz**, böylece WP güncellendikçe uyum bozulmaz ve arayüz kullanıcının seçtiği WP Admin Renk Temasına (Blue, Midnight, Coffee vb.) otomatik adapte olur.

---

## 1. Yönetim Paneli Yetenekleri (Admin Dashboard Features)

### 📊 1. Sunucu ve Bulut Depolama Genel Durumu (Storage Overview Cards)
- **Yerel Depolama (Local Storage):** Toplam kullanılan alan, sunucudaki boş alan (HTML5 `<progress>` veya WP tema renkli dolgu çubuğu ile visualizer).
- **Cloudflare R2 (Free Tier Tracker):** R2 bucket'ında kullanılan toplam alan ve 10 GB ücretsiz kotadan ne kadar kaldığının canlı göstergesi.
- **Google Drive (Free Tier Tracker):** Google Drive'da kullanılan alan ve 15 GB kotadaki doluluk oranı.
- **Özet İstatistik Kartları:** Toplam Dosya Sayısı, Toplam İndirme Sayısı, Aktif Depolama Sağlayıcısı.

### 👥 2. Üye Klasör ve Kota Analizi (Per-User Storage Analytics)
- Her kullanıcının ne kadar dosya yüklediği ve klasörünün ne kadar alan kapladığı listesi (`wp_filehub_files` üzerinden anlık hesaplama).
- Kullanıcı bazlı depolama kotası sınırı (Örn: `100MB / 500MB %20 Dolu`).
- Kota aşımında otomatik yükleme engelleme switch'i.

### ⚙️ 3. Modern Toggle Switch'li Ayarlar Paneli (Modern Toggle Settings)
- **Geçiş (Toggle) Butonları:** WordPress Admin renk paletine tam uyumlu SAF CSS iOS/Material tarzı modern açma/kapama butonları.
- **Ayar Kategori Grupları:**
  - *Genel Ayarlar:* Eklenti aktifliği, Misafir yükleme izni, Maksimum dosya boyutu sınırı.
  - *Güvenlik & İzinler:* İzin verilen uzantılar (jpg, pdf, zip vb.), MIME kontrolü zorunluluğu, Otomatik virüs/şüpheli içerik taraması.
  - *Depolama Sürücü Seçimi:* Local / Cloudflare R2 / Google Drive arasında tek tıkla geçiş yapabilen Toggle kartları.

---

## 2. WP Core Temasına %100 Uyum Sağlayan CSS Toggle ve Kart Mimarısı

Hiçbir harici CSS yüklemeden, sadece 25 satırlık WordPress uyumlu pure CSS ile modern toggle switch yapısı:

```css
/* WP Core Native Modern Toggle Switch */
.filehub-switch {
  position: relative;
  display: inline-block;
  width: 44px;
  height: 24px;
}
.filehub-switch input {
  opacity: 0;
  width: 0;
  height: 0;
}
.filehub-slider {
  position: absolute;
  cursor: pointer;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: #ccc;
  transition: .3s;
  border-radius: 24px;
}
.filehub-slider:before {
  position: absolute;
  content: "";
  height: 18px;
  width: 18px;
  left: 3px;
  bottom: 3px;
  background-color: white;
  transition: .3s;
  border-radius: 50%;
}
.filehub-switch input:checked + .filehub-slider {
  background-color: var(--wp-admin-theme-color, #2271b1); /* WP Admin Renk Temasına Otomatik Uyum */
}
.filehub-switch input:checked + .filehub-slider:before {
  transform: translateX(20px);
}
```

---

## 3. AI Agent İçin "Gelişmiş Modern WP Core Master Prompt" (Kopyala - Yapıştır)

Projeyi sıfır klasörde modern toggle butonlu ve depolama analizli olarak başlatacak master prompt:

```markdown
### AI AGENT TASK INSTRUCTION: BUILD "GNN FILEHUB" WITH MODERN WP-CORE DASHBOARD

You are a Principal WordPress Core Architect.
Your task is to build a lightweight, ultra-low maintenance, enterprise-grade WordPress File Hub plugin from scratch in a clean directory.

MANDATE & DESIGN PHILOSOPHY:
1. MAXIMIZE WORDPRESS CORE COMPATIBILITY: Use WP native APIs, WP Admin CSS (.wrap, .card, .wp-list-table, .form-table), WP REST API (`WP_REST_Controller`), and WP Post Meta for attachments.
2. MODERN UI WITHOUT EXTERNAL FRAMEWORK BLOAT: Do NOT load Tailwind or Bootstrap. Implement a modern dashboard using WP native `.card` grid layout, custom pure CSS toggle switches utilizing WP theme CSS variables (`var(--wp-admin-theme-color)`), and native HTML5 progress meters. This ensures 100% theme compatibility and zero breakage when WP updates.
3. ZERO EXTERNAL COMPOSER PACKAGES: Implement Cloudflare R2 (S3 SigV4) and Google Drive API v3 using WordPress core `wp_remote_post()` and `wp_remote_request()` (WP HTTP API).

PANEL & FEATURE SPECIFICATION:

1. Dashboard & Analytics Screen ("FileHub - Genel Bakış"):
- Storage Overview Cards: Visual progress meters for Local Disk, Cloudflare R2 (10GB Free Tier tracker), and Google Drive (15GB Free Tier tracker).
- User Folder Analytics Table: Display list of users, number of uploaded files, total disk usage per user, quota usage percentage bar, and quota management.
- Quick Stats: Total Files, Total Downloads, Active Storage Engine indicator.

2. Modern Toggle Settings Screen ("FileHub - Ayarlar"):
- Custom pure CSS Toggle Switches for boolean options:
  - Enable/Disable Guest Uploads
  - Enable Strict MIME Validation
  - Enable Auto Unique Filename Renaming
  - Storage Engine Selector (Local / Cloudflare R2 / Google Drive)
- Extension & MIME Whitelist Tag/Input fields.
- API Key inputs for R2 (Account ID, Access Key, Secret Key, Bucket) and Google Drive (Client ID, Refresh Token, Folder ID).

3. File Manager & Shortcodes:
- `[filehub_uploader]`: Clean HTML5 Drag & Drop form with live MB/s speed, progress bar, ETA, and native JS `fetch()`.
- `[filehub_manager]`: User file list using WP styled `.widefat.striped` table with pagination, search box, preview modal, and secure REST download links.

Generate code structure:
gnn-filehub.php
inc/
  class-filehub-core.php
  class-filehub-rest-api.php
  class-filehub-attachment.php
  class-filehub-admin.php (Generates Modern WP Native Dashboard & Settings with CSS Toggles)
  class-filehub-shortcodes.php
  storage/
    class-storage-interface.php
    class-storage-local.php
    class-storage-r2.php
    class-storage-gdrive.php
assets/
  css/filehub-admin.css (Contains pure CSS toggle switches and WP card layout styles)
  js/filehub-public.js

Begin by creating `gnn-filehub.php` and the core architecture.
```
