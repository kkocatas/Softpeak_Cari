# Softpeak Cari — kurulum rehberi

Personel ve müşteri faturaları, projeler, ödeme/tahsilatlar ve cari bakiyeler için web uygulaması.
Veriler sizin MySQL veritabanınızda, fatura PDF'leri sunucunuzdaki bir klasörde durur.

**Gereken:** PHP 8.1 veya üstü (pdo_mysql, fileinfo, mbstring eklentileri), MySQL 5.7+ / MariaDB 10.3+, SSL sertifikası.

> **Plesk kullanıyorsanız (ör. isimtescil.net):** aşağıdaki adımlar aynı, yalnızca adlar farklı.
> Web kök dizini `public_html` değil `httpdocs`; site ana dizini `/var/www/vhosts/softpeak.com.tr`.
> Veritabanı: Web Siteleri ve Alan Adları > Veritabanları > **Veritabanı Ekle** (host: `localhost`).
> PDF klasörü: Dosya Yöneticisi'nde en üst dizinde, `httpdocs`'un yanında `softpeak-faturalar` klasörünü açın; `pdf_dir` = `'auto'` bırakın.
> Uygulama klasörü: `httpdocs/cari`. Yükleme sınırı: PHP Ayarları > `upload_max_filesize` 25M, `post_max_size` 26M.
> **Windows (IIS) sunucu** ise (yollar `C:\Inetpub\vhosts\...` biçimindeyse): `.htaccess` yerine pakette gelen `web.config` geçerlidir, ayrıca bir şey yapmanız gerekmez. Uygulama `softpeak-faturalar` klasörünü açamazsa PDF'leri kendi içindeki `pdf-arsiv` klasöründe tutar; bu klasör `web.config` ile dışarıdan erişime kapalıdır. Yedek alırken `httpdocs/cari/pdf-arsiv` klasörünü de dahil edin.
> PHP sürümü: PHP Ayarları'nda 8.2 veya 8.3 seçin (8.0 da çalışır ama artık güvenlik güncellemesi almıyor).

## 1. Veritabanını oluşturun

cPanel > **MySQL Veritabanları**:

1. Yeni veritabanı oluşturun, ör. `cari` (cPanel başına kullanıcı adınızı ekler: `kullaniciadi_cari`).
2. Yeni kullanıcı oluşturun, güçlü bir şifre verin.
3. Kullanıcıyı veritabanına ekleyin, **TÜM AYRICALIKLAR**'ı işaretleyin.

## 2. PDF klasörünü oluşturun

cPanel > **Dosya Yöneticisi**'nde ana dizininizde (public_html'in yanında, **içinde değil**) `softpeak-faturalar` adında bir klasör açın.
Tam yolu not edin: genellikle `/home/CPANELKULLANICI/softpeak-faturalar`.

> PDF'lerin public_html dışında durması önemli. İçeride olursa, dosya adını tahmin eden biri faturayı giriş yapmadan indirebilir. Uygulama PDF'leri yalnızca giriş yapmış kullanıcılara verir.

## 3. Dosyaları yükleyin

1. Zip'i bilgisayarınızda açın.
2. Dosya Yöneticisi'nde `public_html/cari` klasörünü oluşturun (uygulama `https://softpeak.com.tr/cari` adresinde çalışır; isterseniz başka bir ad ya da alt alan adı kullanabilirsiniz).
3. İçindeki her şeyi bu klasöre yükleyin. `.htaccess` gizli dosyadır; Dosya Yöneticisi'nde Ayarlar > "Gizli dosyaları göster" ile görünür.

## 4. Ayarları yapın

`config.sample.php` dosyasını kopyalayıp adını `config.php` yapın, düzenleyin:

| Ayar | Ne yazılır |
|---|---|
| `db_name`, `db_user`, `db_pass` | 1. adımdaki veritabanı bilgileri |
| `pdf_dir` | 2. adımdaki tam yol |
| `softpeak_vkn` | Softpeak'in VKN'si (toplu PDF aktarımında gelen/kesilen ayrımı için) |
| `setup_key` | Uzun, rastgele bir metin; yalnızca kurulumda bir kez kullanılır |

## 5. Kurulumu çalıştırın

1. Tarayıcıda `https://softpeak.com.tr/cari/setup.php` adresini açın.
2. Kurulum anahtarını, kendi kullanıcı adı ve şifrenizi, isterseniz mali müşavirin hesabını girin, **Kur**'a basın.
3. "Kurulum tamamlandı" yazısını görünce **`setup.php` dosyasını sunucudan silin.**

Giriş adresi: `https://softpeak.com.tr/cari/`

## 6. HTTPS ve yükleme sınırı

- cPanel > **SSL/TLS Status** bölümünden ücretsiz sertifikanın etkin olduğunu kontrol edin. Sonra `.htaccess` içindeki HTTPS yönlendirme satırlarının başındaki `#` işaretlerini kaldırın.
- Büyük PDF'ler için cPanel > **PHP Sürümünü Seç** (veya MultiPHP INI Düzenleyici) > `upload_max_filesize` = `25M`, `post_max_size` = `26M` yapın. Arşiv ekranı geçerli sınırı gösterir.

## Kullanım

- **Yönetici** her şeyi ekler, düzenler, siler. **Salt okunur** kullanıcı (mali müşavir) her ekranı görür, PDF'leri açıp indirir, ekstre yazdırır, CSV alır; hiçbir şeyi değiştiremez.
- **Fatura PDF'i:** Fatura penceresinde "PDF seç". Yeni faturada PDF seçince fatura no, tarih, matrah, KDV ve VKN'ye göre cari boş alanlara yazılır.
- **Geçmiş faturalar:** "PDF arşiv yükle" ekranına PDF'leri sürükleyin. Her dosyanın bilgileri PDF içinden okunur, VKN'si kayıtlı bir cariyle eşleşirse cari ve fatura türü kendiliğinden seçilir. Satırları kontrol edip "Kaydet"e basın. Aynı cari için aynı fatura numarası ikinci kez kaydedilmez.
  - Okuma GİB e-Fatura / e-Arşiv PDF'lerinde en iyi sonucu verir. Taranmış (resim) PDF'lerde metin olmadığı için bilgileri elle girersiniz; PDF yine eklenir.
  - Önce müşterileri ve personeli VKN'leriyle ekleyin; eşleşme bu sayede çalışır. Eşleşmeyen VKN için satırda "cari olarak ekle" bağlantısı çıkar.
- **Ödemeleri faturalara bağlama:** Ödeme/tahsilat penceresinde carinin açık faturaları listelenir; tutar eskiden yeniye otomatik dağıtılır, isterseniz elle değiştirirsiniz. Bir fatura birden çok ödemeyle kapanabilir; bir faturaya dağıtılan toplam fatura tutarını aşamaz (sunucu da kontrol eder). Faturalar "Açık / Kısmi / Ödendi" durumuyla görünür. Faturaya bağlanmamış eski ödemeler için cari ekstresinde "Faturalarla eşleştir" düğmesi çıkar.
- **Raporlar:** Personel veya müşteri bazında, seçilen tarih aralığında net (KDV hariç), KDV, KDV dahil, ödenecek, ödenen ve kalan tutarlar; satıra tıklayınca faturaları açılır. CSV olarak indirilebilir.
- **Yazdırma:** Her listede ve cari ekstresinde "Yazdır" düğmesi var; PDF'ler "Aç / yazdır" ile tarayıcıda açılır.
- **İşlem kaydı:** Ayarlar ekranında kimin neyi ne zaman eklediği, değiştirdiği, sildiği görünür.

## Yedekleme

Faturalarınız bu sunucuda tek kopya. cPanel > **Yedekleme** (veya JetBackup) ile hem veritabanını hem `softpeak-faturalar` klasörünü düzenli yedekleyin; ayda bir yedeği bilgisayarınıza da indirin.

## Dosyalar

| Dosya | Görevi |
|---|---|
| `index.php` | Giriş ekranı ve uygulama |
| `api.php` | Veri ve PDF işlemleri (oturum, yetki ve CSRF kontrollü) |
| `setup.php` | Tek seferlik kurulum; kurulumdan sonra silin |
| `lib/schema.sql` | Veritabanı tabloları (phpMyAdmin'de elle de çalıştırılabilir) |
| `assets/` | Arayüz, logo ve PDF okuyucu (pdf.js, Apache 2.0 lisanslı) |
