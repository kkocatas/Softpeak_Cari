<?php
declare(strict_types=1);
// Tek seferlik kurulum: tabloları oluşturur, ilk kullanıcıları ekler, PDF klasörünü kontrol eder.
// Kurulum bittikten sonra bu dosyayı sunucudan SİLİN.

require __DIR__ . '/lib/bootstrap.php';
security_headers();
header('Content-Type: text/html; charset=utf-8');

$msgs = []; $errs = []; $done = false;
$key = (string)($_POST['key'] ?? '');
$keyOk = cfg('setup_key') && !str_starts_with((string)cfg('setup_key'), 'BURAYA') && hash_equals((string)cfg('setup_key'), $key);

function check_env(array &$msgs, array &$errs): void {
    if (version_compare(PHP_VERSION, '8.0.0', '<')) $errs[] = 'PHP 8.0 veya üstü gerekli (şu an ' . PHP_VERSION . '). Hosting panelindeki PHP ayarlarından sürümü yükseltin.';
    elseif (version_compare(PHP_VERSION, '8.1.0', '<')) $msgs[] = 'Uyarı: PHP ' . PHP_VERSION . ' çalışır ama güvenlik güncellemesi almıyor. Hosting panelindeki PHP ayarlarından 8.2 veya 8.3 seçmeniz önerilir.';
    foreach (['pdo_mysql', 'fileinfo', 'mbstring'] as $ext) if (!extension_loaded($ext)) $errs[] = "PHP eklentisi eksik: $ext (hosting panelindeki PHP ayarlarından açın).";
    try { db()->query('SELECT 1'); $msgs[] = 'Veritabanı bağlantısı çalışıyor.'; }
    catch (Throwable $t) { $errs[] = 'Veritabanına bağlanılamadı. config.php içindeki db_ bilgilerini kontrol edin. (' . $t->getMessage() . ')'; }
    $dir = pdf_dir_path();
    try {
        pdf_root();
        if (!is_writable($dir)) $errs[] = "PDF klasörüne yazılamıyor: $dir. Plesk Dosya Yöneticisi'nde klasörün izinlerinden web sitesi kullanıcısına yazma (Değiştirme) izni verin.";
        else $msgs[] = "PDF klasörü hazır: $dir";
        $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
        $real = realpath($dir) ?: $dir;
        $inner = norm_path($real) === norm_path(realpath(pdf_inner_dir()) ?: pdf_inner_dir());
        if ($inner) {
            $msgs[] = 'PDF\'ler uygulama klasörünün içindeki pdf-arsiv klasöründe tutulacak; bu klasör web.config ve .htaccess ile dışarıdan erişime kapatıldı.';
        } elseif ($docroot && str_starts_with(norm_path($real) . '/', norm_path($docroot) . '/')) {
            $errs[] = 'PDF klasörü web kök dizininin (httpdocs / public_html) içinde. Faturalar adres yazılarak indirilebilir hale gelebilir; config.php içinde pdf_dir değerini \'auto\' yapın.';
        }
    } catch (Throwable $t) {
        $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '(bilinmiyor)';
        $errs[] = 'PDF klasörü oluşturulamadı: ' . $dir . '. Dosya Yöneticisi\'nde en üst dizinde (web kök dizininizin yanında) cari-belgeler adlı klasörü elle açın ve config.php içinde pdf_dir değerini \'auto\' yapın.';
        $msgs[] = 'Bilgi: Sunucudaki web kök dizini ' . $docroot . ' · PHP erişim sınırı (open_basedir): ' . (ini_get('open_basedir') ?: 'yok');
    }
    if (!is_https()) $msgs[] = 'Uyarı: Sayfa HTTPS üzerinden açılmamış. Adresi https:// ile açın; açılmıyorsa hosting panelinden ücretsiz SSL sertifikasını (Let\'s Encrypt) etkinleştirin. Şifreler şifresiz gönderilmesin.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$keyOk) { $errs[] = 'Kurulum anahtarı yanlış. config.php içindeki setup_key değerini girin.'; }
    else {
        check_env($msgs, $errs);
        if (!$errs) {
            try {
                $sql = file_get_contents(__DIR__ . '/lib/schema.sql');
                $sql = preg_replace('/^--.*$/m', '', $sql);
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) db()->exec($stmt);
                $msgs[] = 'Tablolar oluşturuldu.';
                $_SESSION = []; ensure_schema();
                $fa = mb_substr(trim((string)($_POST['firma_ad'] ?? '')), 0, 150); $fv = preg_replace('/\D/', '', (string)($_POST['firma_vkn'] ?? ''));
                if ($fv !== '' && !preg_match('/^\d{10,11}$/', $fv)) $errs[] = 'Firma VKN 10, TCKN 11 haneli olmalı.';
                else { if ($fa !== '') ayar_yaz('firma_ad', $fa); if ($fv !== '') ayar_yaz('firma_vkn', $fv); if ($fa !== '' || $fv !== '') $msgs[] = 'Firma bilgileri kaydedildi. Logo ve diğer ayarlar uygulamadaki Ayarlar ekranından değiştirilebilir.'; }
                $n = (int)db()->query('SELECT COUNT(*) FROM kullanicilar')->fetchColumn();
                $add = function (string $user, string $ad, string $pass, string $rol) use (&$errs, &$msgs) {
                    if ($user === '') return;
                    if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $user)) { $errs[] = "Kullanıcı adı geçersiz: $user (harf, rakam, nokta, tire; en az 3 karakter)"; return; }
                    if (mb_strlen($pass) < 10) { $errs[] = "$user için şifre en az 10 karakter olmalı."; return; }
                    $st = db()->prepare('INSERT INTO kullanicilar (kullanici, ad, sifre_hash, rol) VALUES (?,?,?,?)');
                    try { $st->execute([$user, $ad ?: $user, password_hash($pass, PASSWORD_DEFAULT), $rol]); $msgs[] = "Kullanıcı eklendi: $user (" . ($rol === 'admin' ? 'yönetici' : 'salt okunur') . ')'; }
                    catch (PDOException $ex) { $errs[] = "$user zaten var."; }
                };
                if ($n === 0) {
                    $add(trim($_POST['u1'] ?? ''), trim($_POST['a1'] ?? ''), (string)($_POST['p1'] ?? ''), 'admin');
                    $add(trim($_POST['u2'] ?? ''), trim($_POST['a2'] ?? ''), (string)($_POST['p2'] ?? ''), 'okur');
                } else {
                    $msgs[] = 'Kullanıcılar zaten var; yeni kullanıcıyı uygulamadaki Ayarlar ekranından ekleyin.';
                }
                if (!$errs) $done = true;
            } catch (Throwable $t) { $errs[] = 'Kurulum hatası: ' . $t->getMessage(); }
        }
    }
}
?><!doctype html><html lang="tr" data-logo-koyu="<?= e(ayar('logo_koyu') ?: 'normal') ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(marka_ad()) ?> · Kurulum</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="auth"><main class="auth-box" style="max-width:560px">
<?= marka_html() ?><h1>Kurulum</h1>
<?php foreach ($errs as $m) echo '<div class="err">' . e($m) . '</div>'; ?>
<?php foreach ($msgs as $m) echo '<div class="' . ((str_starts_with($m, 'Uyarı') || str_starts_with($m, 'Bilgi')) ? 'err' : 'ok-msg') . '">' . e($m) . '</div>'; ?>
<?php if ($done): ?>
  <p><b>Kurulum tamamlandı.</b> Şimdi bu sunucudan <code>setup.php</code> dosyasını silin, ardından <a href="./">uygulamaya giriş yapın</a>.</p>
<?php else: ?>
<form method="post" class="form" autocomplete="off">
  <label class="f full">Kurulum anahtarı (config.php &gt; setup_key)<input type="password" name="key" required></label>
  <h2 class="full">Firma</h2>
  <label class="f">Firma unvanı<input name="firma_ad" value="<?= e($_POST['firma_ad'] ?? '') ?>" placeholder="Faturalarda görünen unvan"></label>
  <label class="f">VKN<input name="firma_vkn" inputmode="numeric" value="<?= e($_POST['firma_vkn'] ?? '') ?>" placeholder="10 haneli vergi no"></label>
  <h2 class="full">Yönetici (siz)</h2>
  <label class="f">Kullanıcı adı<input name="u1" value="<?= e($_POST['u1'] ?? '') ?>" required></label>
  <label class="f">Ad soyad<input name="a1" value="<?= e($_POST['a1'] ?? '') ?>"></label>
  <label class="f full">Şifre (en az 10 karakter)<input type="password" name="p1" minlength="10" required></label>
  <h2 class="full">Mali müşavir (salt okunur, isteğe bağlı)</h2>
  <label class="f">Kullanıcı adı<input name="u2" value="<?= e($_POST['u2'] ?? '') ?>"></label>
  <label class="f">Ad soyad<input name="a2" value="<?= e($_POST['a2'] ?? '') ?>"></label>
  <label class="f full">Şifre<input type="password" name="p2"></label>
  <div class="full"><button class="btn primary" type="submit">Kur</button></div>
</form>
<?php endif; ?>
</main></body></html>
