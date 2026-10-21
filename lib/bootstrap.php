<?php
declare(strict_types=1);

// Ortak başlangıç: ayarlar, veritabanı bağlantısı, oturum ve yardımcılar.

if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(500);
    exit('config.php bulunamadı. config.sample.php dosyasını config.php olarak kopyalayıp doldurun.');
}
$CONFIG = require __DIR__ . '/../config.php';

date_default_timezone_set('Europe/Istanbul');
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
    $pdo->exec("SET time_zone = '+03:00'");
    return $pdo;
}

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('SPCARI');
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
 * 'auto': önce web kök dizininin yanındaki softpeak-faturalar; orada klasör açılamıyorsa uygulama içindeki pdf-arsiv.
 * Bir kez seçilen yer sabit kalır (pdf-arsiv'de dosya varsa hep orası kullanılır).
 */
function pdf_dir_path(): string {
    $dir = rtrim((string)cfg('pdf_dir', 'auto'), '/\\');
    if ($dir !== '' && $dir !== 'auto') return $dir;
    $inner = pdf_inner_dir();
    if (is_dir($inner)) return $inner;
    $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 2);
    $outer = dirname($docroot) . '/softpeak-faturalar';
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
