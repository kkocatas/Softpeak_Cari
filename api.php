<?php
declare(strict_types=1);
// Cari Takip — JSON API. Tüm istekler oturum ister (login hariç); değişiklikler yönetici rolü ister.

require __DIR__ . '/lib/bootstrap.php';
security_headers();

function out(array $data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400) { out(['ok' => false, 'error' => $msg], $code); }
function body(): array {
    static $b = null;
    if ($b !== null) return $b;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_starts_with($ct, 'application/json')) $b = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    else $b = $_POST;
    return $b;
}

/* ---------- kayıt tanımları: arayüzdeki alan adı => [sütun, tür, seçenek] ---------- */
const ENT = [
    'cariler' => [
        'tip' => ['tip', 'enum', ['personel', 'musteri'], true],
        'ad' => ['ad', 'str', 200, true],
        'vkn' => ['vkn', 'str', 20],
        'eposta' => ['eposta', 'email', 150],
        'telefon' => ['telefon', 'phone', 30],
        'durum' => ['durum', 'enum', ['aktif', 'pasif']],
        'musteriId' => ['musteri_id', 'ref', 'cariler'],
        'aylikAlis' => ['aylik_alis', 'money'],
        'aylikSatis' => ['aylik_satis', 'money'],
        'kdvOran' => ['kdv_oran', 'pct'],
        'baslangic' => ['baslangic', 'date'],
        'notlar' => ['notlar', 'str', 5000],
    ],
    'projeler' => [
        'ad' => ['ad', 'str', 200, true],
        'musteriId' => ['musteri_id', 'ref', 'cariler', true],
        'bedel' => ['bedel', 'money'],
        'durum' => ['durum', 'enum', ['devam', 'teklif', 'tamam', 'iptal']],
        'baslangic' => ['baslangic', 'date'],
        'bitis' => ['bitis', 'date'],
        'aciklama' => ['aciklama', 'str', 5000],
    ],
    'faturalar' => [
        'tur' => ['tur', 'enum', ['gelen', 'kesilen'], true],
        'cariId' => ['cari_id', 'ref', 'cariler', true],
        'personelId' => ['personel_id', 'ref', 'cariler'],
        'projeId' => ['proje_id', 'ref', 'projeler'],
        'no' => ['no', 'str', 40],
        'tarih' => ['tarih', 'date', null, true],
        'donem' => ['donem', 'month', null, true],
        'vade' => ['vade', 'date'],
        'matrah' => ['matrah', 'money', null, true],
        'kdvOran' => ['kdv_oran', 'pct'],
        'tevkifat' => ['tevkifat', 'tevkifat'],
        'stopajOran' => ['stopaj_oran', 'pct'],
        'aciklama' => ['aciklama', 'str', 500],
    ],
    'hareketler' => [
        'tur' => ['tur', 'enum', ['tahsilat', 'odeme'], true],
        'cariId' => ['cari_id', 'ref', 'cariler', true],
        'projeId' => ['proje_id', 'ref', 'projeler'],
        'tarih' => ['tarih', 'date', null, true],
        'tutar' => ['tutar', 'money', null, true],
        'yontem' => ['yontem', 'str', 40],
        'aciklama' => ['aciklama', 'str', 500],
    ],
];
const ETIKET = ['tip' => 'Tür', 'tur' => 'Tür', 'ad' => 'Ad', 'musteriId' => 'Müşteri', 'cariId' => 'Cari', 'tarih' => 'Tarih', 'donem' => 'Dönem', 'matrah' => 'Matrah', 'tutar' => 'Tutar'];

function to_api(string $t, array $row): array {
    $o = ['id' => (string)$row['id']];
    foreach (ENT[$t] as $k => $d) {
        $v = $row[$d[0]] ?? null;
        $o[$k] = match ($d[1]) {
            'ref' => $v === null ? '' : (string)$v,
            'money', 'pct' => $v === null ? 0 : (float)$v,
            'tevkifat' => (int)$v,
            default => $v ?? '',
        };
    }
    if ($t === 'faturalar') { $o['pdfAd'] = $row['pdf_ad'] ?? ''; $o['pdfBoyut'] = (int)($row['pdf_boyut'] ?? 0); $o['pdf'] = !empty($row['pdf_yol']); $o['belgeTur'] = $o['pdf'] ? strtolower(pathinfo((string)$row['pdf_yol'], PATHINFO_EXTENSION)) : ''; }
    $o['guncelleme'] = $row['guncelleme'] ?? '';
    return $o;
}

function validate(string $t, array $in): array {
    $vals = [];
    foreach (ENT[$t] as $k => $d) {
        [$col, $type] = $d; $opt = $d[2] ?? null; $req = $d[3] ?? false;
        $v = $in[$k] ?? null;
        if (is_string($v)) $v = trim($v);
        $empty = $v === null || $v === '';
        if ($empty) {
            if ($req) fail((ETIKET[$k] ?? $k) . ' boş bırakılamaz.');
            $vals[$col] = match ($type) { 'pct' => ($col === 'kdv_oran' ? (float)(ayar('varsayilan_kdv') ?: 20) : 0), 'money', 'tevkifat' => 0, 'str', 'email', 'phone' => '', 'enum' => $opt[0], default => null };
            continue;
        }
        switch ($type) {
            case 'str': $vals[$col] = mb_substr((string)$v, 0, (int)$opt); break;
            case 'email': if (!filter_var($v, FILTER_VALIDATE_EMAIL) || strlen((string)$v) > (int)$opt) fail('E-posta adresi geçersiz: ' . $v); $vals[$col] = (string)$v; break;
            case 'phone': if (!preg_match('/^[0-9+()\s.\-]{7,30}$/', (string)$v)) fail('Telefon numarası geçersiz; yalnızca rakam, boşluk, +, ( ) ve - kullanın.'); $vals[$col] = (string)$v; break;
            case 'enum': if (!in_array($v, $opt, true)) fail('Geçersiz değer: ' . $k); $vals[$col] = $v; break;
            case 'date': if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) fail((ETIKET[$k] ?? $k) . ' tarihi geçersiz.'); $vals[$col] = $v; break;
            case 'month': if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$v)) fail('Dönem YYYY-AA biçiminde olmalı.'); $vals[$col] = $v; break;
            case 'money': if (!is_numeric($v) || abs((float)$v) > 1e12) fail((ETIKET[$k] ?? $k) . ' sayı olmalı.'); $vals[$col] = round((float)$v, 2); break;
            case 'pct': if (!is_numeric($v) || $v < 0 || $v > 100) fail('Oran 0 ile 100 arasında olmalı.'); $vals[$col] = (float)$v; break;
            case 'tevkifat': if (!in_array((int)$v, [0, 2, 3, 4, 5, 7, 9, 10], true)) fail('Tevkifat oranı geçersiz.'); $vals[$col] = (int)$v; break;
            case 'ref':
                if (!ctype_digit((string)$v)) fail('Geçersiz bağlantı: ' . $k);
                $st = db()->prepare("SELECT COUNT(*) FROM {$opt} WHERE id = ?"); $st->execute([(int)$v]);
                if (!$st->fetchColumn()) fail((ETIKET[$k] ?? $k) . ' bulunamadı; sayfayı yenileyin.');
                $vals[$col] = (int)$v; break;
        }
    }
    if ($t === 'faturalar' && $vals['matrah'] <= 0) fail('Matrah 0\'dan büyük olmalı.');
    if ($t === 'hareketler' && $vals['tutar'] <= 0) fail('Tutar 0\'dan büyük olmalı.');
    return $vals;
}

function audit(string $t, ?int $id, string $islem, ?array $eski, ?array $yeni): void {
    $u = current_user();
    db()->prepare('INSERT INTO islem_kaydi (kullanici_id, tablo, kayit_id, islem, eski, yeni) VALUES (?,?,?,?,?,?)')
        ->execute([$u['id'] ?? null, $t, $id, $islem, $eski ? json_encode($eski, JSON_UNESCAPED_UNICODE) : null, $yeni ? json_encode($yeni, JSON_UNESCAPED_UNICODE) : null]);
}

function fetch_row(string $t, int $id): ?array {
    $st = db()->prepare("SELECT * FROM {$t} WHERE id = ?"); $st->execute([$id]);
    return $st->fetch() ?: null;
}

function check_dup_fatura(array $vals, ?int $id): void {
    if ($vals['no'] === '') return;
    $st = db()->prepare('SELECT id FROM faturalar WHERE no = ? AND cari_id = ? AND tur = ? AND id <> ?');
    $st->execute([$vals['no'], $vals['cari_id'], $vals['tur'], $id ?? 0]);
    if ($st->fetchColumn()) fail('Bu cari için ' . $vals['no'] . ' numaralı fatura zaten kayıtlı.', 409);
}

/* ---------- ödeme dağıtımı ---------- */
/** Faturanın cariye yansıyan (ödenecek / tahsil edilecek) tutarı — arayüzdeki calcF().net ile aynı formül. */
function fatura_net(array $f): float {
    $m = (float)$f['matrah']; $k = $m * (float)$f['kdv_oran'] / 100;
    return round($m + $k - $k * (int)$f['tevkifat'] / 10 - $m * (float)$f['stopaj_oran'] / 100, 2);
}
function fatura_odenen(int $faturaId, int $haricHareket = 0): float {
    $st = db()->prepare('SELECT COALESCE(SUM(tutar),0) FROM odeme_dagitim WHERE fatura_id = ? AND hareket_id <> ?');
    $st->execute([$faturaId, $haricHareket]);
    return round((float)$st->fetchColumn(), 2);
}
function dagitim_of(int $hareketId): array {
    $st = db()->prepare('SELECT id, hareket_id, fatura_id, tutar FROM odeme_dagitim WHERE hareket_id = ? ORDER BY id');
    $st->execute([$hareketId]);
    return array_map('dagitim_api', $st->fetchAll());
}
function dagitim_api(array $r): array { return ['id' => (string)$r['id'], 'hareketId' => (string)$r['hareket_id'], 'faturaId' => (string)$r['fatura_id'], 'tutar' => (float)$r['tutar']]; }

/** Bir ödemenin fatura dağıtımını doğrular ve yazar. $list null ise mevcut dağıtım korunur, yalnızca tutar kontrol edilir. */
function apply_dagitim(int $hareketId, ?array $list): void {
    $h = fetch_row('hareketler', $hareketId);
    $tutar = round((float)$h['tutar'], 2);
    if ($list === null) {
        $st = db()->prepare('SELECT COALESCE(SUM(tutar),0) FROM odeme_dagitim WHERE hareket_id = ?'); $st->execute([$hareketId]);
        if ((float)$st->fetchColumn() - $tutar > 0.005) fail('Bu ödeme faturalara ' . number_format((float)$st->fetchColumn(), 2, ',', '.') . ' TL dağıtılmış; tutar bundan düşük olamaz. Önce dağıtımı azaltın.');
        return;
    }
    $want = []; $toplam = 0.0;
    foreach ($list as $d) {
        $fid = (string)($d['faturaId'] ?? '');
        $t = round((float)($d['tutar'] ?? 0), 2);
        if ($t <= 0) continue;
        if (!ctype_digit($fid)) fail('Geçersiz fatura bağlantısı.');
        $want[(int)$fid] = round(($want[(int)$fid] ?? 0) + $t, 2);
        $toplam += $t;
    }
    if ($toplam - $tutar > 0.005) fail('Faturalara dağıtılan toplam (' . number_format($toplam, 2, ',', '.') . ' TL) ödeme tutarını aşıyor.');
    $beklenen = $h['tur'] === 'odeme' ? 'gelen' : 'kesilen';
    foreach ($want as $fid => $t) {
        $st = db()->prepare('SELECT * FROM faturalar WHERE id = ? FOR UPDATE'); $st->execute([$fid]);
        $f = $st->fetch() ?: fail('Dağıtımdaki bir fatura bulunamadı; sayfayı yenileyin.', 404);
        if ((int)$f['cari_id'] !== (int)$h['cari_id']) fail(($f['no'] ?: 'Fatura') . ' bu cariye ait değil.');
        if ($f['tur'] !== $beklenen) fail(($f['no'] ?: 'Fatura') . ($h['tur'] === 'odeme' ? ' gelen fatura değil; ödeme yalnızca gelen faturalara dağıtılır.' : ' kesilen fatura değil; tahsilat yalnızca kesilen faturalara dağıtılır.'));
        $kalan = round(fatura_net($f) - fatura_odenen($fid, $hareketId), 2);
        if ($t - $kalan > 0.005) fail(($f['no'] ?: 'Fatura') . ' için en fazla ' . number_format(max(0, $kalan), 2, ',', '.') . ' TL ödenebilir; fatura tutarı aşılamaz.');
    }
    db()->prepare('DELETE FROM odeme_dagitim WHERE hareket_id = ?')->execute([$hareketId]);
    $ins = db()->prepare('INSERT INTO odeme_dagitim (hareket_id, fatura_id, tutar) VALUES (?,?,?)');
    foreach ($want as $fid => $t) $ins->execute([$hareketId, $fid, $t]);
}

function save_record(string $t, array $in): array {
    $vals = validate($t, $in);
    $id = isset($in['id']) && ctype_digit((string)$in['id']) ? (int)$in['id'] : null;
    if ($t === 'faturalar') {
        check_dup_fatura($vals, $id);
        if ($id) {
            $odenen = fatura_odenen($id);
            if ($odenen - fatura_net($vals) > 0.005) fail('Bu faturaya ' . number_format($odenen, 2, ',', '.') . ' TL ödeme bağlı; fatura tutarı bundan düşük olamaz. Önce ödemelerin dağıtımını azaltın.');
        }
    }
    if ($t === 'hareketler' && $id) {
        $eski = fetch_row('hareketler', $id);
        if ($eski && ($eski['cari_id'] != $vals['cari_id'] || $eski['tur'] !== $vals['tur'])) db()->prepare('DELETE FROM odeme_dagitim WHERE hareket_id = ?')->execute([$id]);
    }
    if ($t === 'cariler' && $id && $vals['musteri_id'] === $id) fail('Cari kendisine bağlanamaz.');
    $cols = array_keys($vals);
    if ($id) {
        $old = fetch_row($t, $id) ?? fail('Kayıt bulunamadı; başka biri silmiş olabilir.', 404);
        $sql = "UPDATE {$t} SET " . implode(', ', array_map(fn($c) => "`$c` = ?", $cols)) . ' WHERE id = ?';
        db()->prepare($sql)->execute([...array_values($vals), $id]);
        audit($t, $id, 'guncelle', $old, $vals);
    } else {
        $sql = "INSERT INTO {$t} (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        db()->prepare($sql)->execute(array_values($vals));
        $id = (int)db()->lastInsertId();
        audit($t, $id, 'ekle', null, $vals);
    }
    return to_api($t, fetch_row($t, $id));
}

/* ---------- PDF ---------- */
function upload_error(array $f): ?string {
    return match ($f['error'] ?? UPLOAD_ERR_NO_FILE) {
        UPLOAD_ERR_OK => null,
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'PDF dosyası sunucunun izin verdiği boyuttan büyük (' . ini_get('upload_max_filesize') . ').',
        UPLOAD_ERR_NO_FILE => 'PDF dosyası seçilmedi.',
        default => 'Dosya yüklenemedi (hata kodu ' . $f['error'] . '). Tekrar deneyin.',
    };
}
const UBL_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
/** Yüklenen fatura belgesinin türü: pdf, zip (GİB e-Fatura/e-Arşiv indirmesi) veya xml (UBL). Geçersizse hata. */
function belge_turu(string $tmp, string $ad): string {
    $head = (string)file_get_contents($tmp, false, null, 0, 4096);
    if (str_starts_with($head, '%PDF-')) return 'pdf';
    if (str_starts_with($head, "PK\x03\x04")) {
        if (class_exists('ZipArchive')) {
            $z = new ZipArchive();
            if ($z->open($tmp) !== true) fail('Zip dosyası açılamadı.');
            $ok = false;
            for ($i = 0; $i < $z->numFiles && $i < 200; $i++) {
                $n = (string)$z->getNameIndex($i);
                if (preg_match('/\.xml$/i', $n) && str_contains((string)$z->getFromIndex($i, 8192), UBL_NS)) { $ok = true; break; }
            }
            $z->close();
            if (!$ok) fail('Zip içinde e-Fatura XML dosyası bulunamadı.');
        }
        return 'zip';
    }
    $t = ltrim($head, "\xEF\xBB\xBF \t\r\n");
    if (str_starts_with($t, '<') && str_contains($head, UBL_NS)) return 'xml';
    fail('Yalnızca PDF, e-Fatura zip veya e-Fatura XML dosyası yüklenebilir.');
}
function store_pdf(int $faturaId, array $f, array $fatura): array {
    if ($err = upload_error($f)) fail($err);
    if (!is_uploaded_file($f['tmp_name'])) fail('Dosya yüklenemedi.');
    $max = (int)cfg('pdf_max_mb', 20) * 1024 * 1024;
    if ($f['size'] > $max) fail('Dosya ' . cfg('pdf_max_mb', 20) . ' MB\'tan büyük olamaz.');
    $ext = belge_turu($f['tmp_name'], (string)$f['name']);
    $root = pdf_root();
    $sub = substr($fatura['tarih'], 0, 4) . '/' . substr($fatura['tarih'], 5, 2);
    if (!is_dir("$root/$sub") && !@mkdir("$root/$sub", 0750, true)) fail('Fatura klasörü oluşturulamadı.', 500);
    $slug = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)$fatura['no']) ?: 'fatura';
    $rel = "$sub/{$faturaId}_{$fatura['tur']}_{$slug}_" . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$root/$rel")) fail('Dosya kaydedilemedi; klasör yazma iznini kontrol edin.', 500);
    @chmod("$root/$rel", 0640);
    $old = $fatura['pdf_yol'] ?? null;
    $ad = mb_substr(basename((string)$f['name']), 0, 255);
    db()->prepare('UPDATE faturalar SET pdf_yol = ?, pdf_ad = ?, pdf_boyut = ? WHERE id = ?')->execute([$rel, $ad, (int)$f['size'], $faturaId]);
    if ($old && $old !== $rel) remove_pdf_file($old);
    audit('faturalar', $faturaId, 'pdf', $old ? ['pdf_yol' => $old] : null, ['pdf_yol' => $rel, 'pdf_ad' => $ad]);
    return to_api('faturalar', fetch_row('faturalar', $faturaId));
}
function safe_pdf_path(string $rel): ?string {
    if (str_contains($rel, '..') || !preg_match('#^\d{4}/\d{2}/[A-Za-z0-9_-]+\.(pdf|zip|xml)$#', $rel)) return null;
    $p = pdf_root() . '/' . $rel;
    return is_file($p) ? $p : null;
}
/** e-Fatura HTML görünümünü ayrı, yalıtılmış (sandbox) bir sayfa olarak gösterir; uygulamanın oturumuna erişemez. */
function send_sandboxed_html(string $html): void {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: sandbox allow-scripts allow-modals allow-popups; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; font-src data:");
    header('Cache-Control: private, no-store');
    echo $html;
}
function remove_pdf_file(string $rel): void { if ($p = safe_pdf_path($rel)) @unlink($p); }

/* ---------- yönlendirme ---------- */
$a = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
start_session();

if ($method === 'POST') {
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = ini_bytes(ini_get('post_max_size') ?: '8M');
    if ($postMax > 0 && $len > $postMax) fail('Gönderilen dosya sunucunun sınırından büyük (' . ini_get('post_max_size') . ').', 413);
}

if ($a === 'logo') {
    // Giriş ekranında da gösterildiği için oturum istemez; yalnızca yüklenen logo görseli döner.
    try { $l = db()->query("SELECT anahtar, deger FROM ayarlar WHERE anahtar IN ('logo_data','logo_mime')")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $t) { $l = []; }
    $mime = $l['logo_mime'] ?? ''; $bin = isset($l['logo_data']) ? base64_decode($l['logo_data'], true) : false;
    if (!$bin || !in_array($mime, LOGO_MIME, true)) { http_response_code(404); exit; }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: public, max-age=31536000, immutable');
    header("Content-Security-Policy: default-src 'none'");
    echo $bin; exit;
}

if ($a === 'login' && $method === 'POST') {
    $ip = client_ip();
    db()->prepare('DELETE FROM giris_denemeleri WHERE zaman < NOW() - INTERVAL 1 DAY')->execute();
    $st = db()->prepare('SELECT COUNT(*) FROM giris_denemeleri WHERE ip = ? AND zaman > NOW() - INTERVAL 15 MINUTE');
    $st->execute([$ip]);
    if ($st->fetchColumn() >= 8) fail('Çok fazla hatalı giriş denemesi. 15 dakika sonra tekrar deneyin.', 429);
    $b = body();
    $st = db()->prepare('SELECT * FROM kullanicilar WHERE kullanici = ?');
    $st->execute([trim((string)($b['kullanici'] ?? ''))]);
    $u = $st->fetch();
    if (!$u || !password_verify((string)($b['sifre'] ?? ''), $u['sifre_hash'])) {
        db()->prepare('INSERT INTO giris_denemeleri (ip) VALUES (?)')->execute([$ip]);
        fail('Kullanıcı adı veya şifre hatalı.', 401);
    }
    if (password_needs_rehash($u['sifre_hash'], PASSWORD_DEFAULT)) db()->prepare('UPDATE kullanicilar SET sifre_hash = ? WHERE id = ?')->execute([password_hash((string)$b['sifre'], PASSWORD_DEFAULT), $u['id']]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id']; $_SESSION['son'] = time(); $_SESSION['csrf'] = bin2hex(random_bytes(24));
    db()->prepare('UPDATE kullanicilar SET son_giris = NOW() WHERE id = ?')->execute([$u['id']]);
    out(['ok' => true]);
}

$user = current_user();
if (!$user) fail('Oturum kapandı. Yeniden giriş yapın.', 401);

if ($method === 'POST' && !hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Oturum doğrulanamadı; sayfayı yenileyin.', 403);
ensure_schema();
$isAdmin = $user['rol'] === 'admin';
$needAdmin = fn() => $isAdmin ?: fail('Bu işlem için yönetici yetkisi gerekiyor.', 403);

try {
switch ($a) {
    case 'me':
        out(['ok' => true, 'user' => $user, 'csrf' => $_SESSION['csrf'], 'ayarlar' => ayarlar_genel(), 'pdfMaxMb' => min((int)cfg('pdf_max_mb', 20), (int)(ini_bytes(ini_get('upload_max_filesize') ?: '2M') / 1048576) ?: 20)]);

    case 'logout':
        $_SESSION = []; session_destroy(); out(['ok' => true]);

    case 'all':
        $r = [];
        foreach (array_keys(ENT) as $t) $r[$t] = array_map(fn($row) => to_api($t, $row), db()->query("SELECT * FROM {$t} ORDER BY id")->fetchAll());
        $r['dagitim'] = array_map('dagitim_api', db()->query('SELECT id, hareket_id, fatura_id, tutar FROM odeme_dagitim ORDER BY id')->fetchAll());
        out(['ok' => true, 'data' => $r]);

    case 'save':
        $needAdmin();
        $t = $_GET['t'] ?? '';
        if (!isset(ENT[$t])) fail('Geçersiz kayıt türü.');
        db()->beginTransaction();
        $rec = save_record($t, body());
        $extra = [];
        if ($t === 'hareketler') {
            $b = body();
            apply_dagitim((int)$rec['id'], array_key_exists('dagitim', $b) && is_array($b['dagitim']) ? $b['dagitim'] : null);
            $extra['dagitim'] = dagitim_of((int)$rec['id']);
        }
        db()->commit();
        out(['ok' => true, 'rec' => $rec] + $extra);

    case 'delete':
        $needAdmin();
        $t = $_GET['t'] ?? '';
        if (!isset(ENT[$t])) fail('Geçersiz kayıt türü.');
        $id = (int)(body()['id'] ?? 0);
        $old = fetch_row($t, $id) ?? fail('Kayıt bulunamadı.', 404);
        try { db()->prepare("DELETE FROM {$t} WHERE id = ?")->execute([$id]); }
        catch (PDOException $ex) {
            if ($ex->getCode() === '23000') fail($t === 'cariler' ? 'Bu cariye bağlı fatura, ödeme, proje veya personel var. Önce onları silin ya da cariyi Pasif yapın.' : 'Bu projeye bağlı kayıtlar var. Önce onları silin ya da projeyi İptal yapın.', 409);
            throw $ex;
        }
        if ($t === 'faturalar' && !empty($old['pdf_yol'])) remove_pdf_file($old['pdf_yol']);
        audit($t, $id, 'sil', $old, null);
        out(['ok' => true]);

    case 'pdf_upload':
        $needAdmin();
        $id = (int)($_POST['fatura_id'] ?? 0);
        $f = fetch_row('faturalar', $id) ?? fail('Fatura bulunamadı.', 404);
        out(['ok' => true, 'rec' => store_pdf($id, $_FILES['pdf'] ?? [], $f)]);

    case 'pdf_delete':
        $needAdmin();
        $id = (int)(body()['id'] ?? 0);
        $f = fetch_row('faturalar', $id) ?? fail('Fatura bulunamadı.', 404);
        if ($f['pdf_yol']) { remove_pdf_file($f['pdf_yol']); db()->prepare('UPDATE faturalar SET pdf_yol = NULL, pdf_ad = NULL, pdf_boyut = NULL WHERE id = ?')->execute([$id]); audit('faturalar', $id, 'pdf_sil', ['pdf_yol' => $f['pdf_yol']], null); }
        out(['ok' => true, 'rec' => to_api('faturalar', fetch_row('faturalar', $id))]);

    case 'import':
        // Toplu arşiv: fatura bilgisi + PDF tek istekte; PDF kaydedilemezse fatura da eklenmez.
        $needAdmin();
        $in = json_decode((string)($_POST['data'] ?? ''), true);
        if (!is_array($in)) fail('Fatura bilgisi okunamadı.');
        unset($in['id']);
        if ($err = upload_error($_FILES['pdf'] ?? [])) fail($err);
        db()->beginTransaction();
        try {
            $rec = save_record('faturalar', $in);
            $rec = store_pdf((int)$rec['id'], $_FILES['pdf'], fetch_row('faturalar', (int)$rec['id']));
            db()->commit();
        } catch (Throwable $t) { if (db()->inTransaction()) db()->rollBack(); throw $t; }
        out(['ok' => true, 'rec' => $rec]);

    case 'pdf':
        $id = (int)($_GET['id'] ?? 0);
        $f = fetch_row('faturalar', $id);
        $p = $f && $f['pdf_yol'] ? safe_pdf_path($f['pdf_yol']) : null;
        if (!$p) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Fatura belgesi bulunamadı.'); }
        session_write_close();
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $name = $f['pdf_ad'] ?: ('fatura-' . $id . '.' . $ext);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        $indir = isset($_GET['indir']);
        if (!$indir && $ext === 'zip') {
            if (!class_exists('ZipArchive')) { header('Content-Type: text/plain; charset=utf-8'); exit('Sunucuda PHP zip eklentisi kapalı; e-Faturayı görüntülemek için İndir düğmesini kullanın.'); }
            $z = new ZipArchive(); $html = null; $xml = null;
            if ($z->open($p) === true) {
                for ($i = 0; $i < $z->numFiles && $i < 200; $i++) {
                    $n = (string)$z->getNameIndex($i);
                    if ($html === null && preg_match('/\.html?$/i', $n)) $html = (string)$z->getFromIndex($i);
                    if ($xml === null && preg_match('/\.xml$/i', $n)) $xml = (string)$z->getFromIndex($i);
                }
                $z->close();
            }
            if ($html !== null) { send_sandboxed_html($html); exit; }
            if ($xml !== null) { header('Content-Type: text/plain; charset=utf-8'); echo $xml; exit; }
            header('Content-Type: text/plain; charset=utf-8'); exit('Zip içinde görüntülenecek dosya yok.');
        }
        if (!$indir && $ext === 'xml') { header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: private, no-store'); readfile($p); exit; }
        header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : ($ext === 'zip' ? 'application/zip' : 'application/xml')));
        header('Content-Length: ' . filesize($p));
        header('Content-Disposition: ' . ($indir || $ext !== 'pdf' ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: private, no-store');
        readfile($p);
        exit;

    case 'ayar_kaydet':
        $needAdmin();
        $b = body(); $yeni = [];
        if (array_key_exists('firma_ad', $b)) $yeni['firma_ad'] = mb_substr(trim((string)$b['firma_ad']), 0, 150);
        if (array_key_exists('firma_vkn', $b)) { $v = preg_replace('/\D/', '', (string)$b['firma_vkn']); if ($v !== '' && !preg_match('/^\d{10,11}$/', $v)) fail('VKN 10, TCKN 11 haneli olmalı.'); $yeni['firma_vkn'] = $v; }
        if (array_key_exists('uygulama_ad', $b)) { $v = mb_substr(trim((string)$b['uygulama_ad']), 0, 60); $yeni['uygulama_ad'] = $v !== '' ? $v : AYAR_VARSAYILAN['uygulama_ad']; }
        if (array_key_exists('kdv_oranlari', $b)) {
            $list = [];
            foreach (preg_split('/[;,\s]+/', (string)$b['kdv_oranlari'], -1, PREG_SPLIT_NO_EMPTY) as $x) { $x = str_replace(',', '.', $x); if (!is_numeric($x) || $x < 0 || $x > 100) fail('KDV oranları 0 ile 100 arasında sayılar olmalı (ör. 20, 10, 1, 0).'); $list[] = rtrim(rtrim(number_format((float)$x, 2, '.', ''), '0'), '.'); }
            $list = array_values(array_unique($list)); if (!$list) fail('En az bir KDV oranı girin.');
            $yeni['kdv_oranlari'] = implode(',', $list);
        }
        if (array_key_exists('varsayilan_kdv', $b)) {
            $v = rtrim(rtrim(number_format((float)str_replace(',', '.', (string)$b['varsayilan_kdv']), 2, '.', ''), '0'), '.');
            $liste = explode(',', $yeni['kdv_oranlari'] ?? ayar('kdv_oranlari'));
            if (!in_array($v, $liste, true)) fail('Varsayılan KDV oranı, KDV oranları listesinde olmalı.');
            $yeni['varsayilan_kdv'] = $v;
        }
        if (array_key_exists('odeme_yontemleri', $b)) {
            $l = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 40), preg_split('/\r?\n/', (string)$b['odeme_yontemleri'])))));
            if (!$l) fail('En az bir ödeme yöntemi girin.'); if (count($l) > 20) fail('En fazla 20 ödeme yöntemi girilebilir.');
            $yeni['odeme_yontemleri'] = implode("\n", $l);
        }
        if (array_key_exists('logo_koyu', $b)) { $v = (string)$b['logo_koyu']; if (!in_array($v, ['normal', 'beyaz', 'ters'], true)) fail('Geçersiz logo görünümü.'); $yeni['logo_koyu'] = $v; }
        if (array_key_exists('ag_gun', $b)) { $v = (int)$b['ag_gun']; if ($v < 0 || $v > 31) fail('Gün sayısı 0 ile 31 arasında olmalı.'); $yeni['ag_gun'] = (string)$v; }
        foreach ($yeni as $k => $v) ayar_yaz($k, $v);
        audit('ayarlar', null, 'guncelle', null, $yeni);
        out(['ok' => true, 'ayarlar' => ayarlar_genel(true)]);

    case 'logo_yukle':
        $needAdmin();
        $f = $_FILES['logo'] ?? [];
        if ($err = upload_error($f)) fail(str_replace('PDF dosyası', 'Logo dosyası', $err));
        if (!is_uploaded_file($f['tmp_name'])) fail('Dosya yüklenemedi.');
        if ($f['size'] > 1048576) fail('Logo en fazla 1 MB olabilir.');
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info['mime'], LOGO_MIME, true)) fail('Logo PNG, JPG, WEBP veya GIF olmalı.');
        $bin = (string)file_get_contents($f['tmp_name']);
        ayar_yaz('logo_data', base64_encode($bin)); ayar_yaz('logo_mime', $info['mime']); ayar_yaz('logo_surum', substr(md5($bin), 0, 10));
        audit('ayarlar', null, 'logo', null, ['mime' => $info['mime'], 'boyut' => strlen($bin)]);
        out(['ok' => true, 'ayarlar' => ayarlar_genel(true)]);

    case 'logo_sil':
        $needAdmin();
        db()->prepare("DELETE FROM ayarlar WHERE anahtar IN ('logo_data','logo_mime','logo_surum')")->execute();
        audit('ayarlar', null, 'logo_sil', null, null);
        out(['ok' => true, 'ayarlar' => ayarlar_genel(true)]);

    case 'users':
        $needAdmin();
        out(['ok' => true, 'users' => db()->query('SELECT id, kullanici, ad, rol, son_giris FROM kullanicilar ORDER BY id')->fetchAll()]);

    case 'user_save':
        $needAdmin();
        $b = body();
        $id = (int)($b['id'] ?? 0);
        $rol = in_array($b['rol'] ?? '', ['admin', 'okur'], true) ? $b['rol'] : 'okur';
        $ad = mb_substr(trim((string)($b['ad'] ?? '')), 0, 120);
        $pass = (string)($b['sifre'] ?? '');
        if ($pass !== '' && mb_strlen($pass) < 10) fail('Şifre en az 10 karakter olmalı.');
        if ($id) {
            if ($id === (int)$user['id'] && $rol !== 'admin') fail('Kendi yönetici yetkinizi kaldıramazsınız.');
            db()->prepare('UPDATE kullanicilar SET ad = ?, rol = ? WHERE id = ?')->execute([$ad, $rol, $id]);
            if ($pass !== '') db()->prepare('UPDATE kullanicilar SET sifre_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        } else {
            $k = trim((string)($b['kullanici'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $k)) fail('Kullanıcı adı en az 3 karakter olmalı; harf, rakam, nokta ve tire kullanın.');
            if ($pass === '') fail('Yeni kullanıcı için şifre girin.');
            try { db()->prepare('INSERT INTO kullanicilar (kullanici, ad, sifre_hash, rol) VALUES (?,?,?,?)')->execute([$k, $ad ?: $k, password_hash($pass, PASSWORD_DEFAULT), $rol]); }
            catch (PDOException $ex) { fail('Bu kullanıcı adı zaten var.', 409); }
        }
        audit('kullanicilar', $id ?: (int)db()->lastInsertId(), $id ? 'guncelle' : 'ekle', null, ['rol' => $rol, 'ad' => $ad]);
        out(['ok' => true]);

    case 'user_delete':
        $needAdmin();
        $id = (int)(body()['id'] ?? 0);
        if ($id === (int)$user['id']) fail('Kendi hesabınızı silemezsiniz.');
        db()->prepare('DELETE FROM kullanicilar WHERE id = ?')->execute([$id]);
        audit('kullanicilar', $id, 'sil', null, null);
        out(['ok' => true]);

    case 'password':
        $b = body();
        $st = db()->prepare('SELECT sifre_hash FROM kullanicilar WHERE id = ?'); $st->execute([$user['id']]);
        if (!password_verify((string)($b['eski'] ?? ''), (string)$st->fetchColumn())) fail('Mevcut şifre hatalı.');
        if (mb_strlen((string)($b['yeni'] ?? '')) < 10) fail('Yeni şifre en az 10 karakter olmalı.');
        db()->prepare('UPDATE kullanicilar SET sifre_hash = ? WHERE id = ?')->execute([password_hash((string)$b['yeni'], PASSWORD_DEFAULT), $user['id']]);
        out(['ok' => true]);

    case 'log':
        $needAdmin();
        $st = db()->query('SELECT l.zaman, l.tablo, l.kayit_id, l.islem, k.ad AS kullanici FROM islem_kaydi l LEFT JOIN kullanicilar k ON k.id = l.kullanici_id ORDER BY l.id DESC LIMIT 200');
        out(['ok' => true, 'log' => $st->fetchAll()]);

    default:
        fail('Bilinmeyen istek.', 404);
}
} catch (PDOException $ex) {
    error_log('Cari Takip: ' . $ex->getMessage());
    fail('Veritabanı hatası. Tekrar deneyin; sürerse hosting hata kaydına bakın.', 500);
}
