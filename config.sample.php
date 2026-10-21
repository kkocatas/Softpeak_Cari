<?php
// Softpeak Cari — ayarlar
// Bu dosyayı config.php adıyla kopyalayıp bilgileri doldurun. config.php'yi kimseyle paylaşmayın.

return [
    // cPanel > MySQL Veritabanları'nda oluşturduğunuz veritabanı ve kullanıcı
    'db_host' => 'localhost',
    'db_name' => 'kullaniciadi_cari',
    'db_user' => 'kullaniciadi_cari',
    'db_pass' => 'BURAYA-VERITABANI-SIFRESI',

    // Fatura PDF'lerinin saklanacağı klasör.
    // 'auto': web kök dizininin (httpdocs / public_html) yanındaki softpeak-faturalar klasörü. Çoğu hostingde böyle bırakın.
    // Farklı bir yer isterseniz tam yolu yazın; klasör web kök dizininin DIŞINDA olmalı.
    'pdf_dir' => 'auto',

    // Tek PDF için üst sınır (MB). Hosting'in upload_max_filesize ayarı bundan küçükse o geçerli olur.
    'pdf_max_mb' => 20,

    // Softpeak'in VKN'si: toplu PDF aktarımında faturanın gelen mi kesilen mi olduğunu anlamak için
    'softpeak_vkn' => '',

    // setup.php'yi bir kez çalıştırmak için kullanılacak gizli anahtar. Kurulumdan sonra setup.php'yi silin.
    'setup_key' => 'BURAYA-UZUN-RASTGELE-BIR-METIN',

    // Oturum süresi (dakika); bu süre işlem yapılmazsa yeniden giriş istenir
    'session_minutes' => 120,
];
