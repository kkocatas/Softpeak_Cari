# Cari Takip — kurulum rehberi

Personel ve müşteri faturaları, projeler, ödeme/tahsilatlar ve cari bakiyeler için web uygulaması.
Veriler sizin MySQL veritabanınızda, fatura PDF'leri sunucunuzdaki bir klasörde durur.

**Gereken:** PHP 8.1 veya üstü (pdo_mysql, fileinfo, mbstring eklentileri), MySQL 5.7+ / MariaDB 10.3+, SSL sertifikası.

> **Plesk kullanıyorsanız (ör. isimtescil.net):** aşağıdaki adımlar aynı, yalnızca adlar farklı.
> Web kök dizini `public_html` değil `httpdocs`; site ana dizini `/var/www/vhosts/ALANADINIZ.com`.
> Veritabanı: Web Siteleri ve Alan Adları > Veritabanları > **Veritabanı Ekle** (host: `localhost`).
> PDF klasörü: Dosya Yöneticisi'nde en üst dizinde, `httpdocs`'un yanında `cari-belgeler` klasörünü açın; `pdf_dir` = `'auto'` bırakın.
> Uygulama klasörü: `httpdocs/cari`. Yükleme sınırı: PHP Ayarları > `upload_max_filesize` 25M, `post_max_size` 26M.
> **Windows (IIS) sunucu** ise (yollar `C:\Inetpub\vhosts\...` biçimindeyse): `.htaccess` yerine pakette gelen `web.config` geçerlidir, ayrıca bir şey yapmanız gerekmez. Uygulama `cari-belgeler` klasörünü açamazsa PDF'leri kendi içindeki `pdf-arsiv` klasöründe tutar; bu klasör `web.config` ile dışarıdan erişime kapalıdır. Yedek alırken `httpdocs/cari/pdf-arsiv` klasörünü de dahil edin.
> PHP sürümü: PHP Ayarları'nda 8.2 veya 8.3 seçin (8.0 da çalışır ama artık güvenlik güncellemesi almıyor).

## 1. Veritabanını oluşturun

cPanel > **MySQL Veritabanları**:

1. Yeni veritabanı oluşturun, ör. `cari` (cPanel başına kullanıcı adınızı ekler: `kullaniciadi_cari`).
2. Yeni kullanıcı oluşturun, güçlü bir şifre verin.
3. Kullanıcıyı veritabanına ekleyin, **TÜM AYRICALIKLAR**'ı işaretleyin.

## 2. PDF klasörünü oluşturun

cPanel > **Dosya Yöneticisi**'nde ana dizininizde (public_html'in yanında, **içinde değil**) `cari-belgeler` adında bir klasör açın.
Tam yolu not edin: genellikle `/home/CPANELKULLANICI/cari-belgeler`.

> PDF'lerin public_html dışında durması önemli. İçeride olursa, dosya adını tahmin eden biri faturayı giriş yapmadan indirebilir. Uygulama PDF'leri yalnızca giriş yapmış kullanıcılara verir.

## 3. Dosyaları yükleyin

1. Zip'i bilgisayarınızda açın.
2. Dosya Yöneticisi'nde `public_html/cari` klasörünü oluşturun (uygulama `https://ALANADINIZ.com/cari` adresinde çalışır; isterseniz başka bir ad ya da alt alan adı kullanabilirsiniz).
3. İçindeki her şeyi bu klasöre yükleyin. `.htaccess` gizli dosyadır; Dosya Yöneticisi'nde Ayarlar > "Gizli dosyaları göster" ile görünür.

## 4. Ayarları yapın

`config.sample.php` dosyasını kopyalayıp adını `config.php` yapın, düzenleyin:

| Ayar | Ne yazılır |
|---|---|
| `db_name`, `db_user`, `db_pass` | 1. adımdaki veritabanı bilgileri |
| `pdf_dir` | 2. adımdaki tam yol |
| `timezone` | Saat dilimi (varsayılan `Europe/Istanbul`) |
| `setup_key` | Uzun, rastgele bir metin; yalnızca kurulumda bir kez kullanılır |

## 5. Kurulumu çalıştırın

1. Tarayıcıda `https://ALANADINIZ.com/cari/setup.php` adresini açın.
2. Kurulum anahtarını, kendi kullanıcı adı ve şifrenizi, isterseniz mali müşavirin hesabını girin, **Kur**'a basın.
3. "Kurulum tamamlandı" yazısını görünce **`setup.php` dosyasını sunucudan silin.**

**Firma bilgileri:** Kurulumda firma unvanı ve VKN sorulur. Logo, uygulama adı, KDV oranları, ödeme yöntemleri ve A/G raporu gün sayısı giriş yaptıktan sonra **Ayarlar → Firma ve uygulama** bölümünden girilir. Bu bilgiler kodda değil veritabanında tutulur; aynı kod farklı firmalara değişiklik yapmadan kurulabilir.

Giriş adresi: `https://ALANADINIZ.com/cari/`

## 6. HTTPS ve yükleme sınırı

- cPanel > **SSL/TLS Status** bölümünden ücretsiz sertifikanın etkin olduğunu kontrol edin. Sonra `.htaccess` içindeki HTTPS yönlendirme satırlarının başındaki `#` işaretlerini kaldırın.
- Büyük PDF'ler için cPanel > **PHP Sürümünü Seç** (veya MultiPHP INI Düzenleyici) > `upload_max_filesize` = `25M`, `post_max_size` = `26M` yapın. Arşiv ekranı geçerli sınırı gösterir.

## Kullanım

- **Yönetici** her şeyi ekler, düzenler, siler. **Salt okunur** kullanıcı (mali müşavir) her ekranı görür, PDF'leri açıp indirir, ekstre yazdırır, Excel ve mali müşavir paketi indirir; hiçbir şeyi değiştiremez.
- **Fatura PDF'i:** Fatura penceresinde "PDF seç". Yeni faturada PDF seçince fatura no, tarih, matrah, KDV ve VKN'ye göre cari boş alanlara yazılır.
- **e-Fatura zip / XML:** GİB e-Arşiv / e-Fatura portalından indirilen zip dosyaları (içinde UBL XML + HTML) arşiv ekranına doğrudan sürüklenebilir. Fatura no, tarih, taraflar, matrah, KDV, tevkifat ve kalemler XML'den kesin olarak okunur; zip olduğu gibi saklanır ve uygulamadan resmî görünümüyle açılır. Tek zip'te birden çok fatura varsa her biri ayrı kaydedilir.
- **Geçmiş faturalar:** "PDF arşiv yükle" ekranına PDF'leri sürükleyin. Her dosyanın bilgileri PDF içinden okunur, VKN'si kayıtlı bir cariyle eşleşirse cari ve fatura türü kendiliğinden seçilir. Satırları kontrol edip "Kaydet"e basın. Aynı cari için aynı fatura numarası ikinci kez kaydedilmez.
  - Okuma GİB e-Fatura / e-Arşiv PDF'lerinde en iyi sonucu verir. Taranmış (resim) PDF'lerde metin olmadığı için bilgileri elle girersiniz; PDF yine eklenir.
  - Önce müşterileri ve personeli VKN'leriyle ekleyin; eşleşme bu sayede çalışır. Eşleşmeyen VKN için satırda "cari olarak ekle" bağlantısı çıkar.
- **Ödemeleri faturalara bağlama:** Ödeme/tahsilat penceresinde carinin açık faturaları listelenir; tutar eskiden yeniye otomatik dağıtılır, isterseniz elle değiştirirsiniz. Bir fatura birden çok ödemeyle kapanabilir; bir faturaya dağıtılan toplam fatura tutarını aşamaz (sunucu da kontrol eder). Faturalar "Açık / Kısmi / Ödendi" durumuyla görünür. Faturaya bağlanmamış eski ödemeler için cari ekstresinde "Faturalarla eşleştir" düğmesi çıkar.
- **Raporlar:** Personel veya müşteri bazında, seçilen tarih aralığında net (KDV hariç), KDV, KDV dahil, ödenecek, ödenen ve kalan tutarlar; satıra tıklayınca faturaları açılır. Excel (.xlsx) olarak indirilebilir.
- **Vade:** Müşteri (ve personel) kartına vade günü girilir; faturada ayrıca vade girilmezse fatura tarihi + bu gün vade sayılır. Vadesi geçen ya da Ayarlar'daki gün sayısı kadar yaklaşan açık faturalar girişte, menüde ve Özet > Yapılacaklar'da uyarılır.
- **Yapılacaklar (Özet):** vadesi geçen/yaklaşan tahsilat ve ödemeler, müşterisi ödediği hâlde ödenmemiş personel faturaları, dönemi için faturası gelmeyen ya da müşteriye fatura kesilmemiş personel, 30 günü geçen yedek.
- **Personel ödemeleri:** personelin açık faturaları, aynı dönemde müşterinin o personel için kesilen faturayı ödeyip ödemediğiyle birlikte listelenir; seçilenler tek seferde ödenir.
- **Mali müşavir paketi:** Faturalar ekranında; seçilen ayın fatura belgeleri ve Excel listesi tek zip.
- **Arama:** her ekranda Ctrl+K (veya /) ile cari, fatura no, açıklama veya tutar aranır.
- **Sürükle-bırak:** fatura PDF / e-Fatura zip dosyaları herhangi bir ekrana bırakılabilir; açık bir fatura penceresine bırakılan tek dosya o faturaya eklenir.
- **Tema:** üst çubuktaki düğmeyle açık / koyu tema; seçim tarayıcıda hatırlanır.
- **Yazdırma:** Her listede ve cari ekstresinde "Yazdır" düğmesi var; PDF'ler "Aç / yazdır" ile tarayıcıda açılır.
- **İşlem kaydı:** Ayarlar ekranında kimin neyi ne zaman eklediği, değiştirdiği, sildiği görünür.

## Güncelleme

Yeni sürüm zip'ini **Ayarlar > Uygulama güncelleme** bölümünden yükleyin; sunucu paneline girmek gerekmez. Yalnızca uygulama dosyaları (index.php, api.php, assets, lib) değişir; config.php, setup.php, kök .htaccess / web.config, kayıtlar ve fatura belgeleri olduğu gibi kalır. Mevcut sürümün dosyaları PDF klasöründeki `_guncelleme` içine zip olarak saklanır. Daha eski bir sürüm yüklenemez.

## Yedekleme

Ayarlar > **Yedekleme > Tam yedeği indir**: veritabanının tamamı (veritabani.sql) ve tüm fatura belgeleri tek zip dosyası olarak iner (sunucuda PHP zip eklentisi gerekir). Geri yüklemek için: boş veritabanında phpMyAdmin > İçe aktar ile veritabani.sql, ardından belgeler klasörünün içeriğini PDF klasörüne kopyalayın.

Ayrıca hosting panelinden de yedek alın: cPanel > **Yedekleme** (veya JetBackup) ile hem veritabanını hem `cari-belgeler` klasörünü düzenli yedekleyin; ayda bir yedeği bilgisayarınıza da indirin.

## Dosyalar

| Dosya | Görevi |
|---|---|
| `index.php` | Giriş ekranı ve uygulama |
| `api.php` | Veri ve PDF işlemleri (oturum, yetki ve CSRF kontrollü) |
| `setup.php` | Tek seferlik kurulum; kurulumdan sonra silin |
| `lib/schema.sql` | Veritabanı tabloları (phpMyAdmin'de elle de çalıştırılabilir) |
| `assets/` | Arayüz, PDF okuyucu (pdf.js, Apache 2.0) ve zip okuyucu (JSZip, MIT) |
