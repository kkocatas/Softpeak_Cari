-- Cari Takip — MySQL / MariaDB şeması (utf8mb4)
-- setup.php bu dosyayı otomatik çalıştırır; elle kurmak isterseniz phpMyAdmin > SQL sekmesine yapıştırın.

CREATE TABLE IF NOT EXISTS kullanicilar (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kullanici VARCHAR(60) NOT NULL UNIQUE,
  ad VARCHAR(120) NOT NULL,
  sifre_hash VARCHAR(255) NOT NULL,
  rol ENUM('admin','okur') NOT NULL DEFAULT 'okur',
  son_giris DATETIME NULL,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS cariler (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tip ENUM('personel','musteri') NOT NULL,
  ad VARCHAR(200) NOT NULL,
  vkn VARCHAR(20) NOT NULL DEFAULT '',
  eposta VARCHAR(150) NOT NULL DEFAULT '',
  telefon VARCHAR(30) NOT NULL DEFAULT '',
  durum ENUM('aktif','pasif') NOT NULL DEFAULT 'aktif',
  musteri_id INT UNSIGNED NULL,
  aylik_alis DECIMAL(15,2) NOT NULL DEFAULT 0,
  aylik_satis DECIMAL(15,2) NOT NULL DEFAULT 0,
  kdv_oran DECIMAL(5,2) NOT NULL DEFAULT 20,
  vade_gun SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  baslangic DATE NULL,
  notlar TEXT NULL,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  guncelleme DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_tip (tip), KEY ix_vkn (vkn),
  CONSTRAINT fk_cari_musteri FOREIGN KEY (musteri_id) REFERENCES cariler(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS projeler (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ad VARCHAR(200) NOT NULL,
  musteri_id INT UNSIGNED NOT NULL,
  bedel DECIMAL(15,2) NOT NULL DEFAULT 0,
  durum ENUM('teklif','devam','tamam','iptal') NOT NULL DEFAULT 'devam',
  baslangic DATE NULL,
  bitis DATE NULL,
  aciklama TEXT NULL,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  guncelleme DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_proje_musteri FOREIGN KEY (musteri_id) REFERENCES cariler(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- tur: gelen = personelden/tedarikçiden gelen (alış), kesilen = müşteriye kesilen (satış)
CREATE TABLE IF NOT EXISTS faturalar (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tur ENUM('gelen','kesilen') NOT NULL,
  cari_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NULL,
  proje_id INT UNSIGNED NULL,
  no VARCHAR(40) NOT NULL DEFAULT '',
  tarih DATE NOT NULL,
  donem CHAR(7) NOT NULL,
  vade DATE NULL,
  matrah DECIMAL(15,2) NOT NULL,
  kdv_oran DECIMAL(5,2) NOT NULL DEFAULT 20,
  tevkifat TINYINT UNSIGNED NOT NULL DEFAULT 0,
  stopaj_oran DECIMAL(5,2) NOT NULL DEFAULT 0,
  aciklama VARCHAR(500) NOT NULL DEFAULT '',
  pdf_yol VARCHAR(255) NULL,
  pdf_ad VARCHAR(255) NULL,
  pdf_boyut INT UNSIGNED NULL,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  guncelleme DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_cari (cari_id), KEY ix_donem (donem), KEY ix_tarih (tarih), KEY ix_no (no),
  CONSTRAINT fk_fatura_cari FOREIGN KEY (cari_id) REFERENCES cariler(id),
  CONSTRAINT fk_fatura_personel FOREIGN KEY (personel_id) REFERENCES cariler(id) ON DELETE SET NULL,
  CONSTRAINT fk_fatura_proje FOREIGN KEY (proje_id) REFERENCES projeler(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- tur: tahsilat = müşteriden gelen para, odeme = personele/tedarikçiye giden para
CREATE TABLE IF NOT EXISTS hareketler (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tur ENUM('tahsilat','odeme') NOT NULL,
  cari_id INT UNSIGNED NOT NULL,
  proje_id INT UNSIGNED NULL,
  tarih DATE NOT NULL,
  tutar DECIMAL(15,2) NOT NULL,
  yontem VARCHAR(40) NOT NULL DEFAULT '',
  aciklama VARCHAR(500) NOT NULL DEFAULT '',
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  guncelleme DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_cari (cari_id), KEY ix_tarih (tarih),
  CONSTRAINT fk_hareket_cari FOREIGN KEY (cari_id) REFERENCES cariler(id),
  CONSTRAINT fk_hareket_proje FOREIGN KEY (proje_id) REFERENCES projeler(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Hangi ödeme/tahsilat hangi faturaya ne kadar gitti (bir ödeme birden çok faturaya, bir fatura birden çok ödemeye bağlanabilir)
CREATE TABLE IF NOT EXISTS odeme_dagitim (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hareket_id INT UNSIGNED NOT NULL,
  fatura_id INT UNSIGNED NOT NULL,
  tutar DECIMAL(15,2) NOT NULL,
  KEY ix_fatura (fatura_id), KEY ix_hareket (hareket_id),
  CONSTRAINT fk_dagitim_hareket FOREIGN KEY (hareket_id) REFERENCES hareketler(id) ON DELETE CASCADE,
  CONSTRAINT fk_dagitim_fatura FOREIGN KEY (fatura_id) REFERENCES faturalar(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Kim, ne zaman, neyi değiştirdi (mali müşavir denetimi için)
CREATE TABLE IF NOT EXISTS islem_kaydi (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kullanici_id INT UNSIGNED NULL,
  tablo VARCHAR(30) NOT NULL,
  kayit_id INT UNSIGNED NULL,
  islem VARCHAR(20) NOT NULL,
  eski JSON NULL,
  yeni JSON NULL,
  zaman DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_kayit (tablo, kayit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS giris_denemeleri (
  ip VARCHAR(45) NOT NULL,
  zaman DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ip (ip, zaman)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Firma ayarları: firma adı, VKN, uygulama adı, logo, KDV oranları, ödeme yöntemleri vb.
CREATE TABLE IF NOT EXISTS ayarlar (
  anahtar VARCHAR(50) NOT NULL PRIMARY KEY,
  deger MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
