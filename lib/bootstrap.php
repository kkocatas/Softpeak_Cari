<?php
declare(strict_types=1);

// Ortak başlangıç: ayarlar, veritabanı bağlantısı, oturum ve yardımcılar.

if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(500);
    exit('config.php bulunamadı. config.sample.php dosyasını config.php olarak kopyalayıp doldurun.');
}
$CONFIG = require __DIR__ . '/../config.php';

date_default_timezone_set((string)cfg('timezone', 'Europe/Istanbul'));
mb_internal_encoding('UTF-8');

function cfg(string $k, $default = null) { global $CONFIG; return $CONFIG[$k] ?? $default; }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    return $pdo;
}

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('CARI_OTURUM');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    $limit = (int)cfg('session_minutes', 120) * 60;
    if (isset($_SESSION['uid'], $_SESSION['son']) && time() - $_SESSION['son'] > $limit) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    if (isset($_SESSION['uid'])) $_SESSION['son'] = time();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function current_user(): ?array {
    start_session();
    if (empty($_SESSION['uid'])) return null;
    static $u = false;
    if ($u === false) {
        $st = db()->prepare('SELECT id, kullanici, ad, rol FROM kullanicilar WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $u = $st->fetch() ?: null;
        if (!$u) { $_SESSION = []; }
    }
    return $u;
}

function security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
}

function ini_bytes(string $v): int {
    $v = trim($v); $n = (int)$v; $u = strtolower(substr($v, -1));
    return match ($u) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

/** Yol karşılaştırması için ayraçları ve (Windows'ta) harf büyüklüğünü eşitler. */
function norm_path(string $p): string {
    $p = rtrim(str_replace('\\', '/', $p), '/');
    return DIRECTORY_SEPARATOR === '\\' ? strtolower($p) : $p;
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

/** Uygulama içindeki yedek arşiv klasörü (web.config / .htaccess ile dışarıdan erişime kapalı). */
function pdf_inner_dir(): string { return dirname(__DIR__) . '/pdf-arsiv'; }

/**
 * Fatura PDF klasörünün yolu.
 * 'auto': önce web kök dizininin yanındaki cari-belgeler; orada klasör açılamıyorsa uygulama içindeki pdf-arsiv.
 * Bir kez seçilen yer sabit kalır (pdf-arsiv'de dosya varsa hep orası kullanılır).
 */
function pdf_dir_path(): string {
    $dir = rtrim((string)cfg('pdf_dir', 'auto'), '/\\');
    if ($dir !== '' && $dir !== 'auto') return $dir;
    $inner = pdf_inner_dir();
    if (is_dir($inner)) return $inner;
    $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 2);
    $outer = dirname($docroot) . '/cari-belgeler';
    if (is_dir($outer) || @mkdir($outer, 0750, true)) return $outer;
    return $inner;
}

/** pdf-arsiv klasörünü dışarıdan erişime kapatan dosyaları yazar. */
function protect_inner_dir(string $dir): void {
    if (!is_file("$dir/web.config")) {
        @file_put_contents("$dir/web.config", "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted=\"false\" /></requestFiltering></security><directoryBrowse enabled=\"false\" /></system.webServer></configuration>\n");
    }
    if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n");
    if (!is_file("$dir/index.html")) @file_put_contents("$dir/index.html", "");
}

/** Fatura PDF klasörü (sonunda / olmadan). Yoksa oluşturur. */
function pdf_root(): string {
    $dir = pdf_dir_path();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        throw new RuntimeException('PDF klasörü oluşturulamadı: ' . $dir);
    }
    if (norm_path($dir) === norm_path(pdf_inner_dir())) protect_inner_dir($dir);
    return $dir;
}

/* ---------- veritabanı sürümü (sonradan eklenen tablo/sütunlar) ---------- */
const SCHEMA_VER = 6;
/** Sonradan eklenen tabloları mevcut kurulumlarda oluşturur (oturum başına bir kez). */
function ensure_schema(): void {
    if (($_SESSION['schema'] ?? 0) >= SCHEMA_VER) return;
    db()->exec("CREATE TABLE IF NOT EXISTS odeme_dagitim (   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,   hareket_id INT UNSIGNED NOT NULL,   fatura_id INT UNSIGNED NOT NULL,   tutar DECIMAL(15,2) NOT NULL,   KEY ix_fatura (fatura_id), KEY ix_hareket (hareket_id),   CONSTRAINT fk_dagitim_hareket FOREIGN KEY (hareket_id) REFERENCES hareketler(id) ON DELETE CASCADE,   CONSTRAINT fk_dagitim_fatura FOREIGN KEY (fatura_id) REFERENCES faturalar(id) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");
    // v3: personel kartında KDV oranı
    $st = db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cariler' AND COLUMN_NAME = 'kdv_oran'");
    $st->execute();
    if (!(int)$st->fetchColumn()) db()->exec('ALTER TABLE cariler ADD COLUMN kdv_oran DECIMAL(5,2) NOT NULL DEFAULT 20 AFTER aylik_satis');
    // v4: cari kartlarında e-posta ve telefon
    foreach (['eposta' => "VARCHAR(150) NOT NULL DEFAULT '' AFTER vkn", 'telefon' => "VARCHAR(30) NOT NULL DEFAULT '' AFTER eposta"] as $kol => $tanim) {
        $st = db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cariler' AND COLUMN_NAME = ?");
        $st->execute([$kol]);
        if (!(int)$st->fetchColumn()) db()->exec("ALTER TABLE cariler ADD COLUMN $kol $tanim");
    }
    // v5: firma ayarları (marka, VKN, logo, listeler) veritabanında
    db()->exec('CREATE TABLE IF NOT EXISTS ayarlar (anahtar VARCHAR(50) NOT NULL PRIMARY KEY, deger MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci');
    $var = db()->query('SELECT anahtar FROM ayarlar')->fetchAll(PDO::FETCH_COLUMN);
    // Önceki sürümlerde VKN config.php'deydi (eski ayar adlarıyla); bir kez veritabanına taşınır.
    $eskiVkn = preg_replace('/\D/', '', (string)(cfg('firma_vkn') ?: cfg('softpeak_vkn', '')));
    if (!in_array('firma_vkn', $var, true) && $eskiVkn !== '') ayar_yaz('firma_vkn', $eskiVkn);
    $eskiLogo = dirname(__DIR__) . '/assets/logo.png';
    if (!in_array('logo_data', $var, true) && is_file($eskiLogo) && ($bin = file_get_contents($eskiLogo))) {
        ayar_yaz('logo_data', base64_encode($bin)); ayar_yaz('logo_mime', 'image/png'); ayar_yaz('logo_surum', substr(md5($bin), 0, 10));
    }
    // v6: cari kartında vade (gün)
    $st = db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cariler' AND COLUMN_NAME = 'vade_gun'");
    $st->execute();
    if (!(int)$st->fetchColumn()) db()->exec('ALTER TABLE cariler ADD COLUMN vade_gun SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER kdv_oran');
    $_SESSION['schema'] = SCHEMA_VER;
    unset($GLOBALS['__ayar_cache']);
}

/* ---------- firma ayarları: marka, VKN, logo, seçenek listeleri (koda gömülü değil, veritabanında) ---------- */
const AYAR_VARSAYILAN = [
    'firma_ad' => '',
    'firma_vkn' => '',
    'uygulama_ad' => 'Cari Takip',
    'kdv_oranlari' => '20,18,10,8,1,0',
    'varsayilan_kdv' => '20',
    'odeme_yontemleri' => "Havale / EFT\nNakit\nKredi kartı\nÇek\nSenet\nMahsup",
    'ag_gun' => '20',
    'logo_surum' => '',
    'logo_koyu' => 'normal',
    'vade_uyari_gun' => '7',
];
const LOGO_MIME = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
function ayarlar(): array {
    if (isset($GLOBALS['__ayar_cache'])) return $GLOBALS['__ayar_cache'];
    $a = AYAR_VARSAYILAN;
    try { foreach (db()->query("SELECT anahtar, deger FROM ayarlar WHERE anahtar <> 'logo_data'") as $r) $a[$r['anahtar']] = $r['deger']; }
    catch (Throwable $t) { /* tablo henüz yok: varsayılanlar */ }
    return $GLOBALS['__ayar_cache'] = $a;
}
function ayar(string $k): string { return (string)(ayarlar()[$k] ?? ''); }
function ayar_yaz(string $k, string $v): void {
    db()->prepare('INSERT INTO ayarlar (anahtar, deger) VALUES (?, ?) ON DUPLICATE KEY UPDATE deger = VALUES(deger)')->execute([$k, $v]);
    unset($GLOBALS['__ayar_cache']);
}
/** Arayüze giden ayarlar (logo verisi hariç). */
function ayarlar_genel(bool $tazele = false): array {
    if ($tazele) unset($GLOBALS['__ayar_cache']);
    $a = ayarlar(); unset($a['logo_mime']);
    $a['logo_url'] = logo_url() ?? '';
    return $a;
}
function marka_ad(): string { return ayar('uygulama_ad') ?: AYAR_VARSAYILAN['uygulama_ad']; }
function logo_url(): ?string { $v = ayar('logo_surum'); return $v !== '' ? 'api.php?a=logo&v=' . rawurlencode($v) : null; }
/** Logo varsa <img>, yoksa firma/uygulama adı (giriş ekranı, menü ve kurulum için). */
function marka_html(string $sinif = 'logo'): string {
    $u = logo_url(); $ad = ayar('firma_ad') ?: marka_ad();
    return $u ? '<img class="' . e($sinif) . '" src="' . e($u) . '" alt="' . e($ad) . '">' : '<span class="' . e($sinif) . ' logo-yazi">' . e($ad) . '</span>';
}
