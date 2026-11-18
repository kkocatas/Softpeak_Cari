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
        'vadeGun' => ['vade_gun', 'int', [0, 365]],
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
            'tevkifat', 'int' => (int)$v,
            default => $v ?? '',
        };
    }
    if ($t === 'faturalar') { $o['pdfAd'] = $row['pdf_ad'] ?? ''; $o['pdfBoyut'] = (int)($row['pdf_boyut'] ?? 0); $o['pdf'] = !empty($row['pdf_yol']); $o['belgeTur'] = $o['pdf'] ? strtolower(pathinfo((string)$row['pdf_yol'], PATHINFO_EXTENSION)) : ''; $o['kaynak'] = $row['kaynak'] ?? ''; }
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
            $vals[$col] = match ($type) { 'pct' => ($col === 'kdv_oran' ? (float)(ayar('varsayilan_kdv') ?: 20) : 0), 'money', 'tevkifat', 'int' => 0, 'str', 'email', 'phone' => '', 'enum' => $opt[0], default => null };
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
            case 'int': if (!preg_match('/^\d+$/', (string)$v) || (int)$v < $opt[0] || (int)$v > $opt[1]) fail('Vade ' . $opt[0] . ' ile ' . $opt[1] . ' gün arasında tam sayı olmalı.'); $vals[$col] = (int)$v; break;
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
/** Güncelleme zip'ini doğrular, mevcut dosyaların yedeğini alır ve yeni dosyaları yazar. */
function guncelleme_kur(string $zipYol): array {
    $z = new ZipArchive();
    if ($z->open($zipYol) !== true) fail('Dosya geçerli bir zip değil.');
    $adlar = [];
    for ($i = 0; $i < $z->numFiles; $i++) { $n = str_replace('\\', '/', (string)$z->getNameIndex($i)); if (!str_ends_with($n, '/')) $adlar[$i] = $n; }
    // Zip tek bir klasörle başlıyorsa (ör. softpeak-cari/index.php) o klasörü at.
    $onek = '';
    if (!in_array('index.php', $adlar, true)) {
        foreach ($adlar as $n) if (preg_match('#^([^/]+/)index\.php$#', $n, $m)) { $onek = $m[1]; break; }
    }
    $izin = '#^(index\.php|api\.php|README\.md|assets/[A-Za-z0-9_./-]+\.(js|css|txt|md|png|svg|webp|woff2?)|lib/(bootstrap\.php|schema\.sql|\.htaccess|[A-Za-z0-9_-]+\.php))$#';
    $yaz = [];
    foreach ($adlar as $i => $n) {
        if ($onek !== '') { if (!str_starts_with($n, $onek)) continue; $n = substr($n, strlen($onek)); }
        if ($n === '' || str_contains($n, '..') || str_starts_with($n, '/')) continue;
        if (!preg_match($izin, $n)) continue; // config.php, setup.php, kök .htaccess/web.config vb. atlanır
        $yaz[$n] = $i;
    }
    foreach (['index.php', 'api.php', 'assets/app.js', 'lib/bootstrap.php'] as $zorunlu) if (!isset($yaz[$zorunlu])) fail('Bu zip bir Cari Takip güncellemesi değil (' . $zorunlu . ' yok).');
    $yeniIndex = (string)$z->getFromIndex($yaz['index.php']);
    if (!preg_match("/\\\$v = '(\\d+)';/", $yeniIndex, $m)) fail('Güncellemenin sürümü okunamadı.');
    $yeniSurum = (int)$m[1];
    $kok = __DIR__;
    $mevcut = preg_match("/\\\$v = '(\\d+)';/", (string)@file_get_contents($kok . '/index.php'), $mm) ? (int)$mm[1] : 0;
    if ($yeniSurum < $mevcut) fail("Bu paket daha eski bir sürüm ($yeniSurum); kurulu sürüm $mevcut.");
    // Mevcut dosyaların yedeği (geri dönüş için), dışarıdan erişilemeyen arşiv klasörüne
    $yd = pdf_root() . '/_guncelleme';
    if (!is_dir($yd) && !@mkdir($yd, 0750, true)) fail('Yedek klasörü oluşturulamadı.', 500);
    $yz = new ZipArchive();
    if ($yz->open("$yd/onceki-surum-$mevcut-" . date('Ymd-His') . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach (array_keys($yaz) as $n) if (is_file("$kok/$n")) $yz->addFile("$kok/$n", $n);
        $yz->close();
    }
    // Önce hepsi okunur, sonra yazılır (yarım kalma riskini azaltır)
    $icerik = [];
    foreach ($yaz as $n => $i) { $c = $z->getFromIndex($i); if ($c === false) fail("$n okunamadı."); $icerik[$n] = $c; }
    $z->close();
    $hata = [];
    foreach ($icerik as $n => $c) {
        $hedef = "$kok/$n"; $dir = dirname($hedef);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $hata[] = $n; continue; }
        if (@file_put_contents($hedef . '.yeni', $c, LOCK_EX) === false || !@rename($hedef . '.yeni', $hedef)) {
            @unlink($hedef . '.yeni');
            if (@file_put_contents($hedef, $c, LOCK_EX) === false) $hata[] = $n;
        }
    }
    if (function_exists('opcache_reset')) @opcache_reset();
    unset($_SESSION['schema']);
    audit('ayarlar', null, 'guncelle', ['surum' => $mevcut], ['surum' => $yeniSurum, 'dosya' => count($icerik), 'hata' => $hata]);
    if ($hata) fail('Bazı dosyalar yazılamadı (klasör yazma izni?): ' . implode(', ', $hata) . '. Önceki sürümün yedeği PDF klasöründeki _guncelleme içinde.', 500);
    return ['ok' => true, 'onceki' => $mevcut, 'surum' => $yeniSurum, 'dosya' => count($icerik)];
}
/* ---------- sözleşme ve belge ekleri (müşteri, personel, proje) ---------- */
const BELGE_TUR = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'odt' => 'application/vnd.oasis.opendocument.text',
    'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation', 'rtf' => 'application/rtf',
    'txt' => 'text/plain', 'csv' => 'text/csv', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
function belge_api(array $r): array {
    return ['id' => (string)$r['id'], 'sahipTur' => $r['sahip_tur'], 'sahipId' => (string)$r['sahip_id'], 'ad' => $r['ad'], 'aciklama' => $r['aciklama'],
        'tur' => strtolower(pathinfo((string)$r['dosya_yol'], PATHINFO_EXTENSION)), 'boyut' => (int)$r['boyut'], 'tarih' => (string)$r['olusturma']];
}
/** Dosyanın uzantısı ile içeriği uyuşuyor mu (ör. .docx gerçekten Office dosyası mı). */
function belge_dogrula(string $tmp, string $ext): void {
    $h = (string)file_get_contents($tmp, false, null, 0, 8192);
    $ok = match ($ext) {
        'pdf' => str_starts_with($h, '%PDF-'),
        'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp' => str_starts_with($h, "PK\x03\x04"),
        'doc', 'xls', 'ppt' => str_starts_with($h, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
        'rtf' => str_starts_with($h, '{\rtf'),
        'png', 'jpg', 'jpeg' => (bool)@getimagesize($tmp),
        'txt', 'csv' => !str_contains($h, "\0"),
        default => false,
    };
    if (!$ok) fail('Dosyanın içeriği uzantısıyla uyuşmuyor (' . strtoupper($ext) . ' değil gibi görünüyor).');
}
function safe_belge_path(string $rel): ?string {
    if (str_contains($rel, '..') || !preg_match('#^belgeler/\d{4}/[A-Za-z0-9_-]+\.[a-z]{2,4}$#', $rel)) return null;
    $p = pdf_root() . '/' . $rel;
    return is_file($p) ? $p : null;
}
function belgeleri_sil(string $tur, int $id): void {
    $st = db()->prepare('SELECT dosya_yol FROM belgeler WHERE sahip_tur = ? AND sahip_id = ?'); $st->execute([$tur, $id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rel) if ($p = safe_belge_path((string)$rel)) @unlink($p);
    db()->prepare('DELETE FROM belgeler WHERE sahip_tur = ? AND sahip_id = ?')->execute([$tur, $id]);
}

/** Fatura belgesini (PDF / e-Fatura zip / XML) gönderir. Yetki kontrolü çağıran tarafta yapılır. */
function send_fatura_belgesi(array $f): void {
    $id = (int)$f['id'];
    $p = $f['pdf_yol'] ? safe_pdf_path($f['pdf_yol']) : null;
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
}

/* ---------- personel girişi: yalnızca kendi kartı, kendi faturaları ve kendisine yapılan ödemeler ---------- */
/** Personelin görebileceği kart alanları: müşteriye satış fiyatı (Müşteri A/G) ve notlar asla gönderilmez. */
function personel_karti(array $c): array {
    $mus = '';
    if ($c['musteri_id']) { $st = db()->prepare('SELECT ad FROM cariler WHERE id = ?'); $st->execute([$c['musteri_id']]); $mus = (string)$st->fetchColumn(); }
    return ['id' => (string)$c['id'], 'ad' => $c['ad'], 'vkn' => $c['vkn'], 'eposta' => $c['eposta'] ?? '', 'telefon' => $c['telefon'] ?? '',
        'aylikAlis' => (float)$c['aylik_alis'], 'kdvOran' => (float)$c['kdv_oran'], 'vadeGun' => (int)($c['vade_gun'] ?? 0), 'musteriAd' => $mus, 'baslangic' => $c['baslangic'] ?? ''];
}
function personel_fatura_api(array $row): array {
    $o = to_api('faturalar', $row);
    unset($o['personelId'], $o['projeId']);
    $o['kaynak'] = $row['kaynak'] ?? '';
    return $o;
}
function personel_islem(string $a, array $user, string $method): void {
    $cid = (int)($user['cari_id'] ?? 0);
    $c = $cid ? fetch_row('cariler', $cid) : null;
    if (!$c || $c['tip'] !== 'personel') fail('Hesabınız bir personel kartına bağlı değil. Yöneticiye başvurun.', 403);
    $kendi = function (int $fid) use ($cid): array {
        $f = fetch_row('faturalar', $fid);
        if (!$f || (int)$f['cari_id'] !== $cid || $f['tur'] !== 'gelen') fail('Fatura bulunamadı.', 404);
        return $f;
    };
    switch ($a) {
        case 'me':
            out(['ok' => true, 'user' => $user, 'csrf' => $_SESSION['csrf'], 'ayarlar' => array_intersect_key(ayarlar_genel(), array_flip(['firma_ad', 'uygulama_ad', 'logo_surum', 'logo_koyu', 'kdv_oranlari', 'varsayilan_kdv'])), 'pdfMaxMb' => min((int)cfg('pdf_max_mb', 20), (int)(ini_bytes(ini_get('upload_max_filesize') ?: '2M') / 1048576) ?: 20)]);
        case 'logout':
            hatirla_bu_cihaz_sil(); $_SESSION = []; session_destroy(); out(['ok' => true]);
        case 'benim':
            $st = db()->prepare("SELECT * FROM faturalar WHERE cari_id = ? AND tur = 'gelen' ORDER BY tarih, id"); $st->execute([$cid]);
            $fat = array_map('personel_fatura_api', $st->fetchAll());
            $st = db()->prepare("SELECT * FROM hareketler WHERE cari_id = ? AND tur = 'odeme' ORDER BY tarih, id"); $st->execute([$cid]);
            $har = array_map(function ($r) { $o = to_api('hareketler', $r); unset($o['projeId']); return $o; }, $st->fetchAll());
            $st = db()->prepare("SELECT d.id, d.hareket_id, d.fatura_id, d.tutar FROM odeme_dagitim d JOIN hareketler h ON h.id = d.hareket_id WHERE h.cari_id = ? AND h.tur = 'odeme' ORDER BY d.id"); $st->execute([$cid]);
            out(['ok' => true, 'kart' => personel_karti($c), 'faturalar' => $fat, 'hareketler' => $har, 'dagitim' => array_map('dagitim_api', $st->fetchAll())]);
        case 'p_fatura_kaydet':
            // Fatura bilgisi (JSON, data alanında) + isteğe bağlı belge, tek istekte
            $in = json_decode((string)($_POST['data'] ?? ''), true);
            if (!is_array($in)) fail('Fatura bilgisi okunamadı.');
            $id = isset($in['id']) && ctype_digit((string)$in['id']) ? (int)$in['id'] : 0;
            $eski = null;
            if ($id) {
                $eski = $kendi($id);
                if (($eski['kaynak'] ?? '') !== 'personel') fail('Bu faturayı yönetici girdiği için değiştiremezsiniz.', 403);
                if (fatura_odenen($id) > 0) fail('Bu faturaya ödeme yapıldığı için değiştirilemez.', 409);
            }
            $girdi = ['tur' => 'gelen', 'cariId' => (string)$cid, 'personelId' => '', 'projeId' => $eski && $eski['proje_id'] ? (string)$eski['proje_id'] : '',
                'vade' => $eski['vade'] ?? ''];
            foreach (['no', 'tarih', 'donem', 'matrah', 'kdvOran', 'tevkifat', 'stopajOran', 'aciklama'] as $k) $girdi[$k] = $in[$k] ?? null;
            if ($id) $girdi['id'] = (string)$id;
            $dosya = $_FILES['pdf'] ?? null;
            if (!$id && (!$dosya || ($dosya['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) fail('Fatura belgesini (PDF veya e-Fatura zip) ekleyin.');
            db()->beginTransaction();
            try {
                $rec = save_record('faturalar', $girdi);
                db()->prepare("UPDATE faturalar SET kaynak = 'personel' WHERE id = ?")->execute([(int)$rec['id']]);
                if ($dosya && ($dosya['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) store_pdf((int)$rec['id'], $dosya, fetch_row('faturalar', (int)$rec['id']));
                db()->commit();
            } catch (Throwable $t) { if (db()->inTransaction()) db()->rollBack(); throw $t; }
            out(['ok' => true, 'rec' => personel_fatura_api(fetch_row('faturalar', (int)$rec['id']))]);
        case 'p_fatura_sil':
            $id = (int)(body()['id'] ?? 0);
            $f = $kendi($id);
            if (($f['kaynak'] ?? '') !== 'personel') fail('Bu faturayı yönetici girdiği için silemezsiniz.', 403);
            if (fatura_odenen($id) > 0) fail('Bu faturaya ödeme yapıldığı için silinemez.', 409);
            db()->prepare('DELETE FROM faturalar WHERE id = ?')->execute([$id]);
            if (!empty($f['pdf_yol'])) remove_pdf_file($f['pdf_yol']);
            audit('faturalar', $id, 'sil', $f, null);
            out(['ok' => true]);
        case 'pdf':
            send_fatura_belgesi($kendi((int)($_GET['id'] ?? 0)));
        case 'password':
            sifre_degistir($user);
        case 'cihazlar':
            $st = db()->prepare('SELECT id, cihaz, olusturma, son_kullanim, son_kullanma FROM oturum_hatirla WHERE kullanici_id = ? AND son_kullanma > NOW() ORDER BY COALESCE(son_kullanim, olusturma) DESC'); $st->execute([$user['id']]);
            out(['ok' => true, 'cihazlar' => array_map(fn($r) => $r + ['bu' => (int)$r['id'] === (int)($_SESSION['hatirla_id'] ?? 0)], $st->fetchAll())]);
        case 'cihaz_sil':
            $id = (int)(body()['id'] ?? 0);
            if ($id) db()->prepare('DELETE FROM oturum_hatirla WHERE id = ? AND kullanici_id = ?')->execute([$id, $user['id']]);
            else db()->prepare('DELETE FROM oturum_hatirla WHERE kullanici_id = ?')->execute([$user['id']]);
            out(['ok' => true]);
        default:
            fail('Bu işlem için yetkiniz yok.', 403);
    }
}
function sifre_degistir(array $user): void {
    $b = body();
    $st = db()->prepare('SELECT sifre_hash FROM kullanicilar WHERE id = ?'); $st->execute([$user['id']]);
    if (!password_verify((string)($b['eski'] ?? ''), (string)$st->fetchColumn())) fail('Mevcut şifre hatalı.');
    if (mb_strlen((string)($b['yeni'] ?? '')) < 10) fail('Yeni şifre en az 10 karakter olmalı.');
    if (hash_equals((string)($b['eski'] ?? ''), (string)$b['yeni'])) fail('Yeni şifre eskisiyle aynı olamaz.');
    db()->prepare('UPDATE kullanicilar SET sifre_hash = ?, sifre_degis = 0 WHERE id = ?')->execute([password_hash((string)$b['yeni'], PASSWORD_DEFAULT), $user['id']]);
    try { db()->prepare('DELETE FROM oturum_hatirla WHERE kullanici_id = ? AND id <> ?')->execute([$user['id'], (int)($_SESSION['hatirla_id'] ?? 0)]); } catch (PDOException $e) {}
    out(['ok' => true]);
}

/* ---------- notlar ---------- */
function not_api(array $r): array { return ['id' => (string)$r['id'], 'sahipTur' => $r['sahip_tur'], 'sahipId' => (string)$r['sahip_id'], 'metin' => $r['metin'], 'yazan' => (string)($r['yazan'] ?? ''), 'tarih' => (string)$r['olusturma']]; }
function sahip_tablosu(string $tur): ?string { return ['cari' => 'cariler', 'proje' => 'projeler', 'fatura' => 'faturalar'][$tur] ?? null; }
function ekleri_sil(string $tur, int $id): void { belgeleri_sil($tur, $id); db()->prepare('DELETE FROM notlar WHERE sahip_tur = ? AND sahip_id = ?')->execute([$tur, $id]); }

/** Tüm tabloların yapısı ve kayıtları (phpMyAdmin ile içe aktarılabilir SQL). */
function sql_dump(): string {
    $pdo = db();
    $out = "-- Cari Takip veritabanı yedeği · " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if ($t === 'giris_denemeleri') continue;
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $out .= "DROP TABLE IF EXISTS `$t`;\n$create;\n";
        $rows = $pdo->query("SELECT * FROM `$t`");
        $buf = [];
        while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
            $buf[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
            if (count($buf) >= 200) { $out .= "INSERT INTO `$t` VALUES\n" . implode(",\n", $buf) . ";\n"; $buf = []; }
        }
        if ($buf) $out .= "INSERT INTO `$t` VALUES\n" . implode(",\n", $buf) . ";\n";
        $out .= "\n";
    }
    return $out . "SET FOREIGN_KEY_CHECKS = 1;\n";
}
function remove_pdf_file(string $rel): void { if ($p = safe_pdf_path($rel)) @unlink($p); }

/* ---------- yönlendirme ---------- */
$a = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/* ---------- telefona kurulum (PWA): oturum istemez ---------- */
if ($a === 'manifest') {
    $ad = 'Cari Takip'; $kisa = 'Cari';
    try { $ad = marka_ad(); $u = trim((string)ayar('uygulama_ad')); if ($u !== '') $kisa = mb_substr($u, 0, 12); } catch (Throwable $t) {}
    $ikon = fn(int $s, string $amac = 'any') => ['src' => 'api.php?a=ikon&s=' . $s . ($amac === 'maskable' ? '&m=1' : '') . '&v=' . rawurlencode((string)(ayar_guvenli('logo_surum'))), 'sizes' => "{$s}x{$s}", 'type' => 'image/png', 'purpose' => $amac];
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-cache');
    echo json_encode([
        'name' => $ad, 'short_name' => $kisa, 'lang' => 'tr', 'dir' => 'ltr',
        'start_url' => './', 'scope' => './', 'id' => './', 'display' => 'standalone', 'orientation' => 'any',
        'background_color' => '#f3f4f7', 'theme_color' => '#2f56d6',
        'description' => 'Fatura, ödeme ve cari hesap takibi',
        'icons' => [$ikon(192), $ikon(512), $ikon(512, 'maskable')],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($a === 'ikon') {
    // Ana ekran ikonu: firma logosu yüklüyse beyaz kare üzerinde logo, yoksa uygulamanın varsayılan ikonu.
    $s = (int)($_GET['s'] ?? 192); $s = in_array($s, [180, 192, 512], true) ? $s : 192; $maskeli = !empty($_GET['m']);
    $png = null;
    try {
        $l = db()->query("SELECT anahtar, deger FROM ayarlar WHERE anahtar IN ('logo_data','logo_mime')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $bin = isset($l['logo_data']) ? base64_decode($l['logo_data'], true) : false;
        if ($bin && function_exists('imagecreatefromstring') && in_array($l['logo_mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true) && ($src = @imagecreatefromstring($bin))) {
            $im = imagecreatetruecolor($s, $s);
            imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
            imagealphablending($im, true);
            $w = imagesx($src); $h = imagesy($src); $kutu = $s * ($maskeli || $s === 180 ? 0.62 : 0.76);
            $k = min($kutu / max(1, $w), $kutu / max(1, $h)); $nw = (int)round($w * $k); $nh = (int)round($h * $k);
            imagecopyresampled($im, $src, (int)(($s - $nw) / 2), (int)(($s - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
            ob_start(); imagepng($im); $png = ob_get_clean();
        }
    } catch (Throwable $t) { $png = null; }
    if ($png === null) {
        $dosya = __DIR__ . '/assets/icons/' . ($s === 180 ? 'apple-touch-icon.png' : ($maskeli ? 'icon-maskable-512.png' : "icon-$s.png"));
        $png = (string)@file_get_contents($dosya);
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($png));
    header('Cache-Control: public, max-age=86400');
    echo $png; exit;
}
if ($a === 'sw') {
    // Service worker: uygulama dosyalarını telefonda saklar (hızlı açılış); veriler her zaman sunucudan gelir, telefonda saklanmaz.
    preg_match("/\\\$v = '(\\d+)';/", (string)@file_get_contents(__DIR__ . '/index.php'), $m);
    $surum = $m[1] ?? '0';
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
    header('Service-Worker-Allowed: ./');
    echo str_replace('__SURUM__', $surum, <<<'JS'
const ONBELLEK = "cari-__SURUM__";
const KABUK = ["assets/app.css?v=__SURUM__", "assets/app.js?v=__SURUM__", "assets/icons/icon-192.png"];
const CEVRIMDISI = '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bağlantı yok</title>' +
  '<body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f3f4f7;color:#18202e;text-align:center;padding:24px">' +
  '<div><h1 style="font-size:22px;margin:0 0 8px">İnternet bağlantısı yok</h1><p style="color:#697386;margin:0 0 18px">Kayıtlar sunucuda tutulduğu için bağlantı gelince açılır.</p>' +
  '<button onclick="location.reload()" style="font:inherit;padding:10px 18px;border:0;border-radius:10px;background:#2f56d6;color:#fff">Tekrar dene</button></div></body></html>';
self.addEventListener("install", e => { e.waitUntil(caches.open(ONBELLEK).then(c => c.addAll(KABUK)).catch(() => {}).then(() => self.skipWaiting())); });
self.addEventListener("activate", e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k.startsWith("cari-") && k !== ONBELLEK).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener("fetch", e => {
  const r = e.request, u = new URL(r.url);
  if (r.method !== "GET" || u.origin !== location.origin) return;
  if (u.pathname.endsWith("/api.php")) return; // veriler ve belgeler: her zaman sunucudan
  if (r.mode === "navigate") {
    e.respondWith(fetch(r).catch(() => new Response(CEVRIMDISI, { headers: { "Content-Type": "text/html; charset=utf-8" } })));
    return;
  }
  if (u.pathname.includes("/assets/")) {
    e.respondWith(caches.open(ONBELLEK).then(c => c.match(r).then(hit => hit || fetch(r).then(res => { if (res.ok) c.put(r, res.clone()); return res; }))));
  }
});
JS);
    exit;
}

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
    $girilen = trim((string)($b['kullanici'] ?? ''));
    if (str_contains($girilen, '@')) $girilen = strtolower($girilen); // e-posta: Türkçe I/ı karşılaştırmasına takılmasın
    $st->execute([$girilen]);
    $u = $st->fetch();
    if (!$u || !password_verify((string)($b['sifre'] ?? ''), $u['sifre_hash'])) {
        db()->prepare('INSERT INTO giris_denemeleri (ip) VALUES (?)')->execute([$ip]);
        fail('Kullanıcı adı veya şifre hatalı.', 401);
    }
    if (password_needs_rehash($u['sifre_hash'], PASSWORD_DEFAULT)) db()->prepare('UPDATE kullanicilar SET sifre_hash = ? WHERE id = ?')->execute([password_hash((string)$b['sifre'], PASSWORD_DEFAULT), $u['id']]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id']; $_SESSION['son'] = time(); $_SESSION['csrf'] = bin2hex(random_bytes(24));
    db()->prepare('UPDATE kullanicilar SET son_giris = NOW() WHERE id = ?')->execute([$u['id']]);
    if (!empty($b['hatirla'])) { try { ensure_schema(); hatirla_olustur((int)$u['id']); } catch (Throwable $t) { error_log('Cari Takip hatırla: ' . $t->getMessage()); } }
    out(['ok' => true]);
}

$user = current_user();
if (!$user) fail('Oturum kapandı. Yeniden giriş yapın.', 401);

if ($method === 'POST' && !hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Oturum doğrulanamadı; sayfayı yenileyin.', 403);
ensure_schema();
if (!empty($user['sifre_degis']) && !in_array($a, ['me', 'logout', 'password'], true)) fail('Devam etmek için önce şifrenizi değiştirin.', 403);
if ($user['rol'] === 'personel') {
    // Personel hesabı ayrı ve dar bir uç kümesine yönlenir; aşağıdaki hiçbir işleme ulaşamaz.
    try { personel_islem($a, $user, $method); }
    catch (PDOException $ex) { error_log('Cari Takip: ' . $ex->getMessage()); fail('Veritabanı hatası. Tekrar deneyin.', 500); }
}
if (!in_array($user['rol'], ['admin', 'okur'], true)) fail('Hesabınızın yetkisi tanımlı değil. Yöneticiye başvurun.', 403);
$isAdmin = $user['rol'] === 'admin';
$needAdmin = fn() => $isAdmin ?: fail('Bu işlem için yönetici yetkisi gerekiyor.', 403);

try {
switch ($a) {
    case 'me':
        out(['ok' => true, 'user' => $user, 'csrf' => $_SESSION['csrf'], 'ayarlar' => ayarlar_genel(), 'pdfMaxMb' => min((int)cfg('pdf_max_mb', 20), (int)(ini_bytes(ini_get('upload_max_filesize') ?: '2M') / 1048576) ?: 20)]);

    case 'logout':
        hatirla_bu_cihaz_sil(); $_SESSION = []; session_destroy(); out(['ok' => true]);

    case 'cihazlar':
        $st = db()->prepare('SELECT id, cihaz, olusturma, son_kullanim, son_kullanma FROM oturum_hatirla WHERE kullanici_id = ? AND son_kullanma > NOW() ORDER BY COALESCE(son_kullanim, olusturma) DESC'); $st->execute([$user['id']]);
        out(['ok' => true, 'cihazlar' => array_map(fn($r) => $r + ['bu' => (int)$r['id'] === (int)($_SESSION['hatirla_id'] ?? 0)], $st->fetchAll())]);

    case 'cihaz_sil':
        $id = (int)(body()['id'] ?? 0);
        if ($id) db()->prepare('DELETE FROM oturum_hatirla WHERE id = ? AND kullanici_id = ?')->execute([$id, $user['id']]);
        else db()->prepare('DELETE FROM oturum_hatirla WHERE kullanici_id = ?')->execute([$user['id']]);
        out(['ok' => true]);

    case 'all':
        $r = [];
        foreach (array_keys(ENT) as $t) $r[$t] = array_map(fn($row) => to_api($t, $row), db()->query("SELECT * FROM {$t} ORDER BY id")->fetchAll());
        $r['dagitim'] = array_map('dagitim_api', db()->query('SELECT id, hareket_id, fatura_id, tutar FROM odeme_dagitim ORDER BY id')->fetchAll());
        $r['belgeler'] = array_map('belge_api', db()->query('SELECT * FROM belgeler ORDER BY olusturma DESC, id DESC')->fetchAll());
        $r['notlar'] = array_map('not_api', db()->query('SELECT n.*, k.ad AS yazan FROM notlar n LEFT JOIN kullanicilar k ON k.id = n.kullanici_id ORDER BY n.olusturma DESC, n.id DESC')->fetchAll());
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
        if (in_array($t, ['cariler', 'projeler', 'faturalar'], true)) ekleri_sil(['cariler' => 'cari', 'projeler' => 'proje', 'faturalar' => 'fatura'][$t], $id);
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
        $f = fetch_row('faturalar', (int)($_GET['id'] ?? 0));
        if (!$f) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Fatura belgesi bulunamadı.'); }
        send_fatura_belgesi($f);

    case 'belge_yukle':
        $needAdmin();
        $tur = $_POST['sahip_tur'] ?? ''; $sid = (int)($_POST['sahip_id'] ?? 0);
        if (!($tab = sahip_tablosu($tur)) || !fetch_row($tab, $sid)) fail('Belgenin ekleneceği kayıt bulunamadı.', 404);
        $f = $_FILES['dosya'] ?? [];
        if ($err = upload_error($f)) fail(str_replace('PDF dosyası', 'Dosya', $err));
        if (!is_uploaded_file($f['tmp_name'])) fail('Dosya yüklenemedi.');
        $max = (int)cfg('pdf_max_mb', 20) * 1024 * 1024;
        if ($f['size'] > $max) fail('Dosya ' . cfg('pdf_max_mb', 20) . ' MB\'tan büyük olamaz.');
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!isset(BELGE_TUR[$ext])) fail('Bu dosya türü eklenemez. PDF, Word, Excel, PowerPoint, OpenDocument, RTF, TXT, CSV, PNG veya JPG yükleyin.');
        belge_dogrula($f['tmp_name'], $ext);
        $sub = 'belgeler/' . date('Y');
        $root = pdf_root();
        if (!is_dir("$root/$sub") && !@mkdir("$root/$sub", 0750, true)) fail('Belge klasörü oluşturulamadı.', 500);
        $rel = "$sub/{$tur}{$sid}_" . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], "$root/$rel")) fail('Dosya kaydedilemedi; klasör yazma iznini kontrol edin.', 500);
        @chmod("$root/$rel", 0640);
        $ad = mb_substr(basename((string)$f['name']), 0, 255);
        $acik = mb_substr(trim((string)($_POST['aciklama'] ?? '')), 0, 255);
        db()->prepare('INSERT INTO belgeler (sahip_tur, sahip_id, ad, aciklama, dosya_yol, boyut, yukleyen) VALUES (?,?,?,?,?,?,?)')->execute([$tur, $sid, $ad, $acik, $rel, (int)$f['size'], $user['id']]);
        $bid = (int)db()->lastInsertId();
        audit('belgeler', $bid, 'ekle', null, ['sahip' => "$tur:$sid", 'ad' => $ad]);
        $st = db()->prepare('SELECT * FROM belgeler WHERE id = ?'); $st->execute([$bid]);
        out(['ok' => true, 'rec' => belge_api($st->fetch())]);

    case 'belge':
        $st = db()->prepare('SELECT * FROM belgeler WHERE id = ?'); $st->execute([(int)($_GET['id'] ?? 0)]);
        $b = $st->fetch(); $p = $b ? safe_belge_path((string)$b['dosya_yol']) : null;
        if (!$p) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Belge bulunamadı.'); }
        session_write_close();
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $inline = !isset($_GET['indir']) && in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'csv'], true);
        header('Content-Type: ' . (BELGE_TUR[$ext] ?? 'application/octet-stream') . (in_array($ext, ['txt', 'csv'], true) ? '; charset=utf-8' : ''));
        header('Content-Length: ' . filesize($p));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $b['ad']) . '"; filename*=UTF-8\'\'' . rawurlencode($b['ad']));
        if ($ext !== 'pdf') header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, no-store');
        readfile($p); exit;

    case 'not_ekle':
        $needAdmin();
        $b = body(); $tur = (string)($b['sahipTur'] ?? ''); $sid = (int)($b['sahipId'] ?? 0); $metin = trim((string)($b['metin'] ?? ''));
        if (!($tab = sahip_tablosu($tur)) || !fetch_row($tab, $sid)) fail('Notun ekleneceği kayıt bulunamadı.', 404);
        if ($metin === '') fail('Not boş olamaz.');
        if (mb_strlen($metin) > 2000) fail('Not en fazla 2000 karakter olabilir.');
        db()->prepare('INSERT INTO notlar (sahip_tur, sahip_id, metin, kullanici_id) VALUES (?,?,?,?)')->execute([$tur, $sid, $metin, $user['id']]);
        $nid = (int)db()->lastInsertId();
        audit('notlar', $nid, 'ekle', null, ['sahip' => "$tur:$sid"]);
        $st = db()->prepare('SELECT n.*, k.ad AS yazan FROM notlar n LEFT JOIN kullanicilar k ON k.id = n.kullanici_id WHERE n.id = ?'); $st->execute([$nid]);
        out(['ok' => true, 'rec' => not_api($st->fetch())]);

    case 'not_sil':
        $needAdmin();
        $id = (int)(body()['id'] ?? 0);
        db()->prepare('DELETE FROM notlar WHERE id = ?')->execute([$id]);
        audit('notlar', $id, 'sil', null, null);
        out(['ok' => true]);

    case 'belge_sil':
        $needAdmin();
        $id = (int)(body()['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM belgeler WHERE id = ?'); $st->execute([$id]);
        $b = $st->fetch() ?: fail('Belge bulunamadı.', 404);
        if ($p = safe_belge_path((string)$b['dosya_yol'])) @unlink($p);
        db()->prepare('DELETE FROM belgeler WHERE id = ?')->execute([$id]);
        audit('belgeler', $id, 'sil', ['ad' => $b['ad'], 'sahip' => $b['sahip_tur'] . ':' . $b['sahip_id']], null);
        out(['ok' => true]);

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
        if (array_key_exists('vade_uyari_gun', $b)) { $v = (int)$b['vade_uyari_gun']; if ($v < 0 || $v > 60) fail('Vade uyarısı 0 ile 60 gün arasında olmalı.'); $yeni['vade_uyari_gun'] = (string)$v; }
        if (array_key_exists('ag_gun', $b)) { $v = (int)$b['ag_gun']; if ($v < 0 || $v > 31) fail('Gün sayısı 0 ile 31 arasında olmalı.'); $yeni['ag_gun'] = (string)$v; }
        foreach ($yeni as $k => $v) ayar_yaz($k, $v);
        audit('ayarlar', null, 'guncelle', null, $yeni);
        out(['ok' => true, 'ayarlar' => ayarlar_genel(true)]);

    case 'yedek':
        // Tek dosyada tam yedek: veritabanı (SQL) + fatura belgeleri. Yalnızca yönetici.
        $needAdmin();
        if (!class_exists('ZipArchive')) fail('Sunucuda PHP zip eklentisi kapalı; yedek oluşturulamıyor. Hosting panelinden zip eklentisini açın.', 500);
        session_write_close();
        @set_time_limit(600);
        $tmp = @tempnam(sys_get_temp_dir(), 'yedek');
        if (!$tmp || !is_writable($tmp)) $tmp = pdf_root() . '/_yedek_' . bin2hex(random_bytes(6)) . '.tmp';
        $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) fail('Yedek dosyası oluşturulamadı.', 500);
        $z->addFromString('veritabani.sql', sql_dump());
        $root = pdf_root(); $n = 0;
        foreach (db()->query('SELECT pdf_yol FROM faturalar WHERE pdf_yol IS NOT NULL') as $r) {
            if ($p = safe_pdf_path((string)$r['pdf_yol'])) { $z->addFile($p, 'belgeler/' . $r['pdf_yol']); $n++; }
        }
        foreach (db()->query('SELECT dosya_yol FROM belgeler') as $r) {
            if ($p = safe_belge_path((string)$r['dosya_yol'])) { $z->addFile($p, 'belgeler/' . $r['dosya_yol']); $n++; }
        }
        $z->addFromString('BENIOKU.txt', "Cari Takip yedeği · " . date('d.m.Y H:i') . "\r\n\r\n" .
            "veritabani.sql : tüm tablolar ve kayıtlar. Geri yüklemek için boş bir veritabanında phpMyAdmin > İçe aktar ile çalıştırın.\r\n" .
            "belgeler/      : fatura PDF / e-Fatura dosyaları ($n dosya). Geri yüklerken içeriği PDF klasörüne (cari-belgeler veya pdf-arsiv) kopyalayın.\r\n");
        $z->close();
        audit('ayarlar', null, 'yedek', null, ['belge' => $n]);
        ayar_yaz('son_yedek', date('Y-m-d H:i'));
        $ad = 'cari-yedek-' . date('Y-m-d-Hi') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($tmp));
        header('Content-Disposition: attachment; filename="' . $ad . '"');
        header('Cache-Control: private, no-store');
        readfile($tmp); @unlink($tmp);
        exit;

    case 'yap_gizle':
        // Özet > Yapılacaklar listesinden kaldırılan satırlar (yalnızca listeden gizlenir, kayıt silinmez)
        $needAdmin();
        $l = body()['liste'] ?? [];
        if (!is_array($l)) fail('Geçersiz liste.');
        $l = array_values(array_unique(array_filter(array_map(fn($x) => is_string($x) ? substr($x, 0, 80) : '', $l), fn($x) => preg_match('/^[a-z]+:[A-Za-z0-9:_-]*$/', $x))));
        $l = array_slice($l, -500);
        ayar_yaz('yap_gizli', json_encode($l));
        out(['ok' => true, 'yap_gizli' => json_encode($l)]);

    case 'guncelle':
        // Uygulama güncellemesi: yönetici zip yükler, izin verilen dosyalar yerine yazılır.
        // config.php, setup.php, kök .htaccess / web.config ve PDF arşivi asla değişmez.
        $needAdmin();
        if (!class_exists('ZipArchive')) fail('Sunucuda PHP zip eklentisi kapalı; güncelleme yüklenemiyor.', 500);
        $f = $_FILES['paket'] ?? [];
        if ($err = upload_error($f)) fail(str_replace('PDF dosyası', 'Güncelleme dosyası', $err));
        if (!is_uploaded_file($f['tmp_name'])) fail('Dosya yüklenemedi.');
        out(guncelleme_kur($f['tmp_name']));

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
        out(['ok' => true, 'users' => array_map(fn($r) => $r['rol'] !== 'admin' && !empty($r['cari_id']) ? ['rol' => 'personel'] + $r : $r, db()->query('SELECT id, kullanici, ad, rol, cari_id, sifre_degis, son_giris FROM kullanicilar ORDER BY id')->fetchAll())]);

    case 'user_save':
        $needAdmin();
        $b = body();
        $id = (int)($b['id'] ?? 0);
        $rol = in_array($b['rol'] ?? '', ['admin', 'okur', 'personel'], true) ? $b['rol'] : 'okur';
        $cariId = null;
        if ($rol === 'personel') {
            $cariId = (int)($b['cariId'] ?? 0);
            $pc = $cariId ? fetch_row('cariler', $cariId) : null;
            if (!$pc || $pc['tip'] !== 'personel') fail('Personel girişi için bir personel kartı seçin.');
            $st = db()->prepare("SELECT COUNT(*) FROM kullanicilar WHERE rol = 'personel' AND cari_id = ? AND id <> ?"); $st->execute([$cariId, (int)($b['id'] ?? 0)]);
            if ($st->fetchColumn()) fail('Bu personelin zaten bir giriş hesabı var.', 409);
        }
        $ad = mb_substr(trim((string)($b['ad'] ?? '')), 0, 120);
        $pass = (string)($b['sifre'] ?? '');
        if ($pass !== '' && mb_strlen($pass) < 10) fail('Şifre en az 10 karakter olmalı.');
        $degis = !empty($b['sifreDegis']) && $id !== (int)$user['id'] ? 1 : 0;
        if ($id) {
            if ($id === (int)$user['id'] && $rol !== 'admin') fail('Kendi yönetici yetkinizi kaldıramazsınız.');
            db()->prepare('UPDATE kullanicilar SET ad = ?, rol = ?, cari_id = ? WHERE id = ?')->execute([$ad, $rol, $cariId, $id]);
            if ($pass !== '') { db()->prepare('UPDATE kullanicilar SET sifre_hash = ?, sifre_degis = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $degis, $id]); db()->prepare('DELETE FROM oturum_hatirla WHERE kullanici_id = ?')->execute([$id]); }
        } else {
            $k = trim((string)($b['kullanici'] ?? ''));
            if (str_contains($k, '@')) $k = mb_strtolower($k);
            if (!preg_match('/^[a-zA-Z0-9._@+-]{3,120}$/', $k)) fail('Kullanıcı adı en az 3 karakter olmalı; harf, rakam, nokta, tire veya e-posta adresi kullanın.');
            if ($pass === '') fail('Yeni kullanıcı için şifre girin.');
            try { db()->prepare('INSERT INTO kullanicilar (kullanici, ad, sifre_hash, rol, cari_id, sifre_degis) VALUES (?,?,?,?,?,?)')->execute([$k, $ad ?: $k, password_hash($pass, PASSWORD_DEFAULT), $rol, $cariId, $degis]); }
            catch (PDOException $ex) { fail('Bu kullanıcı adı zaten var.', 409); }
        }
        $kid = $id ?: (int)db()->lastInsertId();
        $st = db()->prepare('SELECT rol FROM kullanicilar WHERE id = ?'); $st->execute([$kid]);
        if ($st->fetchColumn() !== $rol) fail('Yetki veritabanına kaydedilemedi (kullanicilar.rol sütunu güncel değil). Çıkış yapıp yeniden girin; düzelmezse phpMyAdmin\'de lib/schema.sql\'deki kullanicilar tablosunu kontrol edin.', 500);
        audit('kullanicilar', $kid, $id ? 'guncelle' : 'ekle', null, ['rol' => $rol, 'ad' => $ad]);
        out(['ok' => true]);

    case 'user_delete':
        $needAdmin();
        $id = (int)(body()['id'] ?? 0);
        if ($id === (int)$user['id']) fail('Kendi hesabınızı silemezsiniz.');
        db()->prepare('DELETE FROM kullanicilar WHERE id = ?')->execute([$id]);
        audit('kullanicilar', $id, 'sil', null, null);
        out(['ok' => true]);

    case 'password':
        sifre_degistir($user);

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
