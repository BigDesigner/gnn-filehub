# Proje ve Güvenlik İnceleme Raporu: f0d2946

**Tarih:** 24 Ekim 2026 · **Sürüm:** 1.6.1 · **Kapsam:** Çalışma ağacındaki tüm eklenti PHP/JS/CSS kaynakları, depolama sürücüleri, testler, yayın iş akışı ve proje şartnameleri.

**Karar:** Dosya gizliliği, kota ve yükleme doğrulaması sorunları giderilmeden bu sürümü hassas dosyalar için güvenli kabul etmiyorum. Bu yalnızca inceleme ve raporlama çalışmasıdır; uygulama kodu değiştirilmedi. Önceden değiştirilmiş `.tasks/pipeline.md` dosyasına dokunulmadı.

[Kolay okunur özet rapor](C:/Users/bigde/.antigravity/gnn-filehub/.memory-bank/audits/ozet-f0d2946.md)

## Bulgu Panosu

| Önem | Sayı | Başlıca sınıflar |
|---|---:|---|
| Kritik | 0 | Bu incelemede doğrulanmadı |
| Yüksek | 9 | Dosya gizliliği, yetki, kota, içerik doğrulama, kaynak tüketimi, veri kaybı |
| Orta | 15 | Silme/tamamlama, aktarım, oturum, ayar, test ve yayın hataları |
| Düşük | 5 | Performans, arayüz, kapsam eksikleri, savunma güçlendirme |

**Sınıflandırma notu:** Sayılar bağımsız CVE sayısı değildir; ilişkili kök nedenler düzeltme başlıklarında gruplandı. F08 sunucu yapılandırmasına, F07 kullanıcı rolüne bağlıdır. F27 için uzaktan istismar yolu doğrulanmadı.

## Yöntem ve Kanıt Sınırı

- 11 uygulama PHP dosyasında `php -l` ve ana JS dosyasında `node --check` başarılı. Kullanılan PHP: 8.5.8; eklentinin ilan ettiği minimum PHP 8.0 üzerinde ayrıca çalıştırılmadı.
- Gerçek eklenti sınıflarıyla **18/18 izole hata yeniden üretimi** başarılı. Buradaki başarı, kusurun yeniden üretildiğini ifade eder. WordPress yardımcıları ve HTTP yanıtları taklit edildi; bunlar tam WordPress, tarayıcı veya gerçek bulut entegrasyon testi değildir.
- Yerelde bulunan WordPress **7.0.2** kaynakları salt okunur incelendi: REST cookie nonce kontrolü, medya okuma izni, yazar filtresi, profil yetkisi, oturum nonce üretimi, kayıt kancaları ve güncelleme filtresi.
- Canlı WordPress veritabanı, R2/Drive hesabı, gerçek kullanıcı/dosya ve sunucu ayarları değiştirilmedi. Gerçek ağ yüklemesi, yük testi, Nginx/Apache HTTP erişim testi ve tarayıcı testi yapılmadı.
- İncelemede harici Composer/NPM bağımlılığı veya açıkça gömülü üretim sırrı bulunmadı. Bu ifade git geçmişi/çalışan sistem sırları için garanti değildir. Testlerdeki yapay Playground parolası üretim sırrı sayılmadı.
- [Yeniden üretim betiği](C:/Users/bigde/.antigravity/gnn-filehub/.memory-bank/audits/audit-f0d2946-checks.php) · [Kaydedilmiş sonuçlar](C:/Users/bigde/.antigravity/gnn-filehub/.memory-bank/audits/audit-f0d2946-checks.json)
- Tekrar çalıştırma: `php .memory-bank/audits/audit-f0d2946-checks.php`. Betik yalnızca kendisinin oluşturduğu rastgele geçici dizinle çalışır ve onu temizler.

## Ayrıntılı Bulgu Envanteri

### F01 — Misafir dosyaları anonim ziyaretçiler tarafından indirilebiliyor

- **Önem:** Yüksek
- **Konum:** `gnn-filehub/inc/class-filehub-rest-api.php:65, 114, 617–653`
- **Açıklama:** Misafir yüklemeleri post_author=0 ile kaydediliyor. Oturumsuz ziyaretçi de kullanıcı 0 olduğundan sahiplik karşılaştırmasını geçiyor; dosya ID’sini bilmesi yeterli. Misafir yükleme sonradan kapatılsa da mevcut misafir dosyaları için indirme kontrolü değişmiyor. R2 tamamlamasında da bütün misafirler uploads/0/ alanını paylaşıyor.
- **Düzeltme:** Misafir yüklemelerini yalnızca yöneticinin indireceği şekilde kapat veya sunucuda üretilmiş, dosyaya bağlı ayrı erişim belirteci kullan. 0 değerini sahiplik olarak kabul etme.
- **Kanıt / sınır:** T08: gerçek indirme işleyicisi anonim kullanıcıyı reddetmeden taklit bulut katmanına ulaştı.
- **Kod işareti:** `(int) $post->post_author !== get_current_user_id()`

### F02 — Özel dosya metaverisi standart WordPress medya API’sinden sızıyor

- **Önem:** Yüksek
- **Konum:** `gnn-filehub/inc/class-filehub-attachment.php:60–71`
- **Açıklama:** Dosyalar üst kayda bağlı olmayan inherit durumundaki normal attachment olarak oluşturuluyor. WordPress bu kayıtları okunabilir kabul ediyor. Eklentinin kendi /files sahiplik kontrolü, /wp/v2/media listesini ve tekil medya sorgusunu kapsamıyor: başlık, yazar ID’si ve tarih gibi bilgiler açığa çıkabilir. Bu bulgu dosya içeriğinin tamamının herkese açık olduğu anlamına gelmez.
- **Düzeltme:** FileHub kayıtlarını özel durum/tür altında tut; standart medya REST, yönetim ve herkese açık sorgularda sahiplik politikasını uygula. Mevcut kayıtlar için geçiş planı ekle.
- **Kanıt / sınır:** Yerel WordPress 7.0.2 çekirdeğinin WP_REST_Posts_Controller::check_read_permission() metodu ve resmi kaynakla doğrulandı; canlı HTTP testi yapılmadı.
- **Kod işareti:** `'post_status' => 'inherit'`

### F03 — MIME güvenlik anahtarı çalışmıyor; içerik doğrulanmıyor

- **Önem:** Yüksek
- **Konum:** `gnn-filehub/inc/class-filehub-admin.php:417, 443; inc/class-filehub-rest-api.php:116–153, 321–327, 433–478`
- **Açıklama:** filehub_strict_mime yalnızca ayarlarda gösterilip saklanıyor. Yükleme yolları dosya içeriğini finfo ile incelemiyor; istemcinin type/mime_type alanlarına güveniyor. PDF uzantılı düz metin, sıkı kontrol açıkken kabul edildi. Bu bir içerik doğrulama açığıdır; mevcut varsayılanlarla doğrudan uzaktan kod çalıştırma kanıtı değildir.
- **Düzeltme:** Sunucuda uzantı–gerçek MIME eşleştirmesi yap. R2 için yayımlanmadan önce karantina/doğrulama akışı kur; istemci Content-Type bilgisini güvenilir sayma.
- **Kanıt / sınır:** T03: strict_mime=1 iken PDF olmayan içerik 200 ile kaydedildi.
- **Kod işareti:** `FileHub_Attachment::create_attachment( $upload_result, $user_id, $file['type'] )`

### F04 — Depolama kotası aşılabiliyor

- **Önem:** Yüksek
- **Konum:** `gnn-filehub/inc/class-filehub-rest-api.php:107–164, 202–205, 308–352, 404–408, 465–478`
- **Açıklama:** Tek parça /upload yolunda kullanıcı kotası kontrolü yok. Parçalı yüklemede yalnızca mevcut kullanımın kotaya ulaşıp ulaşmadığına bakılıyor; eklenecek gerçek dosya boyutu hesaba katılmıyor. R2 son kontrolünde de eşzamanlı yüklemeler için atomik rezervasyon yok.
- **Düzeltme:** Tüm yollar için ortak, gerçek boyuta dayalı kota kontrolü ve kullanıcı bazında atomik rezervasyon uygula. Başarısız yüklemelerde rezervasyonu bırak.
- **Kanıt / sınır:** T01: dolu kotaya yeni dosya kabul edildi. T02: yalnızca 1 bayt boşluk kalmışken 4 baytlık dosya kabul edildi.
- **Kod işareti:** `$user_stats['total_bytes'] >= $user_stats['quota_bytes']`

### F05 — Tamamlanmayan parçalı yüklemeler diski doldurabilir

- **Önem:** Yüksek
- **Konum:** `gnn-filehub/inc/class-filehub-rest-api.php:173–245, 301–306; inc/class-filehub-core.php:151–190`
- **Açıklama:** Parça indeksi toplam parça sayısına göre doğrulanmıyor; toplam parça, yükleme oturumu ve biriken geçici bayt sayısı sınırlandırılmıyor. Kullanıcı çok sayıda yarım yükleme bırakabiliyor. Süre dolumu/çöp temizliği yok ve kota hesabı geçici parçaları görmüyor. Misafir yükleme açıkken saldırı için hesap gerekmiyor.
- **Düzeltme:** Sunucu üretimli yükleme oturumu, indeks/boyut sınırları, geçici alan rezervasyonu, hız sınırı ve zaman aşımı temizliği ekle.
- **Kanıt / sınır:** T11: total_chunks=2 iken part_999 diske yazıldı. Temizlik yalnızca başarılı birleştirme akışında bulunuyor.
- **Kod işareti:** `$part_file = $chunk_dir . '/part_' . $chunk_index`

### F06 — R2 imzalı bağlantıları boyut sınırını ve tamamlanmış dosyayı korumuyor

- **Önem:** Yüksek
- **Konum:** `gnn-filehub/inc/storage/class-storage-r2.php:94–165; inc/class-filehub-rest-api.php:372–488`
- **Açıklama:** PUT bağlantısı bir saat geçerli, yalnızca host imzalı ve gövde UNSIGNED-PAYLOAD. Tamamlama çağrısını atlayan kullanıcı kotaya yazılmayan nesneler bırakabilir. Tamamlanan nesne de aynı bağlantıyla süre dolana kadar daha büyük/farklı içerikle yeniden yazılabilir; HEAD kontrolü yalnızca kontrol anını kapsar.
- **Düzeltme:** Geçici anahtar ve sunucu taraflı yükleme kaydı kullan; doğrulanmış nesneyi istemcinin yazamadığı nihai anahtara taşı. Kullanılmamış oturumları temizle, bayt sınırını depolama girişinde uygula ve eşzamanlı rezervasyon ekle.
- **Kanıt / sınır:** İmzalanan alanlar koddan incelendi; bağlantının süresi dolana kadar yeniden kullanılabilmesi Cloudflare’ın resmi dokümanıyla doğrulandı. Gerçek bucket’a istek gönderilmedi.
- **Kod işareti:** `'X-Amz-SignedHeaders' => 'host'`

### F07 — Yönetici olmayan kullanıcı kendi kotasını yükseltebilir

- **Önem:** Yüksek
- **Konum:** `gnn-filehub/inc/class-filehub-admin.php:1246–1291; inc/class-filehub-core.php:75–96`
- **Açıklama:** Kota kaydı için manage_options yerine edit_user(self) yeterli. WordPress kişinin kendi profilini düzenlemesine izin veriyor. wp-admin’e girebilen Contributor/Author/Editor gibi roller profil ekranından nonce alıp kotasını artırabilir. Sıradan subscriber için mevcut admin yönlendirmesi bu ekranı engelliyor; bulgu tüm rollere koşulsuz uygulanmıyor.
- **Düzeltme:** Kota alanını gösterme ve kaydetme işlemlerinde yöneticiye özel yetki kullan; kişinin kendi profilini düzenleme hakkını kota yönetiminden ayır.
- **Kanıt / sınır:** T07: edit-self izniyle özel kota 999999 MB olarak kaydedildi. Yetki anlamı WordPress map_meta_cap koduyla karşılaştırıldı.
- **Kod işareti:** `if ( ! current_user_can( 'edit_user', $user_id ) )`

### F08 — Yerel dosya koruması sunucu yapılandırmasına bağlı

- **Önem:** Yüksek
- **Konum:** `gnn-filehub/inc/class-filehub-core.php:151–168, 195–207; inc/storage/class-storage-local.php:49–86`
- **Açıklama:** Korunan dosyalar web kökünün uploads dizininde ve koruma yalnızca .htaccess yazılmasına dayanıyor. Nginx .htaccess uygulamaz; Apache’de de ilgili override kapalıysa veya dosya yazılamazsa koruma sağlanmaz. Kullanıcı ID’si ve dosya adıyla doğrudan URL erişimi mümkün olabilir. Canlı sunucunun bu durumda olduğu doğrulanmadı.
- **Düzeltme:** Mümkünse dosyaları web kökü dışına taşı; aksi halde desteklenen sunucular için açık deny kuralı ve gerçek HTTP erişim testi zorunlu olsun. Koruma kurulamadığında yüklemeyi durdur.
- **Kanıt / sınır:** Koşullu dağıtım riski. WordPress Nginx dokümanı ve hata sonucu kontrol edilmeyen .htaccess yazımı ile doğrulandı.
- **Kod işareti:** `@file_put_contents( $htaccess_file, "Deny from all\n" )`

### F09 — Eşzamanlı aynı adlı dosyalar birbirinin üzerine yazılabilir

- **Önem:** Yüksek
- **Konum:** `gnn-filehub/inc/storage/class-storage-r2.php:160, 216; inc/storage/class-storage-local.php:60–85`
- **Açıklama:** R2 nesne anahtarı saniye çözünürlüklü time()+dosya adı ile üretiliyor; aynı kullanıcı aynı saniyede aynı adı yüklerse anahtar aynı. Yerel sürücüde de file_exists kontrolü ile taşıma arasında atomik koruma yok. Sonuç farklı kayıtların aynı dosyayı göstermesi veya veri kaybı olabilir.
- **Düzeltme:** Fiziksel anahtarı rastgele UUID ile üret, görünen adı ayrı sakla. Yerelde atomik özel oluşturma/taşıma, bulutta çakışmasız anahtar kullan.
- **Kanıt / sınır:** T05: art arda aynı adlı iki R2 isteği aynı anahtarı üretti. Yerel yarış kod akışıyla saptandı; eşzamanlı yük testi yapılmadı.
- **Kod işareti:** `$r2_key = 'uploads/' . $user_id . '/' . time() . '-' . $filename`

### F10 — R2 tamamlaması tekrar oynatılabiliyor ve dosya adı değiştirilebiliyor

- **Önem:** Orta
- **Konum:** `gnn-filehub/inc/class-filehub-rest-api.php:428–488`
- **Açıklama:** Tamamlama yalnızca anahtarın kullanıcı önekine ve HEAD yanıtına bakıyor. Anahtarın bekleyen bir oturuma ait olduğu veya daha önce kaydedilmediği doğrulanmıyor; filename uzantı listesi yeniden denetlenmiyor. Aynı nesne için birden fazla attachment/kota kaydı oluşabilir; kopyalardan biri silinince diğerleri bozulur. Tekrar tamamlamada kota aşımı dalı önceden kaydedilmiş nesneyi de silebilir.
- **Düzeltme:** Tek kullanımlık oturum ve benzersiz storage_key ilişkisi kur; tekrarlanan isteğe mevcut sonucu döndür. Ad, tip ve boyutu sunucudaki oturumla bağla.
- **Kanıt / sınır:** T04: aynı anahtar ve izinli olmayan renamed.exe adıyla iki ayrı kayıt oluştu.
- **Kod işareti:** `FileHub_Attachment::create_attachment( $upload_result, $user_id, $mime_type ?: $meta['content_type'] )`

### F11 — Silme ve hata geri alma eksikleri sahipsiz dosya bırakıyor

- **Önem:** Orta
- **Konum:** `gnn-filehub/inc/class-filehub-rest-api.php:153–155, 352–354, 478–480, 584–590; inc/storage/class-storage-r2.php:310–323; inc/storage/class-storage-gdrive.php:410–427`
- **Açıklama:** Bulut sürücüleri HTTP 403/500 yanıtını WP_Error olmadığı için başarılı sayıyor; REST katmanı sürücü sonucunu zaten yok sayarak DB kaydını siliyor. Yükleme sonrası DB hatasında dosya geri alınmıyor. WordPress Medya Kütüphanesi/kullanıcı silme yollarını özel depolamaya bağlayan delete_attachment kancası da yok.
- **Düzeltme:** Silme sonucunu HTTP durumuyla doğrula; fiziksel silme başarısızsa kaydı koru ve tekrar denenebilir hata göster. DB başarısızlığında geri alma ve WordPress yaşam döngüsü kancalarını ekle.
- **Kanıt / sınır:** T06: taklit bulut 403 döndürürken kayıt silindi ve success=true verildi. Diğer dallar statik incelendi.
- **Kod işareti:** `$driver->delete_file( $storage_key ); wp_delete_attachment( $attachment_id, true )`

### F12 — FileHub silme API’si sıradan medya kayıtlarını da silebiliyor

- **Önem:** Orta
- **Konum:** `gnn-filehub/inc/class-filehub-rest-api.php:555–588`
- **Açıklama:** Kaydın attachment olması ve sahiplik yeterli; FileHub metaverisi zorunlu değil. Yetkisi sonradan düşürülmüş ancak eski medya kayıtları kendi üzerinde kalan kullanıcı, WordPress delete_post yetkisi kontrol edilmeden bu API’den onları silebilir.
- **Düzeltme:** FileHub kaydı olduğunu zorunlu doğrula. Diğer WordPress medya kayıtlarını reddet; işlem kapsamına uygun capability kontrolü yap.
- **Kanıt / sınır:** T16: FileHub metaverisi olmayan attachment silindi.
- **Kod işareti:** `$driver_name = get_post_meta( $attachment_id, '_filehub_storage_driver', true ) ?: 'local'`

### F13 — Parçalı yükleme başarısız veya eksik kaydı başarılı gösterebilir

- **Önem:** Orta
- **Konum:** `gnn-filehub/inc/class-filehub-rest-api.php:221–261, 279–306; gnn-filehub/assets/js/filehub-public.js:475–520`
- **Açıklama:** move/copy ve dosya kilidi başarısızlıkları chunk_saved=200 ile sonuçlanabiliyor. JS yalnızca HTTP 200’e bakıyor; nihai attachment_id aramıyor. Birleştirmede okuma/kopyalama sonucu, toplam boyut eşitliği ve bütünlük doğrulanmıyor. Ayrıca her isteğin kilit dosyasını kaldırması, aynı dosyada bekleyen istekler varken Unix benzeri sistemlerde farklı kilit nesneleri oluşmasına yol açabilir.
- **Düzeltme:** Her disk/lock işlemini doğrula; parça yazımını aynı oturum kilidi altında yap. Kilit dosyası yaşam süresini güvenli yönet. Nihai kaydı istemcide zorunlu doğrula; boyut ve mümkünse checksum kontrolü ekle.
- **Kanıt / sınır:** T12: kaynak dosya yokken 200/chunk_saved döndü. Yarış ve istemci tamamlanma denetimi statik bulgudur.
- **Kod işareti:** `@copy( $chunk_file['tmp_name'], $part_file );`

### F14 — Büyük dosya aktarımı vaat edilen devam etme ve hata toleransını sağlamıyor

- **Önem:** Orta
- **Konum:** `gnn-filehub/assets/js/filehub-public.js:199–201, 348–350, 412–520; inc/storage/class-storage-gdrive.php:265–350; inc/storage/class-storage-r2.php:244–251, 344–350`
- **Açıklama:** Parçalar sabit 5 MB; upload_max_filesize=2 MB gibi sunucularda parçalama bile başarısız olur. Oturum kalıcılığı, eksik parça sorgusu, yeniden deneme ve iptal yok; tek hata sonrasında diğer parçalar gönderilmeye devam ediyor. R2 tek PUT kullanıyor. Google Drive aktarımı bir PHP isteği içinde sürüyor; ağ hatasında kaldığı yerden devam etmiyor. Bulut indirmesi dosyanın tamamını önce diske alıyor ve 120 saniye sınırına takılabilir; cURL olmayan R2 yüklemesi tamamını RAM’e alıyor.
- **Düzeltme:** Sunucu limitlerine uyarlanan parça boyutu, kalıcı oturum, yeniden deneme/iptal ve sağlayıcıya uygun multipart/resume ekle. Büyük indirmeleri kesintiye dayanıklı aktar; desteklenmeyen çalışma koşullarını açıkça bildir. R2 kurulum açıklamasına istemcinin gönderdiği Content-Type için gerekli AllowedHeaders ayarını da ekle; mevcut ekran yalnızca origin ve method örneği veriyor.
- **Kanıt / sınır:** Statik uçtan uca akış incelemesi; gerçek büyük dosya/bulut performans testi yapılmadı.
- **Kod işareti:** `const CHUNK_SIZE = 5 * 1024 * 1024`

### F15 — 100 dosyadan sonrası listelenemiyor ve aranamıyor

- **Önem:** Orta
- **Konum:** `gnn-filehub/inc/class-filehub-rest-api.php:502–522; gnn-filehub/assets/js/filehub-public.js:528–548, 629–639`
- **Açıklama:** Liste en fazla son 100 kaydı döndürüyor. page/offset ve toplam kayıt bilgisi yok; arama yalnızca tarayıcıya gelen bu kayıtları süzüyor. Eski dosyalar diskte kalırken kullanıcı onları bulup silemiyor.
- **Düzeltme:** Sunucuda sayfalama ve arama, toplam kayıt bilgisi ve arayüzde sayfa kontrolleri ekle.
- **Kanıt / sınır:** T15: page=2 gönderildiğinde sorguya sayfa veya offset aktarılmadı.
- **Kod işareti:** `'posts_per_page' => $per_page`

### F16 — Şifre değişiminden sonra sayfadaki REST oturumu bozuluyor

- **Önem:** Orta
- **Konum:** `gnn-filehub/inc/class-filehub-rest-api.php:706–723; gnn-filehub/assets/js/filehub-public.js:696–720`
- **Açıklama:** Şifre değişiminde yeni auth cookie/oturum üretiliyor; sayfa eski oturuma bağlı filehub_vars.nonce değerini kullanmayı sürdürüyor. Sayfa yenilenene kadar sonraki profil/API istekleri nonce hatası alabilir. Yeni cookie ile uyumlu nonce veya zorunlu yenileme yok.
- **Düzeltme:** Başarılı şifre değişiminden sonra sayfayı güvenli biçimde yenile veya yeni oturumla uyumlu nonce üretip bütün istemci kullanımını güncelle.
- **Kanıt / sınır:** WordPress wp_set_auth_cookie() ve wp_create_nonce() kaynakları ile istemci akışı karşılaştırıldı; tarayıcı oturum testi yapılmadı.
- **Kod işareti:** `wp_set_auth_cookie( $user->ID )`

### F17 — “0 = sınırsız kota” ayarı gerçekte 500 MB oluyor

- **Önem:** Orta
- **Konum:** `gnn-filehub/inc/class-filehub-admin.php:469–470; inc/class-filehub-attachment.php:221–230`
- **Açıklama:** Yönetim panelindeki açıklama ile hesap farklı. Varsayılan kota 0 seçilince get_user_quota() 500 MB döndürüyor; sınırsız seçildiğini düşünen kullanıcı kısıtlanıyor.
- **Düzeltme:** 0 değerinin anlamını tek yerde tanımlayıp API, profil, rezervasyon ve yönetim ekranında aynı uygula.
- **Kanıt / sınır:** T09: varsayılan kota 0 için 524288000 bayt döndü.
- **Kod işareti:** `return ( $default_quota_mb > 0 ? $default_quota_mb : 500 ) * 1024 * 1024`

### F18 — Google Drive ayarı değişince eski hesap/klasör önbelleği kullanılabiliyor

- **Önem:** Orta
- **Konum:** `gnn-filehub/inc/storage/class-storage-gdrive.php:37–44, 90–94, 366–370; inc/class-filehub-admin.php:979–980, 1222–1229`
- **Açıklama:** Klasör önbelleği yalnızca kullanıcı ID’sine bağlı; hesap veya hedef klasör değişince eski alt klasör bir gün kullanılabilir. Token ve kota önbellekleri de yapılandırmaya bağlı değil. OAuth yeniden bağlama yalnızca access token’ı temizliyor; ayar içe aktarma ilgili önbellekleri temizlemiyor.
- **Düzeltme:** Önbellek anahtarlarını hesap+üst klasör+yapılandırma kimliğine bağla ve ayar değişiminde ilgili kayıtları geçersiz kıl.
- **Kanıt / sınır:** T14: NEW_PARENT ayarına rağmen OLD_CHILD döndü.
- **Kod işareti:** `$cache_key = 'filehub_gdrive_user_folder_' . $user_id`

### F19 — Güncelleyicide filtre sözleşmesi ve önbellek hataları var

- **Önem:** Orta
- **Konum:** `gnn-filehub/inc/class-filehub-updater.php:59–77, 96–97, 176–190; inc/class-filehub-core.php:62–65`
- **Açıklama:** after_install(), ilgisiz eklentilerde bile ilk response yerine result döndürüyor; önceki filtrenin WP_Error sonucunu maskeleyebiliyor. destination dizisini değiştirmek çekirdeğin kendi sonucunu güncellemiyor ve move() hatası kontrol edilmiyor. Başarısız sorguyu false olarak önbellekleme, cache miss ile aynı olduğu için tekrar istek üretiyor. Güncelleyici yalnızca is_admin() içinde kuruluyor; normal cron bağlamında filtreler yok. Paket bulunamazsa kullanılan kaynak zip’i, eklenti dosyalarını depo kökü altındaki ek dizinde taşır.
- **Düzeltme:** Filtre dönüş sözleşmesini düzelt, önceki hataları koru; dizin düzenlemeyi uygun kaynak filtresinde yap. Hata için ayrı cache değeri kullan. Güncelleme filtrelerini cron’da da kur ve yalnızca doğrulanmış eklenti paketini kabul et.
- **Kanıt / sınır:** T13: iki çağrıda iki başarısız HTTP isteği. T18: başka eklentinin kurulum hatası kayboldu. Diğerleri WordPress upgrader kaynaklarıyla incelendi.
- **Kod işareti:** `return $result`

### F20 — İstek ve ayar türleri doğrulanmıyor; bozuk girdiler 500 üretebiliyor

- **Önem:** Orta
- **Konum:** `gnn-filehub/inc/class-filehub-rest-api.php:26–81, 663–672, 738–758; inc/class-filehub-admin.php:230–255`
- **Açıklama:** REST parametre şemaları yok. JSON gövdesi olmadan profile isteği array_key_exists(..., null) ile TypeError veriyor; dizi biçimli parola/kullanıcı adı da strlen/trim hatalarına yol açabilir. Normal ayar kaydında sanitize_callback bulunmuyor; içe aktarmanın yaptığı tür/driver kontrolü burada uygulanmıyor. Uzantı listesi büyük harf/nokta gibi yaygın yazımlara normalize edilmiyor.
- **Düzeltme:** REST args şemaları ve açık tür/boyut kontrolleriyle 400 döndür; ayarların ortak doğrulama/sanitizasyon katmanını normal kayıt ve import için kullan.
- **Kanıt / sınır:** T10: JSON yokken TypeError yeniden üretildi. Ayar kaydı ve diğer tür dalları statik incelendi.
- **Kod işareti:** `$has_display_name = array_key_exists( 'display_name', $params )`

### F21 — Üye kaydı standart kayıt güvenlik kancalarını atlıyor

- **Önem:** Orta
- **Konum:** `gnn-filehub/inc/class-filehub-rest-api.php:729–786; inc/class-filehub-attachment.php:101–145`
- **Açıklama:** Doğrudan wp_insert_user() çağrısı, register_new_user() akışındaki register_post ve registration_errors kontrollerini çalıştırmıyor. Bu kancalara bağlı CAPTCHA/kayıt politikaları bu REST yolunda uygulanmayabilir. Eklentide istek sınırı veya e-posta sahipliği doğrulaması da yok; kayıt açıldığında otomatik hesap ve yükleme bildirimi kötüye kullanımı riski artar. users_can_register kontrolü mevcut; kapalı kaydı açma açığı yok.
- **Düzeltme:** Kayıt politikalarını REST akışına bilinçli bağla; yükleme/kayıt hız sınırları ve gerekiyorsa e-posta doğrulama ekle. Sadece nonce eklemek bot sorununu çözmez.
- **Kanıt / sınır:** Yerel WordPress register_new_user() kancaları ile karşılaştırıldı; üçüncü taraf güvenlik eklentileriyle canlı uyumluluk testi yapılmadı.
- **Kod işareti:** `$user_id = wp_insert_user( array( ... ) )`

### F22 — Mevcut testler güncel sürümü doğrulamıyor; yayın kapısı yok

- **Önem:** Orta
- **Konum:** `.tests/test-real-playground-suite.php:7, 76–88, 146–156, 205–224, 294–327; .github/workflows/release.yml`
- **Açıklama:** Testler kaldırılmış /change-password ve handle_change_password() yolunu, eski filehub_page_register ayarını kullanıyor. set_json_params() standart WP_REST_Request metodu değil. Parça testi gerçek handler yerine birleştirme kodunu kendi içinde tekrar ediyor. Sabit kişisel Playground yolu ve kalıcı test kullanıcı/sayfaları var. Release iş akışında lint veya test adımı yok; eski “%100 geçti” raporu bu sürüme kanıt olamaz.
- **Düzeltme:** Taşınabilir, izole WordPress test ortamı kur; gerçek REST dispatch ve izin kontrollerini, misafir/çoklu kullanıcı, kota, bulut hata, yarış ve oturum senaryolarını kapsa. CI’da bunları yayın önkoşulu yap.
- **Kanıt / sınır:** Test kaynakları güncel API ve yerel WordPress sınıfıyla karşılaştırıldı. Durum değiştiren eski entegrasyon paketi çalıştırılmadı.
- **Kod işareti:** `$rest_api->handle_change_password( $request )`

### F23 — Yayın sürümü paket içeriğiyle ayrışabiliyor

- **Önem:** Orta
- **Konum:** `.github/workflows/release.yml:31–59; gnn-filehub/gnn-filehub-nextgen.php:6, 25–29; gnn-filehub/VERSION`
- **Açıklama:** Tag veya version_override yalnızca zip/release adını değiştiriyor; VERSION ve PHP eklenti başlığı aynı sürüme getirilmiyor/doğrulanmıyor. 1.7.0 adlı bir paket içeride 1.6.1 kalabilir; tekrar güncelleme uyarısı ve önbellek tutarsızlığı oluşur. Ayrıca version_override doğrudan shell betiğine gömülüyor; özel karakterler komut olarak yorumlanabilir. Bu ikinci yol workflow çalıştırma yetkisi gerektirir, anonim saldırı değildir.
- **Düzeltme:** Semver doğrulaması ve tag–VERSION–başlık eşitliği kontrolünü zorunlu kıl. Girdiyi shell koduna gömmek yerine ortam değişkeni üzerinden veri olarak al; üretilecek paketi test et.
- **Kanıt / sınır:** Statik workflow incelemesi. Mevcut başlık ve VERSION şu an ikisi de 1.6.1; mevcut sürüm farkı varmış gibi raporlanmadı.
- **Kod işareti:** `VERSION="${{ github.event.inputs.version_override }}"`

### F24 — Yükleme arayüzü ve indirme sayacı yanıltıcı durum üretebiliyor

- **Önem:** Düşük
- **Konum:** `gnn-filehub/assets/js/filehub-public.js:209–238, 385–390, 537–547; inc/class-filehub-rest-api.php:637; inc/class-filehub-attachment.php:331–334`
- **Açıklama:** Yeni sürükle-bırak önceki kuyruk bitmeden ayrı kuyruk başlatıyor; önceki 1200 ms zamanlayıcı yeni yüklemenin göstergesini gizleyebilir. Listeleme API hatalarında ekran Yükleniyor durumunda kalıyor. İndirme sayacı dosyanın varlığı/başarılı aktarımı doğrulanmadan artıyor ve paralel artışlar kaybolabiliyor.
- **Düzeltme:** Tek yönetilen kuyruk ve iptal edilen zamanlayıcı kullan; API hatalarını görünür göster. İndirme metriğinin neyi saydığını tanımlayıp atomik güncelle.
- **Kanıt / sınır:** Statik UI ve sayaç akışı incelemesi; tarayıcı görsel testi yapılmadı.
- **Kod işareti:** `setTimeout(() => { if (progressBar) progressBar.style.display = 'none'; }, 1200)`

### F25 — Her parçada tüm dosyaların taranması büyüdükçe pahalılaşıyor

- **Önem:** Düşük
- **Konum:** `gnn-filehub/inc/class-filehub-attachment.php:249–276, 287–319; inc/class-filehub-rest-api.php:202–203`
- **Açıklama:** Kullanıcı istatistiği tüm attachment ID’lerini ve metalarını dolaşıyor; her yükleme parçasında yeniden çalışıyor. Sistem istatistiği bütün kayıtları nesne olarak yüklüyor. Dosya ve parça sayısı arttıkça CPU/DB/bellek maliyeti büyür.
- **Düzeltme:** Toplamları güvenli biçimde toplu SQL/sayaç ile hesapla, rezervasyonla tutarlı önbellekle ve periyodik uzlaştırma ekle.
- **Kanıt / sınır:** Statik karmaşıklık incelemesi; ölçülmüş kapasite sınırı iddiası yok.
- **Kod işareti:** `'posts_per_page' => -1`

### F26 — Şartnamede ve arayüzde görünen bazı özellikler eksik

- **Önem:** Düşük
- **Konum:** `gnn-filehub/inc/class-filehub-admin.php:338–411, 418, 453–456; .specs/gnn_filehub_wp_native_spec.md; gnn-filehub/README.md`
- **Açıklama:** Otomatik yeniden adlandırma anahtarı sürücülerce okunmuyor. Şartnamedeki kullanıcı bazlı analiz tablosu, önizleme ve virüs/şüpheli içerik taraması uygulanmamış. Google Drive genel bakışı sabit 15 GB gösterirken ana WP paneli gerçek kota API’sini kullanıyor. README 2 MB parça ve resumable aktarım anlatıyor; kod 5 MB ve kalıcı devam etme yok.
- **Düzeltme:** Tamamlanan davranışı arayüz/dokümanla eşleştir; uygulanmayan anahtarları kaldır veya işlevini tamamla. Kota görünümünü tek veri kaynağına bağla. İsteğe bağlı özellikleri açık backlog olarak tut.
- **Kanıt / sınır:** Kaynak genelinde kullanım ve UI–API bağlantıları tarandı; eksik özellikler güvenlik açığı sayılmadı.
- **Kod işareti:** `$auto_rename = get_option( 'filehub_auto_rename', '1' )`

### F27 — Yerel dizin sınırı kontrolü ortak önekli kardeş dizini kabul ediyor

- **Önem:** Düşük
- **Konum:** `gnn-filehub/inc/storage/class-storage-local.php:40–42, 105–112, 130–137`
- **Açıklama:** strpos(real_target, real_base) kontrolü dizin ayırıcı sınırını aramıyor: filehub-protected-sibling da filehub-protected önekiyle başlar. Depolama anahtarına traversal girebilirse kardeş dizinden okuma/silme mümkün. İncelenen normal yükleme API’sinden bu anahtarı saldırganın yazabildiği bir yol doğrulanmadı; bu nedenle uzaktan istismar kanıtı değil, savunma eksikliği olarak sınıflandı.
- **Düzeltme:** Normalize edilmiş gerçek yolun base + DIRECTORY_SEPARATOR içinde kaldığını doğrula; geçici dosya kontrolünde de aynı sınırı ve sunucu üretimli dosyayı zorunlu kıl.
- **Kanıt / sınır:** T17: yalnızca yapay test dizini içinde ../filehub-protected-sibling/probe.txt silinebildi.
- **Kod işareti:** `strpos( $real_target, $real_base ) === 0`

### F28 — Misafir kota hesabı bütün kullanıcıların dosyalarını sayıyor

- **Önem:** Orta
- **Konum:** `gnn-filehub/inc/class-filehub-attachment.php:249–263; inc/class-filehub-rest-api.php:170, 203, 378, 404`
- **Açıklama:** get_user_stats(0), WordPress’e author=0 gönderiyor. WP_Query bunu kullanıcı 0 filtresi olarak uygulamıyor; yazar kısıtı kalkıyor. Sonuç diğer üyelerin toplam kullanımının misafir kotasına dahil olması ve misafir yüklemelerinin beklenmedik engellenmesi.
- **Düzeltme:** Misafir kimliğini ve kotasını açık tasarla; gerçek author=0 koşulu veya doğru author__in sorgusu kullan. Misafir kotasını tüm sistem kullanımıyla karıştırma.
- **Kanıt / sınır:** Yerel WordPress 7.0.2 class-wp-query.php:2388–2416 ile doğrulandı. Test taklidi bu çekirdek davranışını bağımsız kanıt olarak sunmuyor.
- **Kod işareti:** `'author' => $user_id`

### F29 — Çeviri, erişilebilirlik ve giriş yönlendirmesi eksikleri var

- **Önem:** Düşük
- **Konum:** `gnn-filehub/assets/js/filehub-public.js:116–132, 554–583; inc/class-filehub-shortcodes.php:230–240, 374–381; inc/class-filehub-core.php:126–138; inc/class-filehub-admin.php:37–45`
- **Açıklama:** JS mesajları Türkçe sabit metin; POT güncel arayüzün tamamını kapsamıyor. Yükleme alanı tıklanabilir div olarak klavye odağı/tuş davranışı sunmuyor. Giriş URL’sine eklenen redirect_to formda okunmayıp mevcut sayfa kullanılıyor. Menü adı proje kuralındaki FileHub yerine GNN Filehub.
- **Düzeltme:** JS çeviri dizgesi, klavye ile kullanılabilir yükleme düğmesi ve güvenli redirect_to desteği ekle; menü/çeviri dosyasını güncelle.
- **Kanıt / sınır:** Statik arayüz incelemesi; ekran okuyucu ve tema matrisi testi yapılmadı.
- **Kod işareti:** `'redirect' => get_permalink()`

## Proje Sınır Koşulları Denetimi

| Kural | Sonuç | Açıklama |
|---|---|---|
| REST yetkileri ve nonce | Kısmi | Cookie nonce denetimini WordPress çekirdeği yapıyor. Uygulamada ayrıca wp_verify_nonce olmamasını tek başına CSRF açığı olarak raporlamadım. Misafir sahipliği, kota yönetimi ve kapsam denetimleri sorunlu: F01, F07, F12. |
| Dosya adı, uzantı ve finfo MIME denetimi | İhlal | Dosya adı temizliği ve temel uzantı listesi var; gerçek içerik denetimi yok. R2 finalize adı yeniden doğrulanmıyor: F03, F10. |
| Sahiplik ve güvenli indirme | Kısmi | Üye dosyaları için özel REST yolunda sahip/yönetici kontrolü mevcut; misafir ve standart medya API’si açığı sürüyor: F01–F02. |
| Yerel korumalı dizin ve .htaccess | Kısmi | Dosya oluşturuluyor ancak yazma başarısı ve gerçek web erişim yasağı doğrulanmıyor: F08. |
| Harici paket kullanmama | Uygun | Composer/NPM bağımlılığı yok. |
| Bulut işlemlerini yalnızca WP HTTP API ile yürütme | Sapma | R2 upload dalı doğrudan cURL kullanıyor. Bu mimari sözleşme sapmasıdır; tek başına güvenlik açığı sayılmadı. |
| WP yönetim teması ve menü | Kısmi | CSS değişkenleri kullanılıyor; menü başlığı belirlenen kısa ada uymuyor. Tema erişilebilirliği görsel olarak test edilmedi. |
| DB → API → istemci → UI → geri bildirim | İhlal | MIME/yeniden adlandırma ayarları işlevsiz; listeleme ve yükleme geri bildirimi eksik: F03, F13, F15, F24, F26. |

## Önerilen Düzeltme Sırası ve Kabul Kontrolleri

1. **Gizlilik ve yetki:** F01–F03, F07–F08, F12. İki farklı üye, misafir ve yöneticiyle hem özel API hem standart medya API’sini test et; korunan dosyaya doğrudan HTTP erişimi reddedilmeli.
2. **Yükleme bütünlüğü ve kaynak sınırları:** F04–F06, F09–F10, F13–F14, F17, F28. Kotanın son baytı, eşzamanlı yükleme, aynı ad, geçersiz MIME, eksik/tekrar parça ve yeniden kullanılan R2 URL’si senaryolarını kapsa.
3. **Veri yaşam döngüsü:** F11, F18, F27. Bulut 403/404/500, DB hatası, Medya Kütüphanesi silme ve hesap/klasör değişiminde veri ile kayıt uyumunu doğrula.
4. **Günlük kullanım:** F15–F16, F20–F21, F24–F26, F29. 101+ dosyada liste/arama, şifre değişiminden sonraki ikinci istek, hatalı gövde, klavye kullanımı ve güvenlik eklentisi kayıt kancalarını test et.
5. **Yayın güveni:** F19, F22–F23. Güncel gerçek WordPress entegrasyonlarını CI kapısı yap; paket içindeki sürüm, dizin yapısı ve cron güncellemelerini doğrula.

## Doğru Çalışan veya Yanlış Pozitif Olarak Elenen Noktalar

- Üye dosyalarının özel indirme/silme yolunda sahiplik denetimi var; önceki denetimdeki “her üyenin dosyasını herkes indirebilir” bulgusu aynen tekrar edilmedi. Misafir 0 istisnası F01’de ayrıldı.
- Dosya listesinde yönetici olmayan kişinin `scope=all` isteği kendi dosyalarıyla sınırlandırılıyor.
- Ayar içe/dışa aktarma ve Google OAuth başlangıç/başarılı callback yollarında yönetici yetkisi ve nonce/state kontrolleri bulunuyor.
- İçe aktarma bilinen ayar anahtarlarıyla sınırlı; keyfi WordPress option yazma yolu görülmedi.
- Şifre değişimi mevcut şifreyi doğruluyor; üye kaydı `users_can_register` ayarını denetliyor.
- Mevcut VERSION ve eklenti başlığı 1.6.1 ile tutarlı. F23 gelecek paket üretimindeki kontrol eksikliğidir.
- SQL injection, doğrulanmış uzak kod çalıştırma veya üye girdisinden doğrulanmış DOM-XSS yolu bulunmadı. İç HTML üretimindeki kalan dinamik alanların kaçışını sıkılaştırmak yine de uygundur; bu bağımsız istismar bulgusu sayılmadı.

## Dış Kaynakla Doğrulanan Davranışlar

- Üst kaydı olmayan inherit attachment için okuma davranışı: [WordPress check_read_permission](https://developer.wordpress.org/reference/classes/wp_rest_posts_controller/check_read_permission/). F02’nin çekirdek davranışını destekler.
- REST cookie kimliğinde nonce doğrulaması: [WordPress rest_cookie_check_errors](https://developer.wordpress.org/reference/functions/rest_cookie_check_errors/). Ayrı wp_verify_nonce çağrısı yok diye otomatik açık sayılmamasının dayanağıdır.
- R2 imzalı URL’nin süresi dolana kadar tekrar kullanılabilmesi: [Cloudflare R2 presigned URLs](https://developers.cloudflare.com/r2/api/s3/presigned-urls/). F06’daki tekrar yazma riskini destekler.
- Nginx’in .htaccess dosyası uygulamaması: [WordPress Nginx yönetim kılavuzu](https://developer.wordpress.org/advanced-administration/server/web-server/nginx/). F08’in dağıtım koşulunu destekler.

**Son durum:** Uygulama kaynaklarında düzeltme yapılmadı. Rapor ve izole doğrulama kanıtları `.memory-bank/audits/` altında kaydedildi.

