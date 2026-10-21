/* Softpeak Cari — arayüz. Veriler api.php üzerinden MySQL'de, fatura PDF'leri sunucudaki klasörde. */
(function () {
"use strict";
const COLS = ["cariler", "projeler", "faturalar", "hareketler"];
const S = {
  me: null, csrf: "", softpeakVkn: "", pdfMaxMb: 20, canWrite: false, loaded: false,
  data: { cariler: [], projeler: [], faturalar: [], hareketler: [], dagitim: [] },
  agGun: (() => { try { const x = localStorage.getItem("spcari-ag-gun"); return x !== null && x !== "" ? x : 20; } catch (e) { return 20; } })(),
  rTip: "personel", rBas: "", rBit: "", rQ: "", rAcik: {}, fDurum: "tum",
  view: "ozet", detail: null, donem: ymNow(), fTur: "tum", fDonem: "", fQ: "", hTur: "tum", hQ: "",
  modal: null, imp: [], users: null, log: null, selPer: new Set(), selFat: new Set(), selFatCari: null,
};

/* ---------- yardımcılar ---------- */
function ymNow() { const d = new Date(); return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0"); }
function today() { const d = new Date(); return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0"); }
const AYLAR = ["Ocak", "Şubat", "Mart", "Nisan", "Mayıs", "Haziran", "Temmuz", "Ağustos", "Eylül", "Ekim", "Kasım", "Aralık"];
function ymLabel(ym) { if (!ym) return ""; const [y, m] = ym.split("-"); return AYLAR[+m - 1] + " " + y; }
function ymShift(ym, n) { let [y, m] = ym.split("-").map(Number); m += n; while (m < 1) { m += 12; y--; } while (m > 12) { m -= 12; y++; } return y + "-" + String(m).padStart(2, "0"); }
function esc(s) { return String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c])); }
const nf = new Intl.NumberFormat("tr-TR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
function tl(n) { return nf.format(Math.round((+n || 0) * 100) / 100) + " ₺"; }
function r2(n) { return Math.round((+n || 0) * 100) / 100; }
function fd(d) { if (!d) return ""; const [y, m, g] = d.split("-"); return g + "." + m + "." + y; }
function num(v) {
  if (typeof v === "number") return v; v = String(v || "").trim().replace(/\s|₺|TL/gi, ""); if (!v) return 0;
  if (v.includes(",")) v = v.replace(/\./g, "").replace(",", "."); else if (/^\d{1,3}(\.\d{3})+$/.test(v)) v = v.replace(/\./g, "");
  return parseFloat(v) || 0;
}
function kb(n) { return n > 1048576 ? (n / 1048576).toFixed(1).replace(".", ",") + " MB" : Math.max(1, Math.round(n / 1024)) + " KB"; }
function balHtml(v) {
  v = r2(v); if (Math.abs(v) < 0.005) return '<span class="bal muted">0,00 ₺</span>';
  return '<span class="bal ' + (v > 0 ? "b" : "a") + '">' + tl(Math.abs(v)) + '<span class="tag">' + (v > 0 ? "(B)" : "(A)") + "</span></span>";
}
function cari(id) { return S.data.cariler.find(c => c.id === id); }
function proje(id) { return S.data.projeler.find(p => p.id === id); }
function cariAd(id) { const c = cari(id); return c ? c.ad : "—"; }
function calcF(f) {
  const m = num(f.matrah), k = m * num(f.kdvOran) / 100, t = k * num(f.tevkifat) / 10, s = m * num(f.stopajOran) / 100;
  return { matrah: r2(m), kdv: r2(k), tevkif: r2(t), stopaj: r2(s), net: r2(m + k - t - s), brut: r2(m + k) };
}
function pdfUrl(id, indir) { return "api.php?a=pdf&id=" + encodeURIComponent(id) + (indir ? "&indir=1" : ""); }
function pdfLink(f) { return f.pdf ? '<a class="pdf-link" href="' + pdfUrl(f.id) + '" target="_blank" rel="noopener" data-stop="1" title="' + esc(f.pdfAd || "PDF") + '">PDF</a>' : ""; }

/* Ekstre kuralı: kesilen fatura ve ödeme = Borç; gelen fatura ve tahsilat = Alacak.
   Bakiye > 0 (B): karşı taraf bize borçlu. Bakiye < 0 (A): Softpeak borçlu. */
function movements(cariId, projeId) {
  const rows = [];
  S.data.faturalar.forEach(f => {
    if ((cariId && f.cariId !== cariId) || (projeId && f.projeId !== projeId)) return;
    const c = calcF(f);
    rows.push({ tarih: f.tarih, kind: "fatura", rec: f, tur: f.tur === "kesilen" ? "Kesilen fatura" : "Gelen fatura", belge: f.no || "", acik: f.aciklama || "", borc: f.tur === "kesilen" ? c.net : 0, alacak: f.tur === "gelen" ? c.net : 0 });
  });
  S.data.hareketler.forEach(h => {
    if ((cariId && h.cariId !== cariId) || (projeId && h.projeId !== projeId)) return;
    const t = num(h.tutar);
    rows.push({ tarih: h.tarih, kind: "hareket", rec: h, tur: h.tur === "tahsilat" ? "Tahsilat" : "Ödeme", belge: h.yontem || "", acik: h.aciklama || "", borc: h.tur === "odeme" ? t : 0, alacak: h.tur === "tahsilat" ? t : 0 });
  });
  rows.sort((a, b) => (a.tarih || "").localeCompare(b.tarih || "") || (a.kind === "fatura" ? -1 : 1));
  let run = 0; rows.forEach(r => { run = r2(run + r.borc - r.alacak); r.bakiye = run; });
  return rows;
}
function bakiye(cariId) {
  let b = 0;
  S.data.faturalar.forEach(f => { if (f.cariId !== cariId) return; const n = calcF(f).net; b += f.tur === "kesilen" ? n : -n; });
  S.data.hareketler.forEach(h => { if (h.cariId !== cariId) return; const t = num(h.tutar); b += h.tur === "odeme" ? t : -t; });
  return r2(b);
}

/* ---------- fatura ödeme durumu ---------- */
function odenenF(fid, haricH) { return r2(S.data.dagitim.reduce((t, d) => t + (d.faturaId === fid && d.hareketId !== haricH ? d.tutar : 0), 0)); }
function kalanF(f, haricH) { return r2(calcF(f).net - odenenF(f.id, haricH)); }
function dagH(hid) { return S.data.dagitim.filter(d => d.hareketId === hid); }
function bosH(h) { return r2(num(h.tutar) - dagH(h.id).reduce((t, d) => t + d.tutar, 0)); }
function durumF(f) { const o = odenenF(f.id), n = calcF(f).net; return o <= 0.004 ? "acik" : o >= n - 0.004 ? "odendi" : "kismi"; }
function durumChipF(f) { const d = durumF(f), g = f.tur === "gelen"; return d === "odendi" ? '<span class="chip odendi">' + (g ? "Ödendi" : "Tahsil edildi") + "</span>" : d === "kismi" ? '<span class="chip kismi">Kısmi</span>' : '<span class="chip acik">Açık</span>'; }
/** Açık faturalar (eskiden yeniye) — ödeme için gelen, tahsilat için kesilen. */
function acikFaturalar(cariId, harTur, haricH) { const ft = harTur === "odeme" ? "gelen" : "kesilen"; return S.data.faturalar.filter(f => f.cariId === cariId && f.tur === ft && kalanF(f, haricH) > 0.004).sort((a, b) => (a.tarih || "").localeCompare(b.tarih || "") || a.id - b.id); }
/** Tutarı eskiden yeniye açık faturalara dağıtır: {faturaId: tutar} */
function fifo(cariId, harTur, tutar, haricH) { const out = {}; let k = r2(tutar); for (const f of acikFaturalar(cariId, harTur, haricH)) { if (k <= 0.004) break; const t = Math.min(k, kalanF(f, haricH)); if (t > 0.004) { out[f.id] = r2(t); k = r2(k - t); } } return out; }
function setDagitim(hid, list) { S.data.dagitim = S.data.dagitim.filter(d => d.hareketId !== hid).concat(list || []); }

/* ---------- kesilen fatura ↔ personel eşleştirme (açıklamadaki addan) ---------- */
/** Büyük/küçük harf, Türkçe karakter, boşluk ve noktalama farklarını yok sayan karşılaştırma anahtarı. */
/** Net A/G'nin Müşteri A/G'ye oranı, ör. "%11,7". */
function agYuzde(ms, fa) { ms = num(ms); fa = num(fa); return ms > 0 ? "%" + new Intl.NumberFormat("tr-TR", { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format((ms - fa) / ms * 100) : ""; }
function foldName(s) { return String(s || "").toLocaleLowerCase("tr").normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/ı/g, "i").replace(/[^a-z0-9]/g, ""); }
function adAnahtarlari(ad) { const t = String(ad || "").trim().split(/\s+/).filter(Boolean); const a = [foldName(ad)]; if (t.length > 1) a.push(foldName(t.slice(1).concat(t[0]).join(" "))); return a.filter(x => x.length >= 5); }
function adGeciyor(metinKey, c) { return adAnahtarlari(c.ad).some(k => metinKey.includes(k)); }
const MONEY_RE = /\d{1,3}(?:\.\d{3})+,\d{2}|\d+,\d{2}/g;
/**
 * Kesilen faturanın hangi personele ne kadar (matrah) ait olduğu: [{pid, matrah|null, kaynak}].
 * Faturada "Hizmeti veren personel" seçiliyse o; değilse açıklamada adı geçen personel.
 * Açıklamada birden çok personel varsa her kalem satırının tutarı o satırdaki personele yazılır.
 */
function faturaPersonelleri(f) {
  if (f.tur !== "kesilen") return [];
  const net = calcF(f).matrah;
  if (f.personelId) return [{ pid: f.personelId, matrah: net, kaynak: "secim" }];
  const key = foldName(f.aciklama); if (!key) return [];
  const per = S.data.cariler.filter(c => c.tip === "personel");
  let bulunan = per.filter(c => c.musteriId === f.cariId && adGeciyor(key, c));
  if (!bulunan.length) bulunan = per.filter(c => adGeciyor(key, c));
  if (!bulunan.length) return [];
  if (bulunan.length === 1) return [{ pid: bulunan[0].id, matrah: net, kaynak: "aciklama" }];
  const pay = {};
  String(f.aciklama).split(/\s*\|\s*|\n/).forEach(line => {
    const lk = foldName(line), kim = bulunan.filter(c => adGeciyor(lk, c)), ms = line.match(MONEY_RE);
    if (kim.length === 1 && ms) pay[kim[0].id] = r2((pay[kim[0].id] || 0) + num(ms[ms.length - 1]));
  });
  return bulunan.map(c => ({ pid: c.id, matrah: pay[c.id] != null ? pay[c.id] : null, kaynak: "aciklama", ortak: true }));
}
/** PDF metninden kalem satırlarını (açıklama + satır tutarı) ve notları çıkarır; fatura açıklamasına yazılır. */
function kalemler(T) {
  T = normText(T);
  const out = [];
  const end = T.search(/Mal\s*Hizmet\s*Toplam\s*Tutar/i);
  let start = end >= 0 ? T.lastIndexOf("Sıra", end) : -1; if (start < 0 && end >= 0) start = T.slice(0, end).search(/S[ıi]ra\s*No/i);
  if (end >= 0 && start >= 0) {
    let blok = T.slice(start, end); const m = blok.match(/(?:^|\n)\s*1\s+(?=[^\d\s%])/);
    if (m) {
      blok = blok.slice(m.index);
      blok.split(/\n\s*(?=\d{1,3}\s+[^\d\s%.,])/).forEach(r => {
        r = r.replace(/\s+/g, " ").trim().replace(/^\d{1,3}\s+/, "");
        const ms = r.match(MONEY_RE), tutar = ms ? ms[ms.length - 1] : "";
        const d = r.replace(/%\s*\d+(?:,\d+)?/g, " ").replace(/\d[\d.,]*\s*(?:TL|₺)?/g, " ").replace(/(^|\s)(TL|₺|Gün|Ay|Adet|Saat|Hafta)(?=\s|$)/gi, " ").replace(/\s+/g, " ").trim();
        if (!d && out.length) { if (tutar) out[out.length - 1].t = tutar; return; }
        if (d) out.push({ d, t: tutar });
      });
    }
  }
  const satirlar = out.map(x => x.d + (x.t ? " · " + x.t + " TL" : ""));
  const not = T.match(/Not\s*:\s*([^\n]+)/i);
  if (not && !/^Yaln[ıi]z/i.test(not[1].trim())) satirlar.push("Not: " + not[1].trim());
  return satirlar.join(" | ").slice(0, 500);
}
async function aciklamalariDoldur() {
  if (S.acikBusy) return;
  const list = S.data.faturalar.filter(f => f.tur === "kesilen" && f.pdf && !(f.aciklama || "").trim());
  S.acikBusy = true; render();
  let n = 0, bos = 0;
  for (const f of list) {
    try {
      const r = await fetch(pdfUrl(f.id), { credentials: "same-origin" }); if (!r.ok) throw new Error("PDF açılamadı");
      const ac = kalemler(await pdfText(await r.blob()));
      if (!ac) { bos++; continue; }
      const j = await api("save", { q: "&t=faturalar", body: Object.assign({}, f, { aciklama: ac }) }); upsert("faturalar", j.rec); n++;
    } catch (e) { bos++; }
  }
  S.acikBusy = false; render();
  toast(n + " faturanın açıklaması PDF'ten dolduruldu" + (bos ? ", " + bos + " fatura okunamadı" : ""));
}

/* ---------- sunucu ---------- */
async function api(a, opts = {}) {
  const init = { method: opts.method || (opts.body || opts.form ? "POST" : "GET"), credentials: "same-origin", headers: { "X-CSRF": S.csrf } };
  if (opts.form) init.body = opts.form;
  else if (opts.body) { init.headers["Content-Type"] = "application/json"; init.body = JSON.stringify(opts.body); }
  let r, j;
  try { r = await fetch("api.php?a=" + a + (opts.q || ""), init); }
  catch (e) { throw new Error("Sunucuya ulaşılamadı. İnternet bağlantınızı kontrol edin."); }
  try { j = await r.json(); } catch (e) { throw new Error("Sunucu beklenmeyen bir yanıt verdi (HTTP " + r.status + ")."); }
  if (r.status === 401 && a !== "password") { location.reload(); throw new Error(j.error); }
  if (!j.ok) { const e = new Error(j.error || "İşlem tamamlanamadı."); e.status = r.status; throw e; }
  return j;
}
function upsert(col, rec) { const l = S.data[col], i = l.findIndex(x => x.id === rec.id); if (i >= 0) l[i] = rec; else l.push(rec); }

/* ---------- toast ---------- */
let tT; function toast(msg) { const r = document.getElementById("toast-root"); r.innerHTML = '<div class="toast" role="status">' + esc(msg) + "</div>"; clearTimeout(tT); tT = setTimeout(() => r.innerHTML = "", 3000); }

/* ---------- menü ---------- */
function navItems() {
  const n = [["ozet", "Özet"], ["personel", "Personel"], ["musteri", "Müşteriler"], ["proje", "Projeler"], ["fatura", "Faturalar"], ["hareket", "Ödeme / Tahsilat"]];
  if (S.canWrite) n.push(["arsiv", "PDF arşiv yükle"]);
  n.splice(6, 0, ["rapor", "Raporlar"], ["agrapor", "A/G Raporu"]);
  n.push(["ayar", "Ayarlar"]);
  return n;
}
function renderNav() {
  const counts = { personel: S.data.cariler.filter(c => c.tip === "personel").length, musteri: S.data.cariler.filter(c => c.tip === "musteri").length, proje: S.data.projeler.length, fatura: S.data.faturalar.length, hareket: S.data.hareketler.length };
  document.getElementById("nav").innerHTML = navItems().map(([k, l]) => '<button data-nav="' + k + '" aria-current="' + (S.view === k) + '">' + l + (counts[k] != null ? '<span class="cnt">' + counts[k] + "</span>" : "") + "</button>").join("") +
    '<button class="nav-logout" data-act="logout">Çıkış yap</button>';
}

/* ---------- görünümler ---------- */
function printHead(t) { return '<div class="print-head"><img src="assets/logo.png" alt=""><span>' + esc(t) + " · " + fd(today()) + "</span></div>"; }
function topActions(extra) {
  return '<div class="actions">' + (extra || "") + '<button class="btn" data-act="print">Yazdır</button>' + (S.canWrite ? '<button class="btn" data-act="new-hareket">+ Ödeme / Tahsilat</button><button class="btn primary" data-act="new-fatura">+ Fatura</button>' : "") + "</div>";
}
function kpi(l, v, hint) { return '<div class="kpi"><span class="lbl">' + esc(l) + '</span><span class="val">' + v + '</span><span class="hint">' + esc(hint) + "</span></div>"; }

function vOzet() {
  const per = S.data.cariler.filter(c => c.tip === "personel"), mus = S.data.cariler.filter(c => c.tip === "musteri");
  let alacak = 0, borc = 0; S.data.cariler.forEach(c => { const b = bakiye(c.id); if (b > 0) alacak += b; else borc -= b; });
  const d = S.donem, fd_ = S.data.faturalar.filter(f => f.donem === d);
  const satis = fd_.filter(f => f.tur === "kesilen").reduce((s, f) => s + calcF(f).matrah, 0), alis = fd_.filter(f => f.tur === "gelen").reduce((s, f) => s + calcF(f).matrah, 0);
  let h = printHead("Özet · " + ymLabel(d)) + '<div class="top"><div><h1>Özet</h1><div class="sub">' + ymLabel(d) + " dönemi · tutarlar KDV hariç matrah, bakiyeler net</div></div>" + topActions() + "</div>";
  if (!S.data.cariler.length && S.canWrite) h += '<div class="banner"><span><b>Başlarken:</b> önce müşterileri, sonra personeli ekleyin. Geçmiş faturalarınızı PDF arşiv yükle ekranından toplu aktarabilirsiniz.</span><button class="btn" data-nav="musteri">Müşteri ekle</button></div>';
  h += '<div class="kpis">' + kpi("Müşterilerden alacak", tl(alacak), "Tahsil edilmemiş net tutar") + kpi("Personele borç", tl(borc), "Ödenmemiş net tutar") +
    kpi(ymLabel(d) + " kesilen", tl(satis), fd_.filter(f => f.tur === "kesilen").length + " fatura") + kpi(ymLabel(d) + " marj", tl(satis - alis), "Gelen faturalar: " + tl(alis)) + "</div>";
  h += '<div class="panel"><div class="panel-head"><h2>Dönem tablosu</h2><div class="filters" style="margin:0"><button class="btn ghost" data-donem="-1" aria-label="Önceki ay">‹</button><input type="month" id="donem-sec" value="' + d + '"><button class="btn ghost" data-donem="1" aria-label="Sonraki ay">›</button></div></div>';
  const bosAc = S.data.faturalar.filter(f => f.tur === "kesilen" && f.pdf && !(f.aciklama || "").trim()).length;
  if (S.canWrite && bosAc) h += '<div class="banner"><span><b>' + bosAc + ' kesilen faturanın açıklaması boş.</b> Müşteriye kesilen faturayı personelle eşleştirmek için kalem satırlarını (personel adlarını) PDF\'lerden okuyalım.</span><button class="btn" data-act="acik-doldur"' + (S.acikBusy ? " disabled" : "") + ">" + (S.acikBusy ? "Okunuyor…" : "PDF'lerden oku") + "</button></div>";
  if (!per.length) h += '<div class="empty"><strong>Henüz personel yok</strong>Personel sayfasından ekleyin; her ay gelen ve kesilen faturalar burada eşleşir.</div>';
  else {
    h += '<div class="tw"><table><thead><tr><th>Personel</th><th>Hizmet verdiği</th><th>Gelen fatura</th><th class="num">Gelen</th><th>Müşteriye fatura</th><th class="num">Kesilen</th><th class="num">Marj</th><th class="num">Personel bakiyesi</th></tr></thead><tbody>';
    let tg = 0, tk = 0;
    const atf = new Map(fd_.filter(f => f.tur === "kesilen").map(f => [f, faturaPersonelleri(f)]));
    const kesilenOf = pid => { const k = []; let ks = 0, eksik = false, acik = false; atf.forEach((lst, f) => { const a = lst.find(x => x.pid === pid); if (!a) return; k.push(f); if (a.matrah == null) eksik = true; else ks += a.matrah; if (a.kaynak === "aciklama") acik = true; }); return { k, ks, eksik, acik }; };
    per.filter(p => p.durum !== "pasif" || fd_.some(f => f.cariId === p.id) || kesilenOf(p.id).k.length).forEach(p => {
      const g = fd_.filter(f => f.tur === "gelen" && f.cariId === p.id), K = kesilenOf(p.id), k = K.k;
      const gs = g.reduce((s, f) => s + calcF(f).matrah, 0), ks = K.ks; tg += gs; tk += ks;
      h += '<tr class="click" data-open="' + p.id + '"><td><b>' + esc(p.ad) + "</b></td><td>" + esc(p.musteriId ? cariAd(p.musteriId) : "—") + "</td>" +
        "<td>" + (g.length ? '<span class="chip ok">Girildi</span>' : '<span class="chip no">Bekleniyor</span>') + '</td><td class="num">' + (g.length ? tl(gs) : "—") + "</td>" +
        "<td>" + (k.length ? '<span class="chip ok" title="' + esc(k.map(f => (f.no || fd(f.tarih)) + " · " + cariAd(f.cariId)).join("\n")) + '">Kesildi</span>' + (K.acik ? '<div class="small muted">açıklamadan eşleşti</div>' : "") : '<span class="chip no">Kesilmedi</span>') + '</td><td class="num">' + (k.length ? (K.eksik ? (ks ? tl(ks) + " + " : "") + '<span class="small muted" title="Fatura birden çok personeli kapsıyor; satır tutarları ayrılamadı">ortak fatura</span>' : tl(ks)) : "—") + "</td>" +
        '<td class="num">' + (g.length || k.length ? tl(ks - gs) : "—") + '</td><td class="num">' + balHtml(bakiye(p.id)) + "</td></tr>";
    });
    h += '</tbody><tfoot><tr><td colspan="3">Toplam</td><td class="num">' + tl(tg) + '</td><td></td><td class="num">' + tl(tk) + '</td><td class="num">' + tl(tk - tg) + "</td><td></td></tr></tfoot></table></div>";
  }
  h += "</div>";
  h += '<div class="grid2"><div class="panel chart"><div class="panel-head"><h2>Son 6 ay</h2><div class="legend"><span><i style="background:var(--accent)"></i>Kesilen</span><span><i style="background:var(--debit)"></i>Gelen</span></div></div>' + chart() + "</div>";
  const son = movements().slice(-8).reverse();
  h += '<div class="panel"><div class="panel-head"><h2>Son hareketler</h2></div>' + (son.length ? '<div class="tw"><table><tbody>' + son.map(r => '<tr class="click" data-edit="' + r.kind + ":" + r.rec.id + '"><td class="small muted" style="white-space:nowrap">' + fd(r.tarih) + "</td><td>" + esc(cariAd(r.rec.cariId)) + '<div class="small muted">' + r.tur + '</div></td><td class="num ' + (r.borc ? "b" : "a") + '">' + tl(r.borc || r.alacak) + "</td></tr>").join("") + "</tbody></table></div>" : '<div class="empty">Fatura veya ödeme girildiğinde burada listelenir.</div>') + "</div></div>";
  if (mus.length) h += '<div class="panel"><div class="panel-head"><h2>Müşteri bakiyeleri</h2></div><div class="tw"><table><thead><tr><th>Müşteri</th><th class="num">Bakiye</th></tr></thead><tbody>' + mus.map(m => '<tr class="click" data-open="' + m.id + '"><td>' + esc(m.ad) + '</td><td class="num">' + balHtml(bakiye(m.id)) + "</td></tr>").join("") + "</tbody></table></div></div>";
  return h;
}
function chart() {
  const ms = []; for (let i = 5; i >= 0; i--) ms.push(ymShift(S.donem, -i));
  const vals = ms.map(m => { const f = S.data.faturalar.filter(x => x.donem === m); return [f.filter(x => x.tur === "kesilen").reduce((s, x) => s + calcF(x).matrah, 0), f.filter(x => x.tur === "gelen").reduce((s, x) => s + calcF(x).matrah, 0)]; });
  const max = Math.max(4, ...vals.flat()), step = Math.pow(10, Math.floor(Math.log10(max))), top = Math.ceil(max / step) * step;
  const W = 520, H = 220, L = 62, B = 28, T = 10, cw = (W - L - 8) / 6, bw = Math.min(22, cw / 3);
  let s = '<svg viewBox="0 0 ' + W + " " + H + '" role="img" aria-label="Son altı ayın kesilen ve gelen fatura toplamları">';
  for (let i = 0; i <= 4; i++) { const v = top * i / 4, y = H - B - (H - B - T) * i / 4; s += '<line x1="' + L + '" x2="' + (W - 8) + '" y1="' + y + '" y2="' + y + '" stroke="var(--line)" stroke-width="1"/><text x="' + (L - 6) + '" y="' + (y + 4) + '" text-anchor="end" font-size="10" fill="var(--muted)" font-family="IBM Plex Mono,monospace">' + (v >= 1000 ? Math.round(v / 1000) + "B" : Math.round(v)) + "</text>"; }
  vals.forEach(([k, g], i) => {
    const x = L + cw * i + cw / 2, hk = (H - B - T) * k / top, hg = (H - B - T) * g / top;
    s += '<rect x="' + (x - bw - 1) + '" y="' + (H - B - hk) + '" width="' + bw + '" height="' + hk + '" rx="2" fill="var(--accent)"><title>Kesilen ' + tl(k) + "</title></rect>";
    s += '<rect x="' + (x + 1) + '" y="' + (H - B - hg) + '" width="' + bw + '" height="' + hg + '" rx="2" fill="var(--debit)"><title>Gelen ' + tl(g) + "</title></rect>";
    s += '<text x="' + x + '" y="' + (H - 8) + '" text-anchor="middle" font-size="11" fill="' + (ms[i] === S.donem ? "var(--fg)" : "var(--muted)") + '" font-weight="' + (ms[i] === S.donem ? 600 : 400) + '">' + AYLAR[+ms[i].split("-")[1] - 1].slice(0, 3) + "</text>";
  });
  return s + '</svg><div class="small muted">Tutarlar KDV hariç, B = bin ₺</div>';
}

function vCariList(tip) {
  const list = S.data.cariler.filter(c => c.tip === tip).sort((a, b) => a.ad.localeCompare(b.ad, "tr"));
  const title = tip === "personel" ? "Personel" : "Müşteriler";
  let h = printHead(title) + '<div class="top"><div><h1>' + title + '</h1><div class="sub">' + (tip === "personel" ? "Softpeak'a fatura kesen, müşterilerde çalışan personel" : "Hizmet ve proje faturası kestiğiniz firmalar") + '</div></div><div class="actions"><button class="btn" data-act="print">Yazdır</button>' + (S.canWrite ? '<button class="btn primary" data-act="new-cari" data-tip="' + tip + '">+ ' + (tip === "personel" ? "Personel" : "Müşteri") + " ekle</button>" : "") + "</div></div>";
  if (!list.length) return h + '<div class="panel"><div class="empty"><strong>Henüz ' + (tip === "personel" ? "personel" : "müşteri") + " yok</strong>" + (tip === "personel" ? "Personeli, çalıştığı müşteriyi ve aylık fatura bedellerini girin." : "Önce müşteriyi ekleyin; personel ve projeler müşteriye bağlanır.") + "</div></div>";
  const selOn = tip === "personel" && S.canWrite;
  if (selOn) { const ids = new Set(list.map(c => c.id)); [...S.selPer].forEach(x => { if (!ids.has(x)) S.selPer.delete(x); }); }
  if (selOn && S.selPer.size) {
    const borc = [...S.selPer].reduce((t, x) => t + Math.max(0, -bakiye(x)), 0);
    h += '<div class="banner selbar"><span><b>' + S.selPer.size + ' personel seçildi</b> · ödenmemiş toplam ' + tl(borc) + '</span><span class="actions"><button class="btn" data-act="sel-clear">Seçimi kaldır</button><button class="btn primary" data-act="bulk-pay">Seçilenlere ödeme yap</button></span></div>';
  }
  h += '<div class="panel"><div class="tw"><table><thead><tr>' + (selOn ? '<th class="sel"><input type="checkbox" data-sel="per-all" aria-label="Tümünü seç"' + (list.length && list.every(c => S.selPer.has(c.id)) ? " checked" : "") + "></th>" : "") + "<th>" + (tip === "personel" ? "Ad soyad" : "Firma") + "</th>" + (tip === "personel" ? '<th>Hizmet verdiği müşteri</th><th class="num">Müşteri A/G</th><th class="num">Firma A/G</th><th class="num">Net A/G</th>' : "<th>Personel</th><th>Proje</th>") + '<th>Durum</th><th class="num">Bakiye</th></tr></thead><tbody>';
  let tb = 0;
  list.forEach(c => {
    const b = bakiye(c.id); tb += b;
    h += '<tr class="click' + (selOn && S.selPer.has(c.id) ? " selected" : "") + '" data-open="' + c.id + '">' + (selOn ? '<td class="sel"><input type="checkbox" data-sel="per" value="' + c.id + '" aria-label="' + esc(c.ad) + ' seç"' + (S.selPer.has(c.id) ? " checked" : "") + "></td>" : "") + "<td><b>" + esc(c.ad) + "</b>" + (c.vkn ? '<div class="small muted">VKN/TCKN ' + esc(c.vkn) + "</div>" : "") + (c.telefon || c.eposta ? '<div class="small muted">' + [c.telefon, c.eposta].filter(Boolean).map(esc).join(" · ") + "</div>" : "") + "</td>" +
      (tip === "personel" ? "<td>" + esc(c.musteriId ? cariAd(c.musteriId) : "—") + '</td><td class="num">' + (c.aylikSatis ? tl(c.aylikSatis) : "—") + '</td><td class="num">' + (c.aylikAlis ? tl(c.aylikAlis) : "—") + '</td><td class="num">' + (c.aylikSatis || c.aylikAlis ? '<b>' + tl(r2(num(c.aylikSatis) - num(c.aylikAlis))) + "</b>" + (num(c.aylikSatis) > 0 ? '<div class="small muted">' + agYuzde(c.aylikSatis, c.aylikAlis) + "</div>" : "") : "—") + "</td>"
        : "<td>" + S.data.cariler.filter(p => p.tip === "personel" && p.musteriId === c.id).length + "</td><td>" + S.data.projeler.filter(p => p.musteriId === c.id).length + "</td>") +
      "<td>" + (c.durum === "pasif" ? '<span class="chip mute">Pasif</span>' : '<span class="chip ok">Aktif</span>') + '</td><td class="num">' + balHtml(b) + "</td></tr>";
  });
  if (tip === "personel") {
    const akt = list.filter(c => c.durum !== "pasif"), ts = akt.reduce((t, c) => t + num(c.aylikSatis), 0), ta = akt.reduce((t, c) => t + num(c.aylikAlis), 0);
    return h + '</tbody><tfoot><tr><td colspan="' + (2 + (selOn ? 1 : 0)) + '">Toplam <span class="small muted">(A/G: aktif personel)</span></td><td class="num">' + tl(ts) + '</td><td class="num">' + tl(ta) + '</td><td class="num">' + tl(ts - ta) + (ts > 0 ? '<div class="small muted">' + agYuzde(ts, ta) + "</div>" : "") + '</td><td></td><td class="num">' + balHtml(tb) + "</td></tr></tfoot></table></div></div>";
  }
  return h + '</tbody><tfoot><tr><td colspan="' + (4 + (selOn ? 1 : 0)) + '">Toplam</td><td class="num">' + balHtml(tb) + "</td></tr></tfoot></table></div></div>";
}

/* Ekstre: bakiye eski→yeni hesaplanır, en yeni kayıt en üstte gösterilir. sel=true ise faturalar seçilebilir. */
function ekstreTable(rows, showCari, sel) {
  if (!rows.length) return '<div class="empty">Bu kayda ait fatura veya ödeme yok.</div>';
  sel = sel && S.canWrite;
  let tb = 0, ta = 0;
  const fats = rows.filter(r => r.kind === "fatura");
  let h = '<div class="tw"><table><thead><tr>' + (sel ? '<th class="sel"><input type="checkbox" data-sel="fat-all" aria-label="Tüm faturaları seç"' + (fats.length && fats.every(r => S.selFat.has(r.rec.id)) ? " checked" : "") + "></th>" : "") + '<th>Tarih</th><th>İşlem</th>' + (showCari ? "<th>Cari</th>" : "") + '<th>Belge / açıklama</th><th class="num">Borç</th><th class="num">Alacak</th><th class="num">Bakiye</th></tr></thead><tbody>';
  rows.slice().reverse().forEach(r => {
    tb += r.borc; ta += r.alacak;
    const on = sel && r.kind === "fatura" && S.selFat.has(r.rec.id);
    h += '<tr class="click' + (on ? " selected" : "") + '" data-edit="' + r.kind + ":" + r.rec.id + '">' + (sel ? '<td class="sel">' + (r.kind === "fatura" ? '<input type="checkbox" data-sel="fat" value="' + r.rec.id + '" aria-label="Faturayı seç"' + (on ? " checked" : "") + ">" : "") + "</td>" : "") + '<td style="white-space:nowrap">' + fd(r.tarih) + '</td><td><span class="chip ' + (r.borc ? "out" : "in") + '">' + r.tur + "</span></td>" + (showCari ? "<td>" + esc(cariAd(r.rec.cariId)) + "</td>" : "") +
      "<td>" + esc(r.belge) + (r.kind === "fatura" ? " " + pdfLink(r.rec) : "") + (r.acik ? '<div class="small muted">' + esc(r.acik) + "</div>" : "") + (r.kind === "fatura" && r.rec.donem ? '<div class="small muted">Dönem: ' + ymLabel(r.rec.donem) + "</div>" : "") +
      (r.kind === "fatura" ? '<div class="small" style="margin-top:3px">' + durumChipF(r.rec) + (durumF(r.rec) === "kismi" ? ' <span class="muted">kalan ' + tl(kalanF(r.rec)) + "</span>" : "") + "</div>" : (bosH(r.rec) > 0.004 && dagH(r.rec.id).length ? '<div class="small muted">' + tl(bosH(r.rec)) + " faturaya bağlanmadı</div>" : !dagH(r.rec.id).length ? '<div class="small muted">Faturaya bağlanmadı</div>' : "")) + "</td>" +
      '<td class="num">' + (r.borc ? tl(r.borc) : "") + '</td><td class="num">' + (r.alacak ? tl(r.alacak) : "") + '</td><td class="num">' + balHtml(r.bakiye) + "</td></tr>";
  });
  return h + '</tbody><tfoot><tr><td colspan="' + ((showCari ? 4 : 3) + (sel ? 1 : 0)) + '">Toplam</td><td class="num">' + tl(tb) + '</td><td class="num">' + tl(ta) + '</td><td class="num">' + balHtml(tb - ta) + "</td></tr></tfoot></table></div>";
}

function vCari(id) {
  const c = cari(id); if (!c) { S.detail = null; return vCariList("personel"); }
  const b = bakiye(id), rows = movements(id);
  let h = printHead("Cari ekstre · " + c.ad) + '<button class="back" data-nav="' + (c.tip === "personel" ? "personel" : "musteri") + '">← ' + (c.tip === "personel" ? "Personel" : "Müşteriler") + "</button>";
  h += '<div class="top"><div><h1>' + esc(c.ad) + '</h1><div class="meta"><span>' + (c.tip === "personel" ? "Personel" : "Müşteri") + "</span>" + (c.vkn ? "<span>VKN/TCKN " + esc(c.vkn) + "</span>" : "") + (c.telefon ? '<span>Tel: <a href="tel:' + esc(c.telefon.replace(/[^0-9+]/g, "")) + '">' + esc(c.telefon) + "</a></span>" : "") + (c.eposta ? '<span>E-posta: <a href="mailto:' + esc(c.eposta) + '">' + esc(c.eposta) + "</a></span>" : "") +
    (c.tip === "personel" && c.musteriId ? "<span>Hizmet verdiği: " + esc(cariAd(c.musteriId)) + "</span>" : "") + (c.baslangic ? "<span>Başlangıç " + fd(c.baslangic) + "</span>" : "") + (c.durum === "pasif" ? '<span class="chip mute">Pasif</span>' : "") + "</div></div>" +
    '<div class="actions"><button class="btn" data-act="print">Ekstreyi yazdır</button>' + (S.canWrite ? '<button class="btn" data-act="edit-cari" data-id="' + id + '">Düzenle</button><button class="btn" data-act="new-hareket" data-cari="' + id + '">+ ' + (c.tip === "personel" ? "Ödeme" : "Tahsilat") + '</button><button class="btn primary" data-act="new-fatura" data-cari="' + id + '">+ ' + (c.tip === "personel" ? "Gelen fatura" : "Kesilen fatura") + "</button>" : "") + "</div></div>";
  const fs = S.data.faturalar.filter(f => f.cariId === id), hs = S.data.hareketler.filter(x => x.cariId === id);
  const fat = fs.reduce((s, f) => s + calcF(f).net, 0), od = hs.reduce((s, x) => s + num(x.tutar), 0), sonOd = hs.map(x => x.tarih).sort().pop();
  h += '<div class="kpis">' + kpi("Bakiye", balHtml(b), b > 0 ? "Karşı taraf borçlu" : b < 0 ? "Softpeak borçlu" : "Kapalı") +
    kpi(c.tip === "personel" ? "Gelen faturalar" : "Kesilen faturalar", tl(fat), fs.length + " fatura, net") +
    kpi(c.tip === "personel" ? "Yapılan ödemeler" : "Alınan tahsilatlar", tl(od), hs.length + " işlem") +
    kpi(c.tip === "personel" ? "Son ödeme" : "Son tahsilat", sonOd ? fd(sonOd) : "—", c.tip === "personel" && (c.aylikAlis || c.aylikSatis) ? "Net A/G " + tl(r2(num(c.aylikSatis) - num(c.aylikAlis))) : "") + "</div>";
  if (c.tip === "personel" && (c.aylikAlis || c.aylikSatis)) { const ko = c.kdvOran ?? 20, ms = num(c.aylikSatis), fa = num(c.aylikAlis);
    h += '<div class="panel ag-ozet"><div><span class="lbl">Müşteri A/G</span><b>' + tl(ms) + '</b><span class="small muted">KDV\'li ' + tl(ms * (1 + ko / 100)) + '</span></div><div><span class="lbl">Firma A/G</span><b>' + tl(fa) + '</b><span class="small muted">KDV\'li ' + tl(fa * (1 + ko / 100)) + '</span></div><div><span class="lbl">Net A/G</span><b class="' + (ms - fa < 0 ? "b" : "") + '">' + tl(ms - fa) + (ms > 0 ? ' <span class="small muted">' + agYuzde(ms, fa) + "</span>" : "") + '</b><span class="small muted">KDV\'li ' + tl((ms - fa) * (1 + ko / 100)) + '</span></div><div><span class="lbl">KDV oranı</span><b>%' + ko + "</b></div></div>"; }
  if (c.notlar) h += '<div class="panel small">' + esc(c.notlar) + "</div>";
  if (S.selFatCari !== id) { S.selFat = new Set(); S.selFatCari = id; }
  let selBar = "";
  if (S.canWrite && S.selFat.size) {
    const sec = S.data.faturalar.filter(f => S.selFat.has(f.id));
    selBar = '<div class="banner selbar"><span><b>' + sec.length + ' fatura seçildi</b> · net toplam ' + tl(sec.reduce((t, f) => t + calcF(f).net, 0)) + '</span><span class="actions"><button class="btn" data-act="selfat-clear">Seçimi kaldır</button><button class="btn primary" data-act="selfat-pay">' + (c.tip === "personel" ? "Seçili faturaları öde" : "Seçili faturalar için tahsilat") + "</button></span></div>";
  }
  const bos = S.data.hareketler.filter(x => x.cariId === id && bosH(x) > 0.004);
  if (S.canWrite && bos.length && bos.some(x => acikFaturalar(id, x.tur).length)) {
    const bt = r2(bos.reduce((t, x) => t + bosH(x), 0));
    selBar += '<div class="banner"><span><b>' + tl(bt) + " tutarında " + (c.tip === "personel" ? "ödeme" : "tahsilat") + ' hiçbir faturaya bağlı değil.</b> Eskiden yeniye açık faturalara otomatik eşleştirebilirsiniz.</span><button class="btn" data-act="auto-match" data-id="' + id + '">Faturalarla eşleştir</button></div>';
  }
  h += selBar + '<div class="panel"><div class="panel-head"><h2>Cari ekstre</h2><span class="small muted">En yeni kayıt en üstte · (B) borçlu · (A) alacaklı</span></div>' + ekstreTable(rows, false, true) + "</div>";
  return h;
}

function projeOzet(p) {
  const fs = S.data.faturalar.filter(f => f.projeId === p.id), hs = S.data.hareketler.filter(x => x.projeId === p.id);
  const fat = fs.filter(f => f.tur === "kesilen").reduce((s, f) => s + calcF(f).matrah, 0), gider = fs.filter(f => f.tur === "gelen").reduce((s, f) => s + calcF(f).matrah, 0);
  const tah = hs.filter(x => x.tur === "tahsilat").reduce((s, x) => s + num(x.tutar), 0), net = fs.filter(f => f.tur === "kesilen").reduce((s, f) => s + calcF(f).net, 0);
  return { fat, gider, tah, net, kalan: r2(net - tah), bedel: num(p.bedel) };
}
function durumChip(d) { return { devam: '<span class="chip out">Devam ediyor</span>', tamam: '<span class="chip ok">Tamamlandı</span>', teklif: '<span class="chip no">Teklif</span>', iptal: '<span class="chip mute">İptal</span>' }[d] || '<span class="chip out">Devam ediyor</span>'; }
function vProjeList() {
  const list = S.data.projeler.slice().sort((a, b) => (b.baslangic || "").localeCompare(a.baslangic || ""));
  let h = '<div class="top"><div><h1>Projeler</h1><div class="sub">Personel hizmetlerinden ayrı izlenen proje işleri</div></div><div class="actions">' + (S.canWrite ? '<button class="btn primary" data-act="new-proje">+ Proje ekle</button>' : "") + "</div></div>";
  if (!list.length) return h + '<div class="panel"><div class="empty"><strong>Henüz proje yok</strong>Proje ekleyin; projeye bağlı faturalar ve tahsilatlar proje sayfasında toplanır.</div></div>';
  h += '<div class="cards">';
  list.forEach(p => {
    const o = projeOzet(p), pct = o.bedel ? Math.min(100, o.fat / o.bedel * 100) : 0;
    h += '<button class="card" data-proje="' + p.id + '"><div style="display:flex;justify-content:space-between;gap:8px;align-items:start"><h3>' + esc(p.ad) + "</h3>" + durumChip(p.durum) + '</div><div class="small muted">' + esc(cariAd(p.musteriId)) + "</div>" +
      (o.bedel ? '<div><div class="bar"><i style="width:' + pct.toFixed(1) + '%"></i></div><div class="small muted" style="margin-top:4px">Bedelin %' + Math.round(pct) + "'i faturalandı</div></div>" : "") +
      '<dl class="kv"><dt>Proje bedeli</dt><dd>' + (o.bedel ? tl(o.bedel) : "—") + "</dd><dt>Faturalanan</dt><dd>" + tl(o.fat) + "</dd><dt>Tahsil edilen</dt><dd>" + tl(o.tah) + "</dd><dt>Açık alacak</dt><dd>" + balHtml(o.kalan) + "</dd></dl></button>";
  });
  return h + "</div>";
}
function vProje(id) {
  const p = proje(id); if (!p) { S.detail = null; return vProjeList(); }
  const o = projeOzet(p);
  let h = printHead("Proje · " + p.ad) + '<button class="back" data-nav="proje">← Projeler</button><div class="top"><div><h1>' + esc(p.ad) + '</h1><div class="meta"><span>' + esc(cariAd(p.musteriId)) + "</span>" + (p.baslangic ? "<span>Başlangıç " + fd(p.baslangic) + "</span>" : "") + (p.bitis ? "<span>Teslim " + fd(p.bitis) + "</span>" : "") + durumChip(p.durum) + "</div></div>" +
    '<div class="actions"><button class="btn" data-act="print">Yazdır</button>' + (S.canWrite ? '<button class="btn" data-act="edit-proje" data-id="' + id + '">Düzenle</button><button class="btn" data-act="new-hareket" data-proje="' + id + '">+ Tahsilat</button><button class="btn primary" data-act="new-fatura" data-proje="' + id + '">+ Fatura</button>' : "") + "</div></div>";
  h += '<div class="kpis">' + kpi("Proje bedeli", o.bedel ? tl(o.bedel) : "—", "KDV hariç") + kpi("Faturalanan", tl(o.fat), o.bedel ? "Kalan " + tl(o.bedel - o.fat) : "KDV hariç") + kpi("Tahsil edilen", tl(o.tah), "Açık: " + tl(o.kalan)) + kpi("Proje marjı", tl(o.fat - o.gider), "Gider faturaları " + tl(o.gider)) + "</div>";
  if (p.aciklama) h += '<div class="panel small">' + esc(p.aciklama) + "</div>";
  return h + '<div class="panel"><div class="panel-head"><h2>Proje hareketleri</h2></div>' + ekstreTable(movements(null, id), true) + "</div>";
}

function filteredFaturalar() {
  let list = S.data.faturalar.slice();
  if (S.fTur !== "tum") list = list.filter(f => f.tur === S.fTur);
  if (S.fDonem) list = list.filter(f => f.donem === S.fDonem);
  if (S.fDurum !== "tum") list = list.filter(f => S.fDurum === "acik" ? durumF(f) !== "odendi" : durumF(f) === "odendi");
  if (S.fQ) { const q = S.fQ.toLocaleLowerCase("tr"); list = list.filter(f => (cariAd(f.cariId) + " " + (f.no || "") + " " + (f.aciklama || "") + " " + (f.projeId ? (proje(f.projeId) || {}).ad || "" : "")).toLocaleLowerCase("tr").includes(q)); }
  return list.sort((a, b) => (b.tarih || "").localeCompare(a.tarih || ""));
}
function vFatura() {
  const list = filteredFaturalar();
  let h = printHead("Faturalar" + (S.fDonem ? " · " + ymLabel(S.fDonem) : "")) + '<div class="top"><div><h1>Faturalar</h1><div class="sub">Personelden gelen ve müşterilere kesilen faturalar</div></div>' + topActions('<button class="btn" data-act="csv-fatura">CSV indir</button>') + "</div>";
  h += '<div class="filters"><div class="seg" role="group" aria-label="Fatura türü">' + [["tum", "Tümü"], ["gelen", "Gelen"], ["kesilen", "Kesilen"]].map(([k, l]) => '<button data-ftur="' + k + '" aria-pressed="' + (S.fTur === k) + '">' + l + "</button>").join("") + "</div>" +
    '<div class="seg" role="group" aria-label="Ödeme durumu">' + [["tum", "Hepsi"], ["acik", "Açık / kısmi"], ["odendi", "Kapanan"]].map(([k, l]) => '<button data-fdurum="' + k + '" aria-pressed="' + (S.fDurum === k) + '">' + l + "</button>").join("") + "</div>" +
    '<input type="month" id="f-donem" value="' + S.fDonem + '" aria-label="Dönem">' + (S.fDonem ? '<button class="btn ghost" data-act="clr-donem">Tüm dönemler</button>' : "") +
    '<input type="search" id="f-q" placeholder="Cari, fatura no, proje ara" value="' + esc(S.fQ) + '" style="flex:1;min-width:160px"></div>';
  if (!list.length) return h + '<div class="panel"><div class="empty"><strong>Fatura bulunamadı</strong>' + (S.data.faturalar.length ? "Filtreleri değiştirin." : "Her ay personelden gelen faturaları ve müşteriye kestiğiniz faturaları buradan girin.") + "</div></div>";
  let tm = 0, tk = 0, tn = 0, tkal = 0;
  h += '<div class="panel"><div class="tw"><table><thead><tr><th>Tarih</th><th>Tür</th><th>Cari</th><th>Fatura no</th><th>Dönem</th><th class="num">Matrah</th><th class="num">KDV</th><th class="num">Ödenecek / tahsil</th><th>Durum</th><th class="num">Kalan</th></tr></thead><tbody>';
  list.forEach(f => {
    const c = calcF(f); tm += c.matrah; tk += c.kdv; tn += c.net;
    const fp = f.projeId || f.personelId ? [] : faturaPersonelleri(f);
    const link = f.projeId ? "Proje: " + esc((proje(f.projeId) || {}).ad || "—") : f.personelId ? "Personel: " + esc(cariAd(f.personelId)) : fp.length ? "Personel: " + esc(fp.map(x => cariAd(x.pid)).join(", ")) + " (açıklamadan)" : "";
    h += '<tr class="click" data-edit="fatura:' + f.id + '"><td style="white-space:nowrap">' + fd(f.tarih) + '</td><td><span class="chip ' + (f.tur === "kesilen" ? "out" : "in") + '">' + (f.tur === "kesilen" ? "Kesilen" : "Gelen") + "</span></td><td>" + esc(cariAd(f.cariId)) + (link ? '<div class="small muted">' + link + "</div>" : "") + "</td><td>" + esc(f.no || "") + " " + pdfLink(f) + '</td><td style="white-space:nowrap">' + ymLabel(f.donem) + '</td><td class="num">' + tl(c.matrah) + '</td><td class="num">' + tl(c.kdv) + (c.tevkif ? '<div class="small muted">Tevk. −' + tl(c.tevkif) + "</div>" : "") + '</td><td class="num">' + tl(c.net) + "</td><td>" + durumChipF(f) + '</td><td class="num">' + (kalanF(f) > 0.004 ? tl(kalanF(f)) : "—") + "</td></tr>"; tkal += Math.max(0, kalanF(f));
  });
  return h + '</tbody><tfoot><tr><td colspan="5">' + list.length + ' fatura</td><td class="num">' + tl(tm) + '</td><td class="num">' + tl(tk) + '</td><td class="num">' + tl(tn) + '</td><td></td><td class="num">' + tl(tkal) + "</td></tr></tfoot></table></div></div>";
}

function vHareket() {
  let list = S.data.hareketler.slice();
  if (S.hTur !== "tum") list = list.filter(x => x.tur === S.hTur);
  if (S.hQ) { const q = S.hQ.toLocaleLowerCase("tr"); list = list.filter(x => (cariAd(x.cariId) + " " + (x.aciklama || "") + " " + (x.yontem || "")).toLocaleLowerCase("tr").includes(q)); }
  list.sort((a, b) => (b.tarih || "").localeCompare(a.tarih || ""));
  let h = printHead("Ödeme / Tahsilat") + '<div class="top"><div><h1>Ödeme / Tahsilat</h1><div class="sub">Müşterilerden gelen ödemeler ve personele yapılan ödemeler</div></div>' + topActions('<button class="btn" data-act="csv-hareket">CSV indir</button>') + "</div>";
  h += '<div class="filters"><div class="seg" role="group" aria-label="İşlem türü">' + [["tum", "Tümü"], ["tahsilat", "Tahsilat"], ["odeme", "Ödeme"]].map(([k, l]) => '<button data-htur="' + k + '" aria-pressed="' + (S.hTur === k) + '">' + l + "</button>").join("") + '</div><input type="search" id="h-q" placeholder="Cari veya açıklama ara" value="' + esc(S.hQ) + '" style="flex:1;min-width:160px"></div>';
  if (!list.length) return h + '<div class="panel"><div class="empty"><strong>Kayıt bulunamadı</strong>' + (S.data.hareketler.length ? "Filtreleri değiştirin." : "Müşteriden ödeme geldiğinde veya personele ödeme yaptığınızda tarihiyle buraya girin.") + "</div></div>";
  let tt = 0, to = 0;
  h += '<div class="panel"><div class="tw"><table><thead><tr><th>Tarih</th><th>Tür</th><th>Cari</th><th>Yöntem</th><th>Açıklama</th><th class="num">Tahsilat</th><th class="num">Ödeme</th></tr></thead><tbody>';
  list.forEach(x => {
    const t = num(x.tutar); if (x.tur === "tahsilat") tt += t; else to += t;
    h += '<tr class="click" data-edit="hareket:' + x.id + '"><td style="white-space:nowrap">' + fd(x.tarih) + '</td><td><span class="chip ' + (x.tur === "tahsilat" ? "in" : "out") + '">' + (x.tur === "tahsilat" ? "Tahsilat" : "Ödeme") + "</span></td><td>" + esc(cariAd(x.cariId)) + "</td><td>" + esc(x.yontem || "") + "</td><td>" + esc(x.aciklama || "") + (x.projeId ? '<div class="small muted">Proje: ' + esc((proje(x.projeId) || {}).ad || "—") + "</div>" : "") + '</td><td class="num a">' + (x.tur === "tahsilat" ? tl(t) : "") + '</td><td class="num b">' + (x.tur === "odeme" ? tl(t) : "") + "</td></tr>";
  });
  return h + '</tbody><tfoot><tr><td colspan="5">' + list.length + ' işlem</td><td class="num">' + tl(tt) + '</td><td class="num">' + tl(to) + "</td></tr></tfoot></table></div></div>";
}

/* ---------- PDF arşiv yükleme ---------- */
function vArsiv() {
  let h = '<div class="top"><div><h1>PDF arşiv yükle</h1><div class="sub">Geçmiş faturaları toplu yükleyin. Fatura no, tarih, VKN ve tutarlar PDF\'in içinden okunur; kaydetmeden önce kontrol edin.</div></div></div>';
  h += '<label class="drop" id="drop" for="imp-files"><strong>PDF dosyalarını buraya bırakın</strong>ya da tıklayıp seçin · birden çok dosya seçebilirsiniz · dosya başına en fazla ' + S.pdfMaxMb + ' MB<input type="file" id="imp-files" accept="application/pdf,.pdf" multiple hidden></label>';
  if (!S.softpeakVkn) h += '<p class="small muted">İpucu: config.php içine Softpeak\'in VKN\'sini (softpeak_vkn) yazarsanız, faturanın gelen mi kesilen mi olduğu daha doğru tahmin edilir.</p>';
  if (!S.imp.length) return h;
  const bekleyen = S.imp.filter(r => r.st !== "ok").length;
  h += '<div class="panel" style="margin-top:16px"><div class="panel-head"><h2>' + S.imp.length + " dosya</h2><div class=\"actions\">" + (S.imp.some(r => r.st === "ok") ? '<button class="btn" data-act="imp-clear-ok">Kaydedilenleri listeden çıkar</button>' : "") + '<button class="btn" data-act="imp-clear">Listeyi temizle</button><button class="btn primary" data-act="imp-save"' + (bekleyen && !S.impBusy ? "" : " disabled") + ">" + (S.impBusy ? "Kaydediliyor…" : bekleyen + " faturayı kaydet") + "</button></div></div>";
  h += '<div class="tw"><table class="imp"><thead><tr><th>Dosya</th><th>Tür</th><th>Cari</th><th>Fatura no</th><th>Tarih</th><th>Dönem</th><th class="num">Matrah</th><th>KDV</th><th>Tevk.</th><th>Durum</th><th></th></tr></thead><tbody>';
  S.imp.forEach((r, i) => {
    const dis = r.st === "ok" || r.st === "busy" ? " disabled" : "";
    const tip = r.tur === "gelen" ? "personel" : "musteri";
    h += '<tr data-row="' + i + '"><td class="fname"><a href="#" data-imp-view="' + i + '">' + esc(r.file.name) + '</a><div class="hint-row">' + kb(r.file.size) + (r.text != null ? ' · <a href="#" data-imp-text="' + i + '">okunan metin</a>' : "") + "</div></td>" +
      '<td><select data-f="tur"' + dis + ">" + opt("gelen", "Gelen", r.tur) + opt("kesilen", "Kesilen", r.tur) + "</select></td>" +
      '<td><select class="w-c" data-f="cariId"' + dis + ">" + cariOpts(tip, r.cariId, "— Cari seçin —") + "</select>" + (r.vknHint && !r.cariId ? '<div class="hint-row">PDF\'teki VKN: ' + esc(r.vknHint) + ' · <a href="#" data-act="imp-new-cari" data-row="' + i + '">cari olarak ekle</a></div>' : "") + "</td>" +
      '<td><input class="w-no" data-f="no" value="' + esc(r.no) + '"' + dis + "></td>" +
      '<td><input class="w-d" type="date" data-f="tarih" value="' + esc(r.tarih) + '"' + dis + "></td>" +
      '<td><input class="w-d" type="month" data-f="donem" value="' + esc(r.donem) + '"' + dis + "></td>" +
      '<td><input class="w-m num" inputmode="decimal" data-f="matrah" value="' + (r.matrah ? nf.format(r.matrah) : "") + '"' + dis + ">" + (r.odenecek ? '<div class="hint-row">Ödenecek: ' + tl(r.odenecek) + "</div>" : "") + "</td>" +
      '<td><select class="w-s" data-f="kdvOran"' + dis + ">" + [20, 18, 10, 8, 1, 0].map(v => opt(v, "%" + v, r.kdvOran)).join("") + "</select></td>" +
      '<td><select class="w-s" data-f="tevkifat"' + dis + ">" + [0, 2, 3, 4, 5, 7, 9, 10].map(v => opt(v, v ? v + "/10" : "Yok", r.tevkifat)).join("") + "</select></td>" +
      '<td class="small">' + ({ ok: '<span class="st-ok">Kaydedildi</span>', busy: '<span class="st-wait">Kaydediliyor…</span>', read: '<span class="st-wait">Okunuyor…</span>', err: '<span class="st-err">' + esc(r.err) + "</span>" }[r.st] || '<span class="st-wait">' + esc(r.note || "Kontrol edin") + "</span>") + "</td>" +
      "<td>" + (r.st !== "ok" && r.st !== "busy" ? '<button class="btn ghost" data-act="imp-del" data-row="' + i + '" aria-label="Listeden çıkar">✕</button>' : "") + "</td></tr>";
  });
  return h + "</tbody></table></div></div>";
}

let pdfjsP = null;
function loadPdfJs() {
  if (pdfjsP) return pdfjsP;
  pdfjsP = new Promise((res, rej) => {
    const s = document.createElement("script");
    s.src = "assets/vendor/pdf.min.js";
    s.onload = () => { window.pdfjsLib.GlobalWorkerOptions.workerSrc = "assets/vendor/pdf.worker.min.js"; res(window.pdfjsLib); };
    s.onerror = () => { pdfjsP = null; rej(new Error("PDF okuyucu yüklenemedi")); };
    document.head.appendChild(s);
  });
  return pdfjsP;
}
async function pdfText(file) {
  const lib = await loadPdfJs();
  const doc = await lib.getDocument({ data: await file.arrayBuffer() }).promise;
  let out = "";
  for (let p = 1; p <= Math.min(doc.numPages, 3); p++) {
    const tc = await (await doc.getPage(p)).getTextContent();
    // Parçaları satır satır birleştir; aynı satırda birbirine değen parçaları boşluksuz yapıştır (ör. "30-09-" + "2026").
    let lastY = null, lastEnd = null, lastH = 10;
    tc.items.forEach(it => {
      if (!it.str) { if (it.hasEOL) { out += "\n"; lastY = null; } return; }
      const t = it.transform || [1, 0, 0, 1, 0, 0], x = t[4], y = t[5], h = Math.abs(t[3]) || it.height || lastH;
      let sep = "";
      if (lastY === null) sep = "";
      else if (Math.abs(y - lastY) > Math.max(2, h * 0.5)) sep = "\n";
      else if (lastEnd !== null && x - lastEnd > h * 0.25) sep = " ";
      out += sep + it.str;
      lastY = y; lastEnd = x + (it.width || 0); lastH = h;
      if (it.hasEOL) { out += "\n"; lastY = null; }
    });
    out += "\n";
  }
  return normText(out);
}
/** Yumuşak tire (U+00AD) ve farklı tire/boşluk karakterlerini sadeleştirir; e-Fatura PDF'lerinde tarihler "31\u00ad12\u00ad2025" biçiminde gelebilir. */
function normText(t) {
  return String(t).replace(/[\u00ad\u2010\u2011\u2012\u2013\u2014\u2212]/g, "-").replace(/[ \t\u00a0\u2007\u202f]+/g, " ");
}
function trNum(s) { return s ? num(s) : 0; }
const AY_RE = "(Ocak|Şubat|Subat|Mart|Nisan|Mayıs|Mayis|Haziran|Temmuz|Ağustos|Agustos|Eylül|Eylul|Ekim|Kasım|Kasim|Aralık|Aralik)";
function ayNo(ad) { const k = ad.toLocaleLowerCase("tr").replace("ş", "s").replace("ı", "i").replace("ğ", "g").replace("ü", "u"); return ["ocak", "subat", "mart", "nisan", "mayis", "haziran", "temmuz", "agustos", "eylul", "ekim", "kasim", "aralik"].indexOf(k) + 1; }
function isoDate(y, m, d) {
  y = +y; m = +m; d = +d; if (y < 100) y += 2000;
  if (!(y >= 2000 && y <= 2100 && m >= 1 && m <= 12 && d >= 1 && d <= 31)) return "";
  const dt = new Date(y, m - 1, d); if (dt.getMonth() !== m - 1) return "";
  return y + "-" + String(m).padStart(2, "0") + "-" + String(d).padStart(2, "0");
}
/** Metindeki ilk geçerli tarihi bulur: gg.aa.yyyy, gg-aa-yyyy, gg/aa/yyyy, yyyy-aa-gg, "30 Eylül 2026"; rakamların arasındaki boşluklara toleranslı. */
function findDate(T, compact) {
  const sep = "\\s*[-./]\\s*";
  let m = T.match(new RegExp("(?<!\\d)(\\d{1,2})" + sep + "(\\d{1,2})" + sep + "(20\\d{2})(?!\\d)"));
  if (m) { const d = isoDate(m[3], m[2], m[1]); if (d) return d; }
  m = T.match(new RegExp("(?<!\\d)(20\\d{2})" + sep + "(\\d{1,2})" + sep + "(\\d{1,2})(?!\\d)"));
  if (m) { const d = isoDate(m[1], m[2], m[3]); if (d) return d; }
  m = T.match(new RegExp("(?<!\\d)(\\d{1,2})\\s+" + AY_RE + "\\s+(20\\d{2})", "i"));
  if (m) { const d = isoDate(m[3], ayNo(m[2]), m[1]); if (d) return d; }
  // Ayraçsız gün-ay-yıl ("31122025"): bazı e-Fatura PDF'lerinde tire görünmez karakter olduğu için kaybolur; yalnızca etiketin hemen ardında aranır
  if (compact) { m = T.match(/^\s*:?\s*(\d{2})(\d{2})(20\d{2})(?!\d)/); if (m) { const d = isoDate(m[3], m[2], m[1]); if (d) return d; } }
  return "";
}
/* GİB e-Fatura / e-Arşiv şablonlarındaki alan adlarına göre tahmin; her alan kullanıcıya gösterilip onaylatılır. */
function parseFatura(text) {
  const r = {}; const T = normText(text);
  // Rakam arasındaki tek boşlukları kaldırılmış kopya ("3 0 . 0 9 . 2 0 2 6" gibi harf harf çıkan PDF'ler için)
  const C = T.replace(/(\d)[ ]+(?=[\d.,\-\/])/g, "$1").replace(/([.,\-\/])[ ]+(?=\d)/g, "$1");
  const fno = T.match(/\b([A-Z0-9]{3}20\d{2}\d{9})\b/) || C.match(/\b([A-Z0-9]{3}20\d{2}\d{9})\b/) || T.match(/Fatura\s*No\s*:?\s*([A-Z0-9][A-Z0-9-]{3,30})/i);
  if (fno) r.no = fno[1];
  // Tarih: önce "Fatura Tarihi / Düzenleme Tarihi / Belge Tarihi" etiketinin hemen ardından, sonra etiketten sonraki 200 karakterde, sonra metnin tamamında
  const lab = /(Fatura|D[üu]zenle(?:me|nme)|Belge)\s*Tarihi/i;
  for (const src of [T, C]) {
    if (r.tarih) break;
    const lm = src.match(lab);
    if (lm) { const after = src.slice(lm.index + lm[0].length, lm.index + lm[0].length + 200); r.tarih = findDate(after, true) || findDate(after); }
  }
  if (!r.tarih) r.tarih = findDate(T) || findDate(C);
  // Dönem: "Dönem: 09/2026", "Eylül 2026" gibi ifadeler; yoksa fatura tarihinin ayı
  let dm = C.match(/D[öo]nem[i]?\s*:?\s*(\d{1,2})\s*[./-]\s*(20\d{2})/i);
  if (dm && +dm[1] >= 1 && +dm[1] <= 12) r.donem = dm[2] + "-" + String(+dm[1]).padStart(2, "0");
  if (!r.donem) { dm = T.match(new RegExp("(?<!\\d\\s)" + AY_RE + "\\s*(20\\d{2})", "i")); if (dm) r.donem = dm[2] + "-" + String(ayNo(dm[1])).padStart(2, "0"); }
  const vkns = []; const reV = /(?:VKN|TCKN|V\.K\.N|T\.C\.K\.N|Vergi\s*(?:Kimlik\s*)?(?:No|Numaras[ıi])|TC\s*Kimlik\s*No)\s*:?\s*(\d{10,11})\b/gi; let m;
  for (const src of [T, C]) { reV.lastIndex = 0; while ((m = reV.exec(src))) if (!vkns.includes(m[1])) vkns.push(m[1]); }
  r.vkns = vkns;
  // Alıcı: "SAYIN" bloğundan sonraki ilk VKN/TCKN (GİB şablonu)
  const sy = T.search(/\bSAYIN\b/i);
  if (sy >= 0) { reV.lastIndex = sy; const am = reV.exec(T); if (am && am.index - sy < 800) r.aliciVkn = am[1]; }
  const money = "(\\d{1,3}(?:\\.\\d{3})*,\\d{2}|\\d+,\\d{2}|\\d+\\.\\d{2})";
  const pick = (...res) => { for (const re of res) for (const src of [T, C]) { const x = src.match(re); if (x) return trNum(x[x.length - 1]); } return 0; };
  r.matrah = pick(new RegExp("KDV\\s*Matrah[ıi]\\s*(?:\\(\\s*%\\s*\\d+[,.]?\\d*\\s*\\))?\\s*:?\\s*" + money, "i"), new RegExp("Vergi\\s*Hari[çc]\\s*Tutar\\s*:?\\s*" + money, "i"), new RegExp("Mal\\s*Hizmet\\s*Toplam\\s*Tutar[ıi]\\s*:?\\s*" + money, "i"), new RegExp("Ara\\s*Toplam\\s*:?\\s*" + money, "i"));
  const kdvTutar = pick(new RegExp("Hesaplanan\\s*(?:KDV|Katma\\s*De[ğg]er\\s*Vergisi)\\s*(?:\\(\\s*%\\s*\\d+[,.]?\\d*\\s*\\))?\\s*:?\\s*" + money, "i"));
  const oran = T.match(/Hesaplanan\s*(?:KDV|Katma\s*De[ğg]er\s*Vergisi)\s*\(\s*%\s*(\d+)/i) || T.match(/KDV\s*\(?\s*%\s*(\d+)/i);
  if (oran) r.kdvOran = +oran[1];
  else if (r.matrah && kdvTutar) { const o = Math.round(kdvTutar / r.matrah * 100); r.kdvOran = [20, 18, 10, 8, 1, 0].reduce((a, b) => Math.abs(b - o) < Math.abs(a - o) ? b : a); }
  const tv = T.match(/Tevkifat[^\n]{0,60}?\b(\d{1,2})\s*\/\s*10\b/i);
  if (tv && [2, 3, 4, 5, 7, 9, 10].includes(+tv[1])) r.tevkifat = +tv[1];
  r.odenecek = pick(new RegExp("[ÖO]denecek\\s*Tutar\\s*:?\\s*" + money, "i"), new RegExp("Vergiler\\s*Dahil\\s*Toplam\\s*Tutar\\s*:?\\s*" + money, "i"));
  return r;
}
function matchRow(r) {
  const own = (S.softpeakVkn || "").trim();
  const others = (r.vkns || []).filter(v => v !== own);
  let c = null; for (const v of others) { c = S.data.cariler.find(x => x.vkn && x.vkn.replace(/\D/g, "") === v); if (c) break; }
  if (c) { r.cariId = c.id; r.tur = c.tip === "personel" ? "gelen" : "kesilen"; }
  else r.vknHint = others[0] || "";
  // Softpeak'in VKN'si biliniyorsa: alıcı Softpeak ise gelen, değilse kesilen
  if (own && r.vkns && r.vkns.includes(own)) {
    if (r.aliciVkn) r.tur = r.aliciVkn === own ? "gelen" : "kesilen";
    else if (!c && others.length) r.tur = r.vkns.indexOf(own) < r.vkns.indexOf(others[0]) ? "kesilen" : "gelen";
    if (c && (c.tip === "personel") !== (r.tur === "gelen")) { r.cariId = ""; r.vknHint = others[0] || ""; }
  }
}
async function addImportFiles(files) {
  const max = S.pdfMaxMb * 1048576;
  const list = Array.from(files).filter(f => /\.pdf$/i.test(f.name) || f.type === "application/pdf");
  if (!list.length) { toast("Yalnızca PDF dosyaları yüklenebilir."); return; }
  const start = S.imp.length;
  list.forEach(f => S.imp.push({ file: f, st: f.size > max ? "err" : "read", err: f.size > max ? "Dosya " + S.pdfMaxMb + " MB'tan büyük" : "", tur: "gelen", cariId: "", no: "", tarih: "", donem: "", matrah: 0, kdvOran: 20, tevkifat: 0 }));
  render();
  for (let i = start; i < S.imp.length; i++) {
    const r = S.imp[i]; if (r.st !== "read") continue;
    try {
      const txt = await pdfText(r.file); r.text = txt; r.aciklama = kalemler(txt);
      const p = parseFatura(txt);
      Object.assign(r, { no: p.no || "", tarih: p.tarih || "", donem: p.donem || (p.tarih ? p.tarih.slice(0, 7) : ""), matrah: r2(p.matrah || 0), kdvOran: p.kdvOran ?? 20, tevkifat: p.tevkifat || 0, odenecek: p.odenecek || 0, vkns: p.vkns, aliciVkn: p.aliciVkn });
      matchRow(r);
      const eksik = [!r.cariId && "cari", !r.no && "fatura no", !r.tarih && "tarih", !r.matrah && "matrah"].filter(Boolean);
      if (!txt.replace(/\s/g, "").length) r.noText = true;
      r.st = "ready"; r.note = eksik.length ? "Eksik: " + eksik.join(", ") : "Kontrol edin";
      if (r.no && r.cariId && S.data.faturalar.some(f => f.no === r.no && f.cariId === r.cariId)) { r.st = "err"; r.err = "Bu fatura zaten kayıtlı"; }
    } catch (e) { r.st = "ready"; r.note = "PDF metni okunamadı; bilgileri elle girin"; r.text = "Hata: " + (e && e.message || e); }
    if (r.noText) r.note = "PDF'te metin yok (taranmış belge); bilgileri elle girin";
    if (S.view === "arsiv") render();
  }
}
async function saveImports() {
  if (S.impBusy) return; S.impBusy = true; render();
  let ok = 0, bad = 0;
  for (const r of S.imp) {
    if (r.st === "ok" || r.st === "read") continue;
    const miss = [!r.cariId && "cari", !r.tarih && "tarih", !(num(r.matrah) > 0) && "matrah"].filter(Boolean);
    if (miss.length) { r.st = "err"; r.err = "Eksik: " + miss.join(", "); bad++; continue; }
    r.st = "busy"; render();
    const fd_ = new FormData();
    fd_.append("pdf", r.file, r.file.name);
    fd_.append("data", JSON.stringify({ tur: r.tur, cariId: r.cariId, no: r.no, tarih: r.tarih, donem: r.donem || r.tarih.slice(0, 7), matrah: r2(num(r.matrah)), kdvOran: +r.kdvOran, tevkifat: +r.tevkifat, stopajOran: 0, aciklama: r.aciklama || "" }));
    try { const j = await api("import", { form: fd_ }); upsert("faturalar", j.rec); r.st = "ok"; ok++; }
    catch (e) { r.st = "err"; r.err = e.message; bad++; }
  }
  S.impBusy = false; render();
  toast(ok + " fatura kaydedildi" + (bad ? ", " + bad + " fatura kontrol bekliyor" : ""));
}

/* ---------- ayarlar ---------- */
function vAyar() {
  let h = '<div class="top"><div><h1>Ayarlar</h1><div class="sub">' + esc(S.me.ad) + " · " + (S.canWrite ? "Yönetici" : "Salt okunur") + '</div></div><div class="actions"><button class="btn" data-act="logout">Çıkış yap</button></div></div>';
  h += '<div class="grid2"><div class="panel"><h2>Şifremi değiştir</h2><form id="pw-form" class="form" style="grid-template-columns:1fr"><label class="f">Mevcut şifre<input type="password" id="pw-old" autocomplete="current-password" required></label><label class="f">Yeni şifre (en az 10 karakter)<input type="password" id="pw-new" autocomplete="new-password" minlength="10" required></label><div><button class="btn primary" type="submit">Şifreyi değiştir</button></div></form></div>';
  if (S.canWrite) {
    h += '<div class="panel"><div class="panel-head"><h2>Kullanıcılar</h2><button class="btn" data-act="new-user">+ Kullanıcı ekle</button></div>';
    if (!S.users) h += '<div class="empty">Yükleniyor…</div>';
    else h += '<div class="tw"><table><thead><tr><th>Kullanıcı</th><th>Yetki</th><th>Son giriş</th></tr></thead><tbody>' + S.users.map(u => '<tr class="click" data-user="' + u.id + '"><td><b>' + esc(u.ad) + '</b><div class="small muted">' + esc(u.kullanici) + "</div></td><td>" + (u.rol === "admin" ? '<span class="chip out">Yönetici</span>' : '<span class="chip mute">Salt okunur</span>') + '</td><td class="small muted">' + esc(u.son_giris || "—") + "</td></tr>").join("") + "</tbody></table></div>";
    h += "</div>";
  }
  h += "</div>";
  if (S.canWrite) {
    h += '<div class="panel"><div class="panel-head"><h2>İşlem kaydı</h2><span class="small muted">Son 200 değişiklik</span></div>';
    if (!S.log) h += '<div class="empty">Yükleniyor…</div>';
    else if (!S.log.length) h += '<div class="empty">Henüz değişiklik yok.</div>';
    else { const T = { cariler: "Cari", projeler: "Proje", faturalar: "Fatura", hareketler: "Ödeme/Tahsilat", kullanicilar: "Kullanıcı" }, I = { ekle: "Eklendi", guncelle: "Güncellendi", sil: "Silindi", pdf: "PDF eklendi", pdf_sil: "PDF kaldırıldı" };
      h += '<div class="tw"><table><thead><tr><th>Zaman</th><th>Kişi</th><th>Kayıt</th><th>İşlem</th></tr></thead><tbody>' + S.log.map(l => '<tr><td class="small" style="white-space:nowrap">' + esc(l.zaman) + "</td><td>" + esc(l.kullanici || "—") + "</td><td>" + esc((T[l.tablo] || l.tablo) + " #" + l.kayit_id) + "</td><td>" + esc(I[l.islem] || l.islem) + "</td></tr>").join("") + "</tbody></table></div>"; }
    h += "</div>";
  }
  return h;
}
async function loadAyar() {
  if (!S.canWrite) return;
  try { const [u, l] = await Promise.all([api("users"), api("log")]); S.users = u.users; S.log = l.log; } catch (e) { toast(e.message); }
  if (S.view === "ayar") render();
}

/* ---------- çizim ---------- */
function render() {
  renderNav();
  const m = document.getElementById("main");
  if (!S.loaded) return;
  const ae = document.activeElement, aid = ae && ae.id, sel = aid && ae.selectionStart;
  let h;
  if (S.detail && S.detail.type === "cari") h = vCari(S.detail.id);
  else if (S.detail && S.detail.type === "proje") h = vProje(S.detail.id);
  else h = ({ ozet: vOzet, personel: () => vCariList("personel"), musteri: () => vCariList("musteri"), proje: vProjeList, fatura: vFatura, hareket: vHareket, arsiv: vArsiv, rapor: vRapor, agrapor: vAgRapor, ayar: vAyar }[S.view] || vOzet)();
  m.innerHTML = h;
  if (aid) { const el = document.getElementById(aid); if (el && el.closest("#main") && el.type !== "file") { el.focus(); try { if (sel != null) el.setSelectionRange(sel, sel); } catch (e) {} } }
}

/* ---------- formlar ---------- */
function openModal(kind, rec, preset) {
  S.modal = { kind, rec: rec || null, preset: preset || {}, confirmDel: false, err: "", file: null, busy: false };
  if (kind === "hareket") { S.modal.dag = {}; if (rec) dagH(rec.id).forEach(d => S.modal.dag[d.faturaId] = d.tutar); else if (preset && preset.dag) S.modal.dag = Object.assign({}, preset.dag); S.modal.dagManual = !!rec || !!(preset && preset.dag); }
  renderModal();
}
function closeModal() { S.modal = null; document.getElementById("modal-root").innerHTML = ""; }
function opt(v, l, sel) { return '<option value="' + esc(v) + '"' + (String(sel ?? "") === String(v) ? " selected" : "") + ">" + esc(l) + "</option>"; }
function cariOpts(tip, sel, blank) { const l = S.data.cariler.filter(c => !tip || c.tip === tip).sort((a, b) => a.ad.localeCompare(b.ad, "tr")); return (blank ? opt("", blank, sel) : "") + l.map(c => opt(c.id, c.ad + (c.durum === "pasif" ? " (pasif)" : ""), sel)).join(""); }
function projeOpts(sel, musteriId) { return opt("", "— Yok —", sel) + S.data.projeler.filter(p => !musteriId || p.musteriId === musteriId || p.id === sel).map(p => opt(p.id, p.ad + " · " + cariAd(p.musteriId), sel)).join(""); }
const RO = () => S.canWrite ? "" : " disabled";

function renderModal() {
  const M = S.modal; if (!M) return; const r = M.rec || {}, p = M.preset; let title, body;
  if (M.kind === "cari") {
    const tip = r.tip || p.tip || "personel"; title = (M.rec ? (S.canWrite ? "Düzenle: " : "") : "Yeni ") + (tip === "personel" ? "personel" : "müşteri");
    body = '<input type="hidden" id="m-tip" value="' + tip + '"><div class="form">' +
      '<label class="f full">' + (tip === "personel" ? "Ad soyad / unvan" : "Firma unvanı") + '<input id="m-ad" required value="' + esc(r.ad) + '"' + RO() + "></label>" +
      '<label class="f">VKN / TCKN<input id="m-vkn" inputmode="numeric" value="' + esc(r.vkn || p.vkn) + '"' + RO() + "></label>" +
      '<label class="f">E-posta<input id="m-eposta" type="email" autocomplete="off" value="' + esc(r.eposta) + '" placeholder="ornek@firma.com"' + RO() + "></label>" +
      '<label class="f">Telefon<input id="m-telefon" type="tel" autocomplete="off" value="' + esc(r.telefon) + '" placeholder="0532 000 00 00"' + RO() + "></label>" +
      '<label class="f">Durum<select id="m-durum"' + RO() + ">" + opt("aktif", "Aktif", r.durum || "aktif") + opt("pasif", "Pasif", r.durum) + "</select></label>" +
      (tip === "personel" ? '<label class="f full">Hizmet verdiği müşteri<select id="m-musteri"' + RO() + ">" + cariOpts("musteri", r.musteriId, "— Seçin —") + "</select></label>" +
        '<div class="full ag-box"><div class="ag-grid">' +
        '<label class="f">Müşteri A/G (KDV hariç)<input id="m-satis" inputmode="decimal" value="' + (r.aylikSatis ? nf.format(r.aylikSatis) : "") + '" placeholder="0,00"' + RO() + "></label>" +
        '<label class="f">Firma A/G (KDV hariç)<input id="m-alis" inputmode="decimal" value="' + (r.aylikAlis ? nf.format(r.aylikAlis) : "") + '" placeholder="0,00"' + RO() + "></label>" +
        '<label class="f">Net A/G<input id="m-net" readonly tabindex="-1" class="calc-out"></label>' +
        '<label class="f">KDV oranı (%)<input id="m-agkdv" inputmode="decimal" value="' + (r.kdvOran ?? 20) + '"' + RO() + "></label>" +
        '<label class="f">Müşteri A/G KDV\'li<input id="m-satis-k" readonly tabindex="-1" class="calc-out"></label>' +
        '<label class="f">Firma A/G KDV\'li<input id="m-alis-k" readonly tabindex="-1" class="calc-out"></label>' +
        '<label class="f">Net A/G KDV\'li<input id="m-net-k" readonly tabindex="-1" class="calc-out"></label>' +
        "</div></div>" +
        '<label class="f">Başlangıç tarihi<input type="date" id="m-bas" value="' + esc(r.baslangic) + '"' + RO() + "></label><div></div>" : "") +
      '<label class="f full">Notlar<textarea id="m-not"' + RO() + ">" + esc(r.notlar) + "</textarea></label></div>";
  } else if (M.kind === "proje") {
    title = M.rec ? "Proje" : "Yeni proje";
    body = '<div class="form"><label class="f full">Proje adı<input id="m-ad" required value="' + esc(r.ad) + '"' + RO() + "></label>" +
      '<label class="f full">Müşteri<select id="m-musteri"' + RO() + ">" + cariOpts("musteri", r.musteriId || p.musteriId, "— Seçin —") + "</select></label>" +
      '<label class="f">Proje bedeli (KDV hariç)<input id="m-bedel" inputmode="decimal" value="' + (r.bedel ? nf.format(r.bedel) : "") + '" placeholder="0,00"' + RO() + "></label>" +
      '<label class="f">Durum<select id="m-durum"' + RO() + ">" + opt("devam", "Devam ediyor", r.durum || "devam") + opt("teklif", "Teklif", r.durum) + opt("tamam", "Tamamlandı", r.durum) + opt("iptal", "İptal", r.durum) + "</select></label>" +
      '<label class="f">Başlangıç<input type="date" id="m-bas" value="' + esc(r.baslangic || today()) + '"' + RO() + '></label><label class="f">Teslim<input type="date" id="m-bit" value="' + esc(r.bitis) + '"' + RO() + "></label>" +
      '<label class="f full">Açıklama<textarea id="m-acik"' + RO() + ">" + esc(r.aciklama) + "</textarea></label></div>";
  } else if (M.kind === "fatura") {
    let tur = r.tur || p.tur; const pc = p.cariId && cari(p.cariId); if (!tur) tur = pc ? (pc.tip === "personel" ? "gelen" : "kesilen") : p.projeId ? "kesilen" : "gelen";
    const cariId = r.cariId || p.cariId || (p.projeId ? (proje(p.projeId) || {}).musteriId : "") || "";
    title = M.rec ? "Fatura" + (r.no ? " · " + r.no : "") : "Yeni fatura";
    let pdfBox;
    if (M.file) pdfBox = '<div class="pdfbox"><span class="name">' + esc(M.file.name) + ' <span class="muted small">' + kb(M.file.size) + ' · kaydedince yüklenecek</span></span><button type="button" class="btn ghost" data-m="pdf-unpick">Vazgeç</button></div>';
    else if (r.pdf) pdfBox = '<div class="pdfbox"><span class="name">' + esc(r.pdfAd || "Fatura PDF") + ' <span class="muted small">' + (r.pdfBoyut ? kb(r.pdfBoyut) : "") + '</span></span><a class="btn" href="' + pdfUrl(r.id) + '" target="_blank" rel="noopener">Aç / yazdır</a><a class="btn" href="' + pdfUrl(r.id, 1) + '">İndir</a>' + (S.canWrite ? '<label class="btn" for="m-pdf">Değiştir</label><button type="button" class="btn danger" data-m="pdf-del">Kaldır</button>' : "") + "</div>";
    else pdfBox = S.canWrite ? '<label class="pdfbox" for="m-pdf" style="cursor:pointer"><span class="name">Fatura PDF\'i ekleyin</span><span class="btn">PDF seç</span></label>' : '<div class="pdfbox muted">Bu faturaya PDF eklenmemiş.</div>';
    body = '<div class="form">' + (S.canWrite ? '<div class="full"><div class="seg" role="group" aria-label="Fatura türü"><button type="button" data-mtur="gelen" aria-pressed="' + (tur === "gelen") + '">Gelen fatura · personelden</button><button type="button" data-mtur="kesilen" aria-pressed="' + (tur === "kesilen") + '">Kesilen fatura · müşteriye</button></div></div>' : '<div class="full"><span class="chip ' + (tur === "kesilen" ? "out" : "in") + '">' + (tur === "kesilen" ? "Kesilen fatura" : "Gelen fatura") + "</span></div>") +
      '<input type="hidden" id="m-tur" value="' + tur + '">' + pdfBox + '<input type="file" id="m-pdf" accept="application/pdf,.pdf" hidden>' +
      '<label class="f full">' + (tur === "gelen" ? "Faturayı kesen (personel / tedarikçi)" : "Fatura kesilen müşteri") + '<select id="m-cari" required' + RO() + ">" + cariOpts(tur === "gelen" ? "personel" : "musteri", cariId, "— Seçin —") + "</select></label>" +
      (tur === "kesilen" ? '<label class="f">Hizmeti veren personel<select id="m-personel"' + RO() + ">" + cariOpts("personel", r.personelId || p.personelId, "— Proje / diğer —") + "</select></label>" : "") +
      '<label class="f' + (tur === "kesilen" ? "" : " full") + '">Proje<select id="m-proje"' + RO() + ">" + projeOpts(r.projeId || p.projeId, tur === "kesilen" ? cariId : null) + "</select></label>" +
      '<label class="f">Fatura no<input id="m-no" value="' + esc(r.no) + '" placeholder="ör. SFT2026000000123"' + RO() + "></label>" +
      '<label class="f">Fatura tarihi<input type="date" id="m-tarih" value="' + esc(r.tarih || today()) + '"' + RO() + "></label>" +
      '<label class="f">Hizmet dönemi<input type="month" id="m-donem" value="' + esc(r.donem || p.donem || S.donem) + '"' + RO() + "></label>" +
      '<label class="f">Vade tarihi<input type="date" id="m-vade" value="' + esc(r.vade) + '"' + RO() + "></label>" +
      '<label class="f">Matrah (KDV hariç)<input id="m-matrah" inputmode="decimal" required value="' + (r.matrah != null ? nf.format(r.matrah) : "") + '" placeholder="0,00"' + RO() + "></label>" +
      '<label class="f">KDV oranı<select id="m-kdv"' + RO() + ">" + [20, 18, 10, 8, 1, 0].map(v => opt(v, "%" + v, r.kdvOran ?? 20)).join("") + "</select></label>" +
      '<label class="f">KDV tevkifatı<select id="m-tev"' + RO() + ">" + [[0, "Yok"], [2, "2/10"], [3, "3/10"], [4, "4/10"], [5, "5/10"], [7, "7/10"], [9, "9/10"], [10, "Tam (10/10)"]].map(([v, l]) => opt(v, l, r.tevkifat ?? 0)).join("") + "</select></label>" +
      '<label class="f">Gelir vergisi stopajı<select id="m-stop"' + RO() + ">" + [0, 10, 15, 20].map(v => opt(v, v ? "%" + v : "Yok", r.stopajOran ?? 0)).join("") + "</select></label>" +
      '<div class="calc" id="m-calc"></div>' + (M.rec ? faturaOdemeleri(M.rec) : "") +
      '<label class="f full">Açıklama<input id="m-acik" value="' + esc(r.aciklama) + '" placeholder="ör. Eylül 2026 yazılım danışmanlık hizmeti"' + RO() + "></label></div>";
  } else {
    let tur = r.tur || p.tur; const pc = p.cariId && cari(p.cariId); if (!tur) tur = pc ? (pc.tip === "personel" ? "odeme" : "tahsilat") : "tahsilat";
    const cariId = r.cariId || p.cariId || (p.projeId ? (proje(p.projeId) || {}).musteriId : "") || "";
    title = M.rec ? (tur === "tahsilat" ? "Tahsilat" : "Ödeme") : "Yeni ödeme / tahsilat";
    body = '<div class="form">' + (S.canWrite ? '<div class="full"><div class="seg" role="group" aria-label="İşlem türü"><button type="button" data-mhtur="tahsilat" aria-pressed="' + (tur === "tahsilat") + '">Tahsilat · müşteriden</button><button type="button" data-mhtur="odeme" aria-pressed="' + (tur === "odeme") + '">Ödeme · personele</button></div></div>' : "") + '<input type="hidden" id="m-tur" value="' + tur + '">' +
      '<label class="f full">' + (tur === "tahsilat" ? "Ödeme yapan müşteri" : "Ödeme yapılan personel / tedarikçi") + '<select id="m-cari" required' + RO() + ">" + cariOpts(tur === "tahsilat" ? "musteri" : "personel", cariId, "— Seçin —") + "</select></label>" +
      '<label class="f">Tarih<input type="date" id="m-tarih" value="' + esc(r.tarih || today()) + '"' + RO() + "></label>" +
      '<label class="f">Tutar<input id="m-tutar" inputmode="decimal" required value="' + (r.tutar != null ? nf.format(r.tutar) : "") + '" placeholder="0,00"' + RO() + "></label>" +
      '<label class="f">Yöntem<select id="m-yontem"' + RO() + ">" + ["Havale / EFT", "Nakit", "Kredi kartı", "Çek", "Senet", "Mahsup"].map(v => opt(v, v, r.yontem || "Havale / EFT")).join("") + "</select></label>" +
      '<label class="f">Proje<select id="m-proje"' + RO() + ">" + projeOpts(r.projeId || p.projeId, tur === "tahsilat" ? cariId : null) + "</select></label>" +
      '<div class="full small muted" id="m-hint"></div>' + '<div class="full" id="m-dag"></div>' +
      '<label class="f full">Açıklama<input id="m-acik" value="' + esc(r.aciklama) + '" placeholder="ör. Ağustos 2026 hizmet bedeli"' + RO() + "></label></div>";
  }
  const del = M.rec && S.canWrite ? (M.confirmDel ? '<span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><span class="small">Bu kayıt kalıcı olarak silinsin mi?</span><button type="button" class="btn danger" data-m="del-yes">Evet, sil</button><button type="button" class="btn" data-m="del-no">Vazgeç</button></span>' : '<button type="button" class="btn danger" data-m="del">Sil</button>') : "";
  document.getElementById("modal-root").innerHTML = '<div class="overlay" data-m="bg"><form class="modal" id="m-form" role="dialog" aria-modal="true" aria-label="' + esc(title) + '" novalidate><div class="modal-head"><h2>' + esc(title) + '</h2><button type="button" class="btn ghost" data-m="close" aria-label="Kapat">✕</button></div>' + body +
    (M.err ? '<div class="err">' + esc(M.err) + "</div>" : "") + '<div class="modal-foot">' + del + '<div class="r"><button type="button" class="btn" data-m="close">' + (S.canWrite ? "Vazgeç" : "Kapat") + "</button>" + (S.canWrite ? '<button type="submit" class="btn primary"' + (M.busy ? " disabled" : "") + ">" + (M.busy ? "Kaydediliyor…" : "Kaydet") + "</button>" : "") + "</div></div></form></div>";
  updCalc(); updHint(); updDag(); updAg();
  const first = document.querySelector("#m-form input:not([type=hidden]):not([type=file]),#m-form select"); if (first && !M.focused) { first.focus(); M.focused = true; }
}
function updCalc() {
  const el = document.getElementById("m-calc"); if (!el) return;
  const c = calcF({ matrah: num(v("m-matrah")), kdvOran: v("m-kdv"), tevkifat: v("m-tev"), stopajOran: v("m-stop") });
  el.innerHTML = "<div>KDV<b>" + tl(c.kdv) + "</b></div><div>Fatura toplamı<b>" + tl(c.brut) + "</b></div><div>Tevkifat + stopaj<b>" + tl(c.tevkif + c.stopaj) + '</b></div><div class="net">' + (v("m-tur") === "gelen" ? "Personele ödenecek" : "Müşteriden tahsil") + "<b>" + tl(c.net) + "</b></div>";
}
function updAg() {
  const out = document.getElementById("m-net"); if (!out) return;
  const ms = num(v("m-satis")), fa = num(v("m-alis")), ko = v("m-agkdv").trim() === "" ? 20 : num(v("m-agkdv")), k = 1 + ko / 100;
  out.value = tl(ms - fa) + (ms > 0 ? "  (" + agYuzde(ms, fa) + ")" : ""); out.classList.toggle("neg", ms - fa < 0);
  document.getElementById("m-satis-k").value = tl(ms * k);
  document.getElementById("m-alis-k").value = tl(fa * k);
  document.getElementById("m-net-k").value = tl((ms - fa) * k);
}
function personelAciklamadan() {
  const ps = document.getElementById("m-personel"); if (!ps || ps.value) return;
  const lst = faturaPersonelleri({ tur: "kesilen", cariId: v("m-cari"), aciklama: v("m-acik"), matrah: 0, kdvOran: 0, tevkifat: 0, stopajOran: 0 });
  if (lst.length === 1) { ps.value = lst[0].pid; toast(cariAd(lst[0].pid) + " açıklamadan seçildi"); }
}
function updHint() { const el = document.getElementById("m-hint"); if (!el) return; const id = v("m-cari"); el.innerHTML = id ? "Güncel bakiye: " + balHtml(bakiye(id)) : ""; }
function v(id) { const e = document.getElementById(id); return e ? e.value : ""; }
function snapshotForm() { const out = {}; document.querySelectorAll("#m-form [id^=m-]").forEach(e => { if ("value" in e && e.type !== "file") out[e.id] = e.value; }); return out; }
function restore(st) { Object.entries(st).forEach(([k, val]) => { const e = document.getElementById(k); if (e && k !== "m-tur" && k !== "m-tip" && !e.disabled && !e.readOnly) e.value = val; }); updCalc(); updHint(); updAg(); }
function rerenderModal(mut) { const st = snapshotForm(); if (mut) mut(S.modal); renderModal(); restore(st); }
function fail(msg) { rerenderModal(M => { M.err = msg; M.busy = false; }); }

async function saveModal() {
  const M = S.modal; if (!M || !S.canWrite || M.busy) return; let col, data;
  if (M.kind === "cari") {
    if (!v("m-ad").trim()) return fail("Ad / unvan girin."); col = "cariler";
    const ep = v("m-eposta").trim(), tel = v("m-telefon").trim();
    if (ep && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ep)) return fail("E-posta adresi geçersiz görünüyor.");
    if (tel && !/^[0-9+()\s.\-]{7,30}$/.test(tel)) return fail("Telefon numarasında yalnızca rakam, boşluk, +, ( ) ve - kullanın.");
    data = { tip: v("m-tip"), ad: v("m-ad").trim(), vkn: v("m-vkn").replace(/\D/g, ""), eposta: ep, telefon: tel, durum: v("m-durum"), notlar: v("m-not").trim() };
    if (data.tip === "personel") {
      const ko = v("m-agkdv").trim() === "" ? 20 : num(v("m-agkdv"));
      if (!(ko >= 0 && ko <= 100)) return fail("KDV oranı 0 ile 100 arasında olmalı.");
      Object.assign(data, { musteriId: v("m-musteri"), aylikAlis: r2(num(v("m-alis"))), aylikSatis: r2(num(v("m-satis"))), kdvOran: ko, baslangic: v("m-bas") });
    }
  } else if (M.kind === "proje") {
    if (!v("m-ad").trim()) return fail("Proje adını girin."); if (!v("m-musteri")) return fail("Müşteri seçin."); col = "projeler";
    data = { ad: v("m-ad").trim(), musteriId: v("m-musteri"), bedel: r2(num(v("m-bedel"))), durum: v("m-durum"), baslangic: v("m-bas"), bitis: v("m-bit"), aciklama: v("m-acik").trim() };
  } else if (M.kind === "fatura") {
    if (!v("m-cari")) return fail("Cari seçin."); const m = num(v("m-matrah")); if (!(m > 0)) return fail("Matrah 0'dan büyük olmalı. Ondalık için virgül kullanın, ör. 85.000,00"); if (!v("m-tarih")) return fail("Fatura tarihini girin."); col = "faturalar";
    data = { tur: v("m-tur"), cariId: v("m-cari"), personelId: v("m-tur") === "kesilen" ? v("m-personel") : "", projeId: v("m-proje"), no: v("m-no").trim(), tarih: v("m-tarih"), donem: v("m-donem") || v("m-tarih").slice(0, 7), vade: v("m-vade"), matrah: r2(m), kdvOran: +v("m-kdv"), tevkifat: +v("m-tev"), stopajOran: +v("m-stop"), aciklama: v("m-acik").trim() };
  } else {
    if (!v("m-cari")) return fail("Cari seçin."); const t = num(v("m-tutar")); if (!(t > 0)) return fail("Tutar 0'dan büyük olmalı. Ondalık için virgül kullanın, ör. 12.500,00"); if (!v("m-tarih")) return fail("Tarihi girin."); col = "hareketler";
    data = { tur: v("m-tur"), cariId: v("m-cari"), tarih: v("m-tarih"), tutar: r2(t), yontem: v("m-yontem"), projeId: v("m-proje"), aciklama: v("m-acik").trim() };
    const dagErr = checkDag(t); if (dagErr) return fail(dagErr);
    data.dagitim = Object.entries(M.dag || {}).filter(([, x]) => x > 0).map(([faturaId, tutar]) => ({ faturaId, tutar: r2(tutar) }));
  }
  if (M.rec) data.id = M.rec.id;
  rerenderModal(M => { M.busy = true; M.err = ""; });
  try {
    const j = await api("save", { q: "&t=" + col, body: data });
    let rec = j.rec; upsert(col, rec); if (j.dagitim) setDagitim(rec.id, j.dagitim);
    if (col === "faturalar" && M.file) {
      const f = new FormData(); f.append("fatura_id", rec.id); f.append("pdf", M.file, M.file.name);
      try { rec = (await api("pdf_upload", { form: f })).rec; upsert(col, rec); }
      catch (e) { S.modal.rec = rec; S.modal.file = null; render(); return fail("Fatura kaydedildi ama PDF yüklenemedi: " + e.message); }
    }
    if (M.afterSave) M.afterSave();
    const wasNew = !M.rec; closeModal(); render(); toast(wasNew ? "Eklendi" : "Kaydedildi");
    if (M.kind === "cari" && S.view === "arsiv") { S.imp.forEach(r => { if (!r.cariId && r.st !== "ok") matchRow(r); }); render(); }
  } catch (e) { fail(e.message); }
}
async function deleteRec() {
  const M = S.modal; const col = { cari: "cariler", proje: "projeler", fatura: "faturalar", hareket: "hareketler" }[M.kind];
  try {
    await api("delete", { q: "&t=" + col, body: { id: M.rec.id } });
    S.data[col] = S.data[col].filter(x => x.id !== M.rec.id);
    if (col === "hareketler") setDagitim(M.rec.id, []);
    if (col === "faturalar") S.data.dagitim = S.data.dagitim.filter(d => d.faturaId !== M.rec.id);
    if (col === "cariler") S.data.cariler.forEach(c => { if (c.musteriId === M.rec.id) c.musteriId = ""; });
    if (S.detail && S.detail.id === M.rec.id) S.detail = null;
    closeModal(); toast("Silindi"); render();
  } catch (e) { rerenderModal(M => { M.err = e.message; M.confirmDel = false; }); }
}
async function pdfDelete() {
  const M = S.modal;
  try { const j = await api("pdf_delete", { body: { id: M.rec.id } }); upsert("faturalar", j.rec); rerenderModal(M => { M.rec = j.rec; M.err = ""; }); render(); toast("PDF kaldırıldı"); }
  catch (e) { fail(e.message); }
}
async function pickPdf(file) {
  const M = S.modal; if (!M) return;
  if (!/\.pdf$/i.test(file.name) && file.type !== "application/pdf") return fail("Yalnızca PDF dosyası seçin.");
  if (file.size > S.pdfMaxMb * 1048576) return fail("PDF " + S.pdfMaxMb + " MB'tan büyük olamaz.");
  rerenderModal(M => { M.file = file; M.err = ""; });
  // Yeni faturada boş alanları PDF'ten doldur
  if (M.rec) return;
  try {
    const txt = await pdfText(file); const p = parseFatura(txt); if (S.modal !== M) return;
    const set = (id, val) => { const e = document.getElementById(id); if (e && val && !e.value) { e.value = val; return true; } return false; };
    set("m-no", p.no);
    if (set("m-acik", kalemler(txt))) personelAciklamadan();
    if (p.tarih) { const t = document.getElementById("m-tarih"); if (t && t.value === today()) t.value = p.tarih; }
    if (p.donem || p.tarih) { const d = document.getElementById("m-donem"); if (d) d.value = p.donem || p.tarih.slice(0, 7); }
    if (p.matrah) set("m-matrah", nf.format(p.matrah));
    if (p.kdvOran != null) { const k = document.getElementById("m-kdv"); if (k && [...k.options].some(o => +o.value === p.kdvOran)) k.value = p.kdvOran; }
    if (p.tevkifat) document.getElementById("m-tev").value = p.tevkifat;
    if (!v("m-cari")) { const r = { vkns: p.vkns, aliciVkn: p.aliciVkn }; matchRow(r); if (r.cariId && r.tur === v("m-tur")) { document.getElementById("m-cari").value = r.cariId; updHint(); } }
    updCalc(); toast("PDF'teki bilgiler boş alanlara yazıldı; kontrol edin");
  } catch (e) { /* metin okunamazsa alanlar elle doldurulur */ }
}
function userModal(u) {
  const root = document.getElementById("modal-root"); const nu = !u; u = u || { rol: "okur" };
  root.innerHTML = '<div class="overlay" data-m="bg"><form class="modal" id="u-form" role="dialog" aria-modal="true" aria-label="Kullanıcı"><div class="modal-head"><h2>' + (nu ? "Yeni kullanıcı" : esc(u.ad)) + '</h2><button type="button" class="btn ghost" data-m="close" aria-label="Kapat">✕</button></div><div class="form">' +
    '<label class="f">Kullanıcı adı<input id="u-k" value="' + esc(u.kullanici) + '"' + (nu ? "" : " disabled") + ' autocomplete="off"></label><label class="f">Ad soyad<input id="u-ad" value="' + esc(u.ad) + '"></label>' +
    '<label class="f">Yetki<select id="u-rol">' + opt("okur", "Salt okunur (mali müşavir)", u.rol) + opt("admin", "Yönetici", u.rol) + '</select></label><label class="f">' + (nu ? "Şifre" : "Yeni şifre (değiştirmeyecekseniz boş bırakın)") + '<input type="password" id="u-p" autocomplete="new-password"></label></div><div class="err" id="u-err" hidden></div>' +
    '<div class="modal-foot">' + (nu || u.id == S.me.id ? "" : '<button type="button" class="btn danger" id="u-del" data-id="' + u.id + '">Kullanıcıyı sil</button>') + '<div class="r"><button type="button" class="btn" data-m="close">Vazgeç</button><button class="btn primary" type="submit">Kaydet</button></div></div><input type="hidden" id="u-id" value="' + (u.id || "") + '"></form></div>';
}

function textModal(r) {
  if (!r) return;
  document.getElementById("modal-root").innerHTML = '<div class="overlay" data-m="bg"><div class="modal" role="dialog" aria-modal="true" aria-label="Okunan metin"><div class="modal-head"><h2>PDF\'ten okunan metin</h2><button type="button" class="btn ghost" data-m="close" aria-label="Kapat">✕</button></div>' +
    '<p class="small muted">' + esc(r.file.name) + ' · Bir alan yanlış ya da boş geliyorsa bu metni kopyalayıp gönderin; okumayı faturalarınıza göre ayarlayalım.</p>' +
    '<textarea id="t-text" readonly style="min-height:320px;font-family:var(--f-num);font-size:12px">' + esc(r.text || "(boş)") + '</textarea>' +
    '<div class="modal-foot"><span></span><div class="r"><button type="button" class="btn" id="t-copy">Kopyala</button><button type="button" class="btn primary" data-m="close">Kapat</button></div></div></div></div>';
}

/* ---------- toplu ödeme ---------- */
function bulkModal() {
  const ids = [...S.selPer].filter(cari);
  if (!ids.length) return;
  const rows = ids.map(id => { const c = cari(id), b = bakiye(id); return '<tr><td><b>' + esc(c.ad) + '</b><div class="small muted">Bakiye ' + balHtml(b) + '</div></td><td class="num"><input class="num" style="max-width:150px" inputmode="decimal" data-b-id="' + id + '" value="' + (b < 0 ? nf.format(-b) : "") + '" placeholder="0,00" aria-label="' + esc(c.ad) + ' ödeme tutarı"></td></tr>'; }).join("");
  document.getElementById("modal-root").innerHTML = '<div class="overlay" data-m="bg"><form class="modal" id="b-form" role="dialog" aria-modal="true" aria-label="Toplu ödeme" novalidate style="max-width:720px"><div class="modal-head"><h2>Toplu ödeme · ' + ids.length + ' personel</h2><button type="button" class="btn ghost" data-m="close" aria-label="Kapat">✕</button></div>' +
    '<div class="form"><label class="f">Ödeme tarihi<input type="date" id="b-tarih" value="' + today() + '"></label><label class="f">Yöntem<select id="b-yontem">' + ["Havale / EFT", "Nakit", "Kredi kartı", "Çek", "Senet", "Mahsup"].map(v => opt(v, v, "Havale / EFT")).join("") + '</select></label>' +
    '<label class="f full">Açıklama (her ödemeye yazılır)<input id="b-acik" value="' + esc(ymLabel(ymShift(ymNow(), -1)) + " hizmet bedeli ödemesi") + '"></label></div>' +
    '<p class="small muted" style="margin:14px 0 6px">Tutarlar personelin güncel borcuyla dolduruldu; değiştirebilirsiniz. Tutarı boş ya da 0 olan personele ödeme kaydedilmez.</p>' +
    '<div class="tw"><table><thead><tr><th>Personel</th><th class="num">Ödenen tutar</th></tr></thead><tbody>' + rows + '</tbody><tfoot><tr><td>Toplam</td><td class="num" id="b-top"></td></tr></tfoot></table></div>' +
    '<div class="err" id="b-err" hidden></div><div class="modal-foot"><span></span><div class="r"><button type="button" class="btn" data-m="close">Vazgeç</button><button type="submit" class="btn primary" id="b-save">Ödemeleri kaydet</button></div></div></form></div>';
  updBulkTotal();
}
function updBulkTotal() { const el = document.getElementById("b-top"); if (!el) return; let t = 0; document.querySelectorAll("[data-b-id]").forEach(i => t += num(i.value)); el.textContent = tl(t); }
async function saveBulk() {
  const err = document.getElementById("b-err"), btn = document.getElementById("b-save"); err.hidden = true;
  const tarih = v("b-tarih"), yontem = v("b-yontem"), acik = v("b-acik").trim();
  if (!tarih) { err.textContent = "Ödeme tarihini girin."; err.hidden = false; return; }
  const items = [...document.querySelectorAll("[data-b-id]")].map(i => ({ id: i.dataset.bId, tutar: r2(num(i.value)), el: i })).filter(x => x.tutar > 0);
  if (!items.length) { err.textContent = "En az bir personel için tutar girin."; err.hidden = false; return; }
  btn.disabled = true; btn.textContent = "Kaydediliyor…";
  let ok = 0; const bad = [];
  for (const x of items) {
    const dag = Object.entries(fifo(x.id, "odeme", x.tutar)).map(([faturaId, tutar]) => ({ faturaId, tutar }));
    try { const j = await api("save", { q: "&t=hareketler", body: { tur: "odeme", cariId: x.id, tarih, tutar: x.tutar, yontem, projeId: "", aciklama: acik, dagitim: dag } }); upsert("hareketler", j.rec); setDagitim(j.rec.id, j.dagitim); ok++; S.selPer.delete(x.id); x.el.value = ""; x.el.disabled = true; }
    catch (e) { bad.push(cariAd(x.id) + ": " + e.message); }
  }
  render();
  if (!bad.length) { closeModal(); toast(ok + " ödeme kaydedildi"); return; }
  err.textContent = ok + " ödeme kaydedildi. Kaydedilemeyenler: " + bad.join(" · "); err.hidden = false; btn.disabled = false; btn.textContent = "Kalanları tekrar dene"; updBulkTotal();
}
function payInvoices() {
  const sec = S.data.faturalar.filter(f => S.selFat.has(f.id) && kalanF(f) > 0.004).sort((a, b) => (a.tarih || "").localeCompare(b.tarih || ""));
  if (!sec.length) { toast("Seçili faturaların hepsi zaten kapanmış."); return; }
  const c = cari(sec[0].cariId), dag = {}; sec.forEach(f => dag[f.id] = kalanF(f));
  const toplam = r2(Object.values(dag).reduce((t, x) => t + x, 0));
  openModal("hareket", null, { cariId: c.id, tur: c.tip === "personel" ? "odeme" : "tahsilat", dag });
  document.getElementById("m-tutar").value = nf.format(toplam);
  document.getElementById("m-acik").value = (c.tip === "personel" ? "Ödenen faturalar: " : "Tahsil edilen faturalar: ") + sec.map(f => f.no || fd(f.tarih)).join(", ");
  updDag();
  S.modal.afterSave = () => { S.selFat = new Set(); };
}
async function autoMatch(cariId) {
  const list = S.data.hareketler.filter(x => x.cariId === cariId && bosH(x) > 0.004).sort((a, b) => (a.tarih || "").localeCompare(b.tarih || ""));
  let n = 0;
  for (const h of list) {
    const mevcut = {}; dagH(h.id).forEach(d => mevcut[d.faturaId] = d.tutar);
    const ek = fifo(cariId, h.tur, bosH(h));
    // fifo hesaplarken bu hareketin kendi dağıtımı hariç tutuldu; mevcutla birleştir
    const birlesik = Object.assign({}, mevcut); let eklendi = false;
    for (const [fid, t] of Object.entries(ek)) { const f = S.data.faturalar.find(x => x.id === fid); const yer = r2(kalanF(f) ); const ekle = Math.min(t, yer); if (ekle > 0.004) { birlesik[fid] = r2((birlesik[fid] || 0) + ekle); eklendi = true; } }
    if (!eklendi) continue;
    try {
      const j = await api("save", { q: "&t=hareketler", body: Object.assign({}, h, { dagitim: Object.entries(birlesik).map(([faturaId, tutar]) => ({ faturaId, tutar })) }) });
      upsert("hareketler", j.rec); setDagitim(j.rec.id, j.dagitim); n++;
    } catch (e) { toast(e.message); break; }
  }
  render(); toast(n ? n + " ödeme faturalarla eşleştirildi" : "Eşleştirilecek açık fatura bulunamadı");
}

/* ---------- ödeme dağıtımı (pencere içi) ---------- */
function faturaOdemeleri(f) {
  const ds = S.data.dagitim.filter(d => d.faturaId === f.id), net = calcF(f).net, od = odenenF(f.id), kal = r2(net - od);
  const g = f.tur === "gelen";
  let h = '<div class="full dagbox"><div class="dag-head"><b>' + (g ? "Ödemeler" : "Tahsilatlar") + "</b> " + durumChipF(f) + '<span class="muted small">' + (g ? "Ödenen " : "Tahsil edilen ") + tl(od) + " · kalan " + tl(Math.max(0, kal)) + "</span>" +
    (S.canWrite && kal > 0.004 ? '<button type="button" class="btn" data-act="pay-fatura" data-id="' + f.id + '">+ ' + (g ? "Ödeme ekle" : "Tahsilat ekle") + "</button>" : "") + "</div>";
  if (ds.length) h += '<table class="mini"><tbody>' + ds.map(d => { const x = S.data.hareketler.find(y => y.id === d.hareketId) || {}; return '<tr><td>' + fd(x.tarih) + "</td><td>" + esc(x.yontem || "") + '</td><td class="num">' + tl(d.tutar) + "</td></tr>"; }).join("") + "</tbody></table>";
  return h + "</div>";
}
function dagRows() {
  const M = S.modal; const cariId = v("m-cari"), tur = v("m-tur"); if (!cariId) return null;
  const haric = M.rec ? M.rec.id : null;
  const acik = acikFaturalar(cariId, tur, haric);
  Object.keys(M.dag).forEach(fid => { if (!acik.some(f => f.id === fid)) { const f = S.data.faturalar.find(x => x.id === fid); if (f && f.cariId === cariId) acik.push(f); else delete M.dag[fid]; } });
  return acik.sort((a, b) => (a.tarih || "").localeCompare(b.tarih || ""));
}
function updDag() {
  const box = document.getElementById("m-dag"); const M = S.modal; if (!box || !M || M.kind !== "hareket") return;
  const rows = dagRows();
  if (!rows) { box.innerHTML = ""; return; }
  const tutar = num(v("m-tutar")), haric = M.rec ? M.rec.id : null;
  if (!M.dagManual) M.dag = fifo(v("m-cari"), v("m-tur"), tutar, haric);
  const dagitilan = r2(Object.values(M.dag).reduce((t, x) => t + x, 0));
  const g = v("m-tur") === "odeme";
  if (!rows.length) { box.innerHTML = '<div class="dagbox small muted">Bu carinin açık ' + (g ? "gelen" : "kesilen") + " faturası yok; kayıt faturaya bağlanmadan cari hesaba işlenir.</div>"; return; }
  let h = '<div class="dagbox"><div class="dag-head"><b>Faturalara dağıt</b><span class="small muted">Dağıtılan ' + tl(dagitilan) + " / " + tl(tutar) + (tutar - dagitilan > 0.004 ? " · " + tl(tutar - dagitilan) + " faturaya bağlanmayacak" : "") + "</span>" +
    (S.canWrite ? '<button type="button" class="btn ghost" data-m="dag-auto">Eskiden yeniye dağıt</button><button type="button" class="btn ghost" data-m="dag-clear">Temizle</button>' : "") + "</div>";
  h += '<div class="tw"><table class="mini"><thead><tr><th>Fatura</th><th class="num">Tutar</th><th class="num">Kalan</th><th class="num">Bu ' + (g ? "ödemeden" : "tahsilattan") + "</th></tr></thead><tbody>";
  rows.forEach(f => { const kal = kalanF(f, haric); h += '<tr><td>' + esc(f.no || "—") + '<div class="small muted">' + fd(f.tarih) + " · " + ymLabel(f.donem) + '</div></td><td class="num">' + tl(calcF(f).net) + '</td><td class="num">' + tl(kal) + '</td><td class="num"><input class="num dag-in" inputmode="decimal" data-dag-f="' + f.id + '" data-max="' + kal + '" value="' + (M.dag[f.id] ? nf.format(M.dag[f.id]) : "") + '" placeholder="0,00"' + RO() + ' aria-label="' + esc(f.no || "Fatura") + ' için tutar"></td></tr>'; });
  box.innerHTML = h + "</tbody></table></div></div>";
}
function updDagSummary() { /* yalnızca başlıktaki toplamı yenile, girişleri yeniden çizme */
  const M = S.modal; const head = document.querySelector("#m-dag .dag-head .small"); if (!head) return;
  const tutar = num(v("m-tutar")), d = r2(Object.values(M.dag).reduce((t, x) => t + x, 0));
  head.textContent = "Dağıtılan " + tl(d) + " / " + tl(tutar) + (tutar - d > 0.004 ? " · " + tl(tutar - d) + " faturaya bağlanmayacak" : "");
}
function checkDag(tutar) {
  const M = S.modal, haric = M.rec ? M.rec.id : null; let top = 0;
  for (const [fid, t] of Object.entries(M.dag || {})) {
    if (!(t > 0)) continue; top += t;
    const f = S.data.faturalar.find(x => x.id === fid); if (!f) continue;
    const kal = kalanF(f, haric);
    if (t - kal > 0.004) return (f.no || "Fatura") + " için en fazla " + tl(kal) + " dağıtılabilir; fatura tutarı aşılamaz.";
  }
  if (top - tutar > 0.004) return "Faturalara dağıtılan toplam (" + tl(top) + ") ödeme tutarını aşıyor.";
  return "";
}

/* ---------- raporlar ---------- */
function raporData() {
  const tip = S.rTip, ft = tip === "personel" ? "gelen" : "kesilen";
  const q = S.rQ.toLocaleLowerCase("tr");
  const fats = S.data.faturalar.filter(f => f.tur === ft && (!S.rBas || f.tarih >= S.rBas) && (!S.rBit || f.tarih <= S.rBit));
  const rows = [];
  S.data.cariler.filter(c => c.tip === tip && (!q || c.ad.toLocaleLowerCase("tr").includes(q))).forEach(c => {
    const fs = fats.filter(f => f.cariId === c.id).sort((a, b) => (b.tarih || "").localeCompare(a.tarih || ""));
    if (!fs.length) return;
    const r = { c, fs, adet: fs.length, net: 0, kdv: 0, brut: 0, kesinti: 0, odenecek: 0, odenen: 0, kalan: 0, bos: 0 };
    fs.forEach(f => { const x = calcF(f), o = Math.min(odenenF(f.id), x.net); r.net += x.matrah; r.kdv += x.kdv; r.brut += x.brut; r.kesinti += x.tevkif + x.stopaj; r.odenecek += x.net; r.odenen += o; r.kalan += Math.max(0, x.net - o); });
    r.bos = r2(S.data.hareketler.filter(h => h.cariId === c.id).reduce((t, h) => t + Math.max(0, bosH(h)), 0));
    rows.push(r);
  });
  return rows.sort((a, b) => b.kalan - a.kalan || a.c.ad.localeCompare(b.c.ad, "tr"));
}
function vRapor() {
  const g = S.rTip === "personel", rows = raporData();
  const yil = new Date().getFullYear(), ay = ymNow();
  const aralik = S.rBas || S.rBit ? (S.rBas ? fd(S.rBas) : "…") + " – " + (S.rBit ? fd(S.rBit) : "…") : "Tüm tarihler";
  let h = printHead((g ? "Personel" : "Müşteri") + " raporu · " + aralik) + '<div class="top"><div><h1>Raporlar</h1><div class="sub">' + (g ? "Personelden gelen faturalar ve yapılan ödemeler" : "Müşterilere kesilen faturalar ve alınan tahsilatlar") + " · fatura tarihine göre · " + aralik + '</div></div><div class="actions"><button class="btn" data-act="csv-rapor">CSV indir</button><button class="btn" data-act="print">Yazdır</button></div></div>';
  h += '<div class="filters"><div class="seg" role="group" aria-label="Rapor türü"><button data-rtip="personel" aria-pressed="' + g + '">Personel</button><button data-rtip="musteri" aria-pressed="' + !g + '">Müşteri</button></div>' +
    '<input type="date" id="r-bas" value="' + S.rBas + '" aria-label="Başlangıç tarihi"><span class="muted">–</span><input type="date" id="r-bit" value="' + S.rBit + '" aria-label="Bitiş tarihi">' +
    '<div class="seg" role="group" aria-label="Hazır aralıklar"><button data-raralik="ay">Bu ay</button><button data-raralik="yil">' + yil + '</button><button data-raralik="gyil">' + (yil - 1) + '</button><button data-raralik="tum">Tümü</button></div>' +
    '<input type="search" id="r-q" placeholder="' + (g ? "Personel" : "Müşteri") + ' ara" value="' + esc(S.rQ) + '" style="flex:1;min-width:140px"></div>';
  if (!rows.length) return h + '<div class="panel"><div class="empty"><strong>Bu aralıkta fatura yok</strong>Tarih aralığını genişletin ya da türü değiştirin.</div></div>';
  const T = { adet: 0, net: 0, kdv: 0, brut: 0, kesinti: 0, odenecek: 0, odenen: 0, kalan: 0 };
  rows.forEach(r => Object.keys(T).forEach(k => T[k] += r[k]));
  const kes = T.kesinti > 0.004;
  h += '<div class="kpis">' + kpi("Net (KDV hariç)", tl(T.net), T.adet + " fatura") + kpi("KDV dahil", tl(T.brut), "KDV " + tl(T.kdv)) + kpi(g ? "Ödenen" : "Tahsil edilen", tl(T.odenen), (g ? "Ödenecek " : "Tahsil edilecek ") + tl(T.odenecek)) + kpi("Kalan", tl(T.kalan), rows.filter(r => r.kalan > 0.004).length + (g ? " personele borç" : " müşteriden alacak")) + "</div>";
  h += '<div class="panel"><div class="tw"><table class="rapor"><thead><tr><th>' + (g ? "Personel" : "Müşteri") + ' / fatura</th><th class="num">Fatura</th><th class="num">Net (KDV hariç)</th><th class="num">KDV</th><th class="num">KDV dahil</th>' + (kes ? '<th class="num">Tevkifat + stopaj</th>' : "") + '<th class="num">' + (g ? "Ödenecek" : "Tahsil edilecek") + '</th><th class="num">' + (g ? "Ödenen" : "Tahsil edilen") + '</th><th class="num">Kalan</th></tr></thead><tbody>';
  rows.forEach(r => {
    const acik = !!S.rAcik[r.c.id];
    h += '<tr class="click grp' + (acik ? " open" : "") + '" data-racik="' + r.c.id + '"><td><span class="caret" aria-hidden="true">' + (acik ? "▾" : "▸") + "</span> <b>" + esc(r.c.ad) + "</b>" + (r.bos > 0.004 ? '<div class="small muted">Faturaya bağlanmamış ' + (g ? "ödeme" : "tahsilat") + " " + tl(r.bos) + "</div>" : "") + '</td><td class="num">' + r.adet + '</td><td class="num">' + tl(r.net) + '</td><td class="num">' + tl(r.kdv) + '</td><td class="num">' + tl(r.brut) + "</td>" + (kes ? '<td class="num">' + tl(r.kesinti) + "</td>" : "") + '<td class="num">' + tl(r.odenecek) + '</td><td class="num">' + tl(r.odenen) + '</td><td class="num"><b>' + (r.kalan > 0.004 ? tl(r.kalan) : "—") + "</b></td></tr>";
    if (acik) r.fs.forEach(f => { const x = calcF(f), o = Math.min(odenenF(f.id), x.net);
      h += '<tr class="click det" data-edit="fatura:' + f.id + '"><td><span class="small">' + fd(f.tarih) + " · " + esc(f.no || "—") + " · " + ymLabel(f.donem) + "</span> " + durumChipF(f) + " " + pdfLink(f) + '</td><td></td><td class="num">' + tl(x.matrah) + '</td><td class="num">' + tl(x.kdv) + '</td><td class="num">' + tl(x.brut) + "</td>" + (kes ? '<td class="num">' + tl(x.tevkif + x.stopaj) + "</td>" : "") + '<td class="num">' + tl(x.net) + '</td><td class="num">' + tl(o) + '</td><td class="num">' + (x.net - o > 0.004 ? tl(x.net - o) : "—") + "</td></tr>"; });
  });
  return h + '</tbody><tfoot><tr><td>Toplam · ' + rows.length + (g ? " personel" : " müşteri") + '</td><td class="num">' + T.adet + '</td><td class="num">' + tl(T.net) + '</td><td class="num">' + tl(T.kdv) + '</td><td class="num">' + tl(T.brut) + "</td>" + (kes ? '<td class="num">' + tl(T.kesinti) + "</td>" : "") + '<td class="num">' + tl(T.odenecek) + '</td><td class="num">' + tl(T.odenen) + '</td><td class="num">' + tl(T.kalan) + '</td></tr></tfoot></table></div><p class="small muted" style="margin:10px 0 0">Satıra tıklayınca faturalar açılır. ' + (g ? "Ödenen" : "Tahsil edilen") + " sütunu yalnızca bu faturalara bağlanmış " + (g ? "ödemeleri" : "tahsilatları") + " gösterir.</p></div>";
}
function raporCsv() {
  const g = S.rTip === "personel";
  const rows = [["Satır", g ? "Personel" : "Müşteri", "VKN/TCKN", "Fatura tarihi", "Fatura no", "Dönem", "Fatura sayısı", "Net (KDV hariç)", "KDV", "KDV dahil", "Tevkifat + stopaj", g ? "Ödenecek" : "Tahsil edilecek", g ? "Ödenen" : "Tahsil edilen", "Kalan", "Durum"]];
  raporData().forEach(r => {
    rows.push(["Toplam", r.c.ad, r.c.vkn, "", "", "", r.adet, csvNum(r.net), csvNum(r.kdv), csvNum(r.brut), csvNum(r.kesinti), csvNum(r.odenecek), csvNum(r.odenen), csvNum(r.kalan), ""]);
    r.fs.forEach(f => { const x = calcF(f), o = Math.min(odenenF(f.id), x.net); rows.push(["Fatura", r.c.ad, r.c.vkn, fd(f.tarih), f.no, f.donem, 1, csvNum(x.matrah), csvNum(x.kdv), csvNum(x.brut), csvNum(x.tevkif + x.stopaj), csvNum(x.net), csvNum(o), csvNum(Math.max(0, x.net - o)), { acik: "Açık", kismi: "Kısmi", odendi: g ? "Ödendi" : "Tahsil edildi" }[durumF(f)]]); });
  });
  saveCsv(rows, "softpeak-rapor-" + S.rTip + (S.rBas ? "-" + S.rBas : "") + (S.rBit ? "-" + S.rBit : "") + ".csv");
}
function saveCsv(rows, name) {
  const blob = new Blob(["﻿" + rows.map(r => r.map(csvCell).join(";")).join("\r\n")], { type: "text/csv;charset=utf-8" });
  const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

/* ---------- A/G raporu (günlük ücretlerden aylık tahmin) ---------- */
function agGun() { const g = num(S.agGun); return g > 0 ? g : 0; }
function agSatirlar() {
  return S.data.cariler.filter(c => c.tip === "personel" && c.durum !== "pasif" && (num(c.aylikSatis) > 0 || num(c.aylikAlis) > 0))
    .sort((a, b) => a.ad.localeCompare(b.ad, "tr"))
    .map(c => {
      const k = (c.kdvOran ?? 20) / 100, al = r2(num(c.aylikSatis)), ve = r2(num(c.aylikAlis));
      const alK = r2(al * k), veK = r2(ve * k), kal = r2(al - ve), kalK = r2(veK - alK), ay = r2(kal * agGun());
      return { c, firma: c.musteriId ? cariAd(c.musteriId) : "—", al, alK, ve, veK, kal, kalK, ay, ayK: r2(ay * (1 + k)) };
    });
}
function vAgRapor() {
  const rows = agSatirlar(), g = agGun();
  const T = { al: 0, alK: 0, ve: 0, veK: 0, kal: 0, kalK: 0, ay: 0, ayK: 0 };
  rows.forEach(r => Object.keys(T).forEach(k => T[k] = r2(T[k] + r[k])));
  const n = v => '<td class="num' + (v < 0 ? " b" : "") + '">' + tl(v) + "</td>";
  let h = printHead("A/G raporu · " + g + " gün") + '<div class="top"><div><h1>A/G Raporu</h1><div class="sub">Personel kartlarındaki günlük Müşteri A/G (alınan) ve Firma A/G (verilen) tutarlarından, girdiğiniz gün sayısına göre aylık tahmin. Tutarlar KDV hariç; KDV sütunları personelin KDV oranıyla hesaplanır.</div></div><div class="actions"><button class="btn" data-act="csv-agrapor">CSV indir</button><button class="btn" data-act="print">Yazdır</button></div></div>';
  h += '<div class="panel ag-gun"><label class="f" for="ag-gun">Gün sayısı<input id="ag-gun" type="number" inputmode="numeric" min="0" max="31" step="1" value="' + esc(String(S.agGun)) + '"></label><span class="small muted">Yalnızca bu alan değiştirilebilir; diğer tutarlar personel kartlarından okunur. Kartlarda Müşteri A/G veya Firma A/G girilmiş aktif personel listelenir.</span></div>';
  if (!rows.length) return h + '<div class="panel"><div class="empty"><strong>A/G tutarı girilmiş personel yok</strong>Personel kartında Müşteri A/G ve Firma A/G alanlarını doldurun; rapor kendiliğinden oluşur.</div></div>';
  h += '<div class="panel"><div class="tw"><table class="agr"><thead><tr><th>İsim</th><th>Firma</th><th class="num">Alınan</th><th class="num">Alınan KDV</th><th class="num">Verilen</th><th class="num">Verilen KDV</th><th class="num">Kalan</th><th class="num">Kalan KDV</th><th class="num">Aylık tahmini<br>kalan</th><th class="num">KDV\'li tahmini<br>kalan</th></tr></thead><tbody>';
  rows.forEach(r => { h += '<tr class="click" data-open="' + r.c.id + '"><td><b>' + esc(r.c.ad) + "</b></td><td>" + esc(r.firma) + "</td>" + n(r.al) + n(r.alK) + n(r.ve) + n(r.veK) + n(r.kal) + n(r.kalK) + n(r.ay) + n(r.ayK) + "</tr>"; });
  h += '</tbody><tfoot><tr><td colspan="2">Toplam günlük</td>' + n(T.al) + n(T.alK) + n(T.ve) + n(T.veK) + n(T.kal) + n(T.kalK) + n(T.ay) + n(T.ayK) + "</tr>" +
    '<tr class="ag-ay"><td colspan="2">Aylık (' + g + " gün)</td>" + n(r2(T.al * g)) + n(r2(T.alK * g)) + n(r2(T.ve * g)) + n(r2(T.veK * g)) + n(r2(T.kal * g)) + n(r2(T.kalK * g)) + "<td></td><td></td></tr></tfoot></table></div></div>";
  h += '<div class="kpis">' + kpi("Aylık alınan", tl(r2(T.al * g)), "KDV dahil " + tl(r2((T.al + T.alK) * g))) + kpi("Aylık verilen", tl(r2(T.ve * g)), "KDV dahil " + tl(r2((T.ve + T.veK) * g))) + kpi("Aylık tahmini kalan", tl(T.ay), (T.al > 0 ? agYuzde(T.al, T.ve) + " · " : "") + "KDV'li " + tl(T.ayK)) + kpi("Aylık KDV farkı", tl(r2(T.kalK * g)), "Verilen KDV − alınan KDV") + "</div>";
  return h;
}
function agRaporCsv() {
  const g = agGun(), rows = [["İsim", "Firma", "Alınan", "Alınan KDV", "Verilen", "Verilen KDV", "Kalan", "Kalan KDV", "Aylık tahmini kalan", "KDV'li tahmini kalan"]];
  const T = { al: 0, alK: 0, ve: 0, veK: 0, kal: 0, kalK: 0, ay: 0, ayK: 0 };
  agSatirlar().forEach(r => { Object.keys(T).forEach(k => T[k] = r2(T[k] + r[k])); rows.push([r.c.ad, r.firma, csvNum(r.al), csvNum(r.alK), csvNum(r.ve), csvNum(r.veK), csvNum(r.kal), csvNum(r.kalK), csvNum(r.ay), csvNum(r.ayK)]); });
  rows.push(["Toplam günlük", "", csvNum(T.al), csvNum(T.alK), csvNum(T.ve), csvNum(T.veK), csvNum(T.kal), csvNum(T.kalK), csvNum(T.ay), csvNum(T.ayK)]);
  rows.push(["Aylık (" + g + " gün)", "", csvNum(T.al * g), csvNum(T.alK * g), csvNum(T.ve * g), csvNum(T.veK * g), csvNum(T.kal * g), csvNum(T.kalK * g), "", ""]);
  saveCsv(rows, "softpeak-ag-raporu-" + g + "-gun.csv");
}

/* ---------- CSV ---------- */
function csvCell(x) { x = String(x ?? ""); return /[";\n]/.test(x) ? '"' + x.replace(/"/g, '""') + '"' : x; }
function csvNum(n) { return String(r2(n)).replace(".", ","); }
function csv(kind) {
  let rows, name;
  if (kind === "fatura") {
    rows = [["Tarih", "Tür", "Cari", "VKN/TCKN", "Fatura no", "Dönem", "Personel", "Proje", "Matrah", "KDV %", "KDV", "Tevkifat", "Stopaj", "Net", "PDF", "Açıklama"]];
    filteredFaturalar().slice().reverse().forEach(f => { const c = calcF(f), cr = cari(f.cariId) || {}; rows.push([fd(f.tarih), f.tur === "kesilen" ? "Kesilen" : "Gelen", cr.ad, cr.vkn, f.no, f.donem, f.personelId ? cariAd(f.personelId) : "", f.projeId ? (proje(f.projeId) || {}).ad : "", csvNum(c.matrah), f.kdvOran, csvNum(c.kdv), csvNum(c.tevkif), csvNum(c.stopaj), csvNum(c.net), f.pdf ? "Var" : "", f.aciklama]); });
    name = "softpeak-faturalar" + (S.fDonem ? "-" + S.fDonem : "") + ".csv";
  } else {
    rows = [["Tarih", "Tür", "Cari", "Tutar", "Yöntem", "Proje", "Açıklama"]];
    S.data.hareketler.slice().sort((a, b) => (a.tarih || "").localeCompare(b.tarih || "")).forEach(x => rows.push([fd(x.tarih), x.tur === "tahsilat" ? "Tahsilat" : "Ödeme", cariAd(x.cariId), csvNum(x.tutar), x.yontem, x.projeId ? (proje(x.projeId) || {}).ad : "", x.aciklama]));
    name = "softpeak-odeme-tahsilat.csv";
  }
  const blob = new Blob(["﻿" + rows.map(r => r.map(csvCell).join(";")).join("\r\n")], { type: "text/csv;charset=utf-8" });
  const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

/* ---------- olaylar ---------- */
document.addEventListener("click", e => {
  if (e.target.closest("[data-stop]")) return; // tablo satırındaki PDF bağlantısı
  const selCell = e.target.closest("td.sel,th.sel");
  if (selCell) { if (e.target === selCell) { const cb = selCell.querySelector("input"); if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event("change", { bubbles: true })); } } return; }
  const tx = e.target.closest("[data-imp-text]"); if (tx) { e.preventDefault(); textModal(S.imp[+tx.dataset.impText]); return; }
  const vw = e.target.closest("[data-imp-view]"); if (vw) { e.preventDefault(); const r = S.imp[+vw.dataset.impView]; if (r) window.open(URL.createObjectURL(r.file), "_blank"); return; }
  const t = e.target.closest("button,tr[data-open],tr[data-edit],tr[data-user],tr[data-racik],[data-m],a[data-act]"); if (!t) return;
  if (t.tagName === "A" && t.dataset.act) e.preventDefault();
  if (t.dataset.nav) { S.view = t.dataset.nav; S.detail = null; if (S.view === "ayar") loadAyar(); render(); window.scrollTo(0, 0); return; }
  if (t.dataset.open) { S.detail = { type: "cari", id: t.dataset.open }; S.view = (cari(t.dataset.open) || {}).tip === "personel" ? "personel" : "musteri"; render(); window.scrollTo(0, 0); return; }
  if (t.dataset.proje) { S.detail = { type: "proje", id: t.dataset.proje }; S.view = "proje"; render(); window.scrollTo(0, 0); return; }
  if (t.dataset.edit) { const [k, id] = t.dataset.edit.split(":"); const rec = S.data[k === "fatura" ? "faturalar" : "hareketler"].find(x => x.id === id); if (rec) openModal(k, rec); return; }
  if (t.dataset.user) { userModal((S.users || []).find(u => String(u.id) === t.dataset.user)); return; }
  if (t.dataset.donem) { S.donem = ymShift(S.donem, +t.dataset.donem); render(); return; }
  if (t.dataset.ftur) { S.fTur = t.dataset.ftur; render(); return; }
  if (t.dataset.fdurum) { S.fDurum = t.dataset.fdurum; render(); return; }
  if (t.dataset.rtip) { S.rTip = t.dataset.rtip; S.rAcik = {}; render(); return; }
  if (t.dataset.racik) { S.rAcik[t.dataset.racik] = !S.rAcik[t.dataset.racik]; render(); return; }
  if (t.dataset.raralik) { const y = new Date().getFullYear(), a = t.dataset.raralik, m = ymNow();
    if (a === "ay") { S.rBas = m + "-01"; S.rBit = m + "-31"; } else if (a === "yil") { S.rBas = y + "-01-01"; S.rBit = y + "-12-31"; } else if (a === "gyil") { S.rBas = (y - 1) + "-01-01"; S.rBit = (y - 1) + "-12-31"; } else { S.rBas = ""; S.rBit = ""; }
    render(); return; }
  if (t.dataset.htur) { S.hTur = t.dataset.htur; render(); return; }
  if (t.dataset.mtur || t.dataset.mhtur) {
    const tur = t.dataset.mtur || t.dataset.mhtur, st = snapshotForm();
    S.modal.preset = Object.assign({}, S.modal.preset, { tur, cariId: "", dag: null }); if (S.modal.kind === "hareket") { S.modal.dag = {}; S.modal.dagManual = false; }
    if (S.modal.rec) S.modal.rec = Object.assign({}, S.modal.rec, { tur, cariId: "", personelId: "" });
    renderModal(); delete st["m-cari"]; delete st["m-personel"]; delete st["m-proje"]; restore(st); return;
  }
  if (t.id === "u-del") { if (t.dataset.confirm) { api("user_delete", { body: { id: t.dataset.id } }).then(() => { closeModal(); toast("Kullanıcı silindi"); loadAyar(); }).catch(er => toast(er.message)); } else { t.dataset.confirm = "1"; t.textContent = "Silmek için tekrar tıklayın"; } return; }
  if (t.id === "t-copy") { const ta = document.getElementById("t-text"); (navigator.clipboard ? navigator.clipboard.writeText(ta.value) : Promise.reject()).then(() => toast("Kopyalandı")).catch(() => { ta.select(); document.execCommand("copy"); toast("Kopyalandı"); }); return; }
  if (t.id === "logout") { api("logout", { method: "POST" }).finally(() => location.reload()); return; }
  const a = t.dataset.act;
  if (a === "new-fatura") openModal("fatura", null, { cariId: t.dataset.cari, projeId: t.dataset.proje });
  else if (a === "new-hareket") openModal("hareket", null, { cariId: t.dataset.cari, projeId: t.dataset.proje });
  else if (a === "new-cari") openModal("cari", null, { tip: t.dataset.tip });
  else if (a === "edit-cari") openModal("cari", cari(t.dataset.id));
  else if (a === "new-proje") openModal("proje");
  else if (a === "edit-proje") openModal("proje", proje(t.dataset.id));
  else if (a === "clr-donem") { S.fDonem = ""; render(); }
  else if (a === "csv-fatura") csv("fatura"); else if (a === "csv-hareket") csv("hareket");
  else if (a === "print") window.print();
  else if (a === "csv-rapor") raporCsv();
  else if (a === "csv-agrapor") agRaporCsv();
  else if (a === "acik-doldur") aciklamalariDoldur();
  else if (a === "auto-match") autoMatch(t.dataset.id);
  else if (a === "pay-fatura") { const f = S.data.faturalar.find(x => x.id === t.dataset.id); if (f) { const k = kalanF(f); openModal("hareket", null, { cariId: f.cariId, tur: f.tur === "gelen" ? "odeme" : "tahsilat", dag: { [f.id]: k } }); document.getElementById("m-tutar").value = nf.format(k); document.getElementById("m-acik").value = (f.tur === "gelen" ? "Fatura ödemesi: " : "Fatura tahsilatı: ") + (f.no || fd(f.tarih)); updDag(); } }
  else if (a === "logout") { api("logout", { method: "POST" }).finally(() => location.reload()); }
  else if (a === "sel-clear") { S.selPer.clear(); render(); }
  else if (a === "bulk-pay") bulkModal();
  else if (a === "selfat-clear") { S.selFat.clear(); render(); }
  else if (a === "selfat-pay") payInvoices();
  else if (a === "new-user") userModal(null);
  else if (a === "imp-save") saveImports();
  else if (a === "imp-clear") { if (!S.impBusy) { S.imp = []; render(); } }
  else if (a === "imp-clear-ok") { S.imp = S.imp.filter(r => r.st !== "ok"); render(); }
  else if (a === "imp-del") { S.imp.splice(+t.dataset.row, 1); render(); }
  else if (a === "imp-new-cari") { const r = S.imp[+t.dataset.row]; openModal("cari", null, { tip: r.tur === "gelen" ? "personel" : "musteri", vkn: r.vknHint }); }
  const m = t.dataset.m;
  if (m === "close" || (m === "bg" && e.target === t)) closeModal();
  else if (m === "del") rerenderModal(M => { M.confirmDel = true; });
  else if (m === "del-no") rerenderModal(M => { M.confirmDel = false; });
  else if (m === "del-yes") deleteRec();
  else if (m === "pdf-unpick") rerenderModal(M => { M.file = null; });
  else if (m === "pdf-del") pdfDelete();
  else if (m === "dag-auto") { S.modal.dagManual = false; updDag(); S.modal.dagManual = true; }
  else if (m === "dag-clear") { S.modal.dag = {}; S.modal.dagManual = true; updDag(); }
});
document.addEventListener("submit", async e => {
  e.preventDefault();
  if (e.target.id === "m-form") saveModal();
  else if (e.target.id === "b-form") saveBulk();
  else if (e.target.id === "pw-form") {
    try { await api("password", { body: { eski: v("pw-old"), yeni: v("pw-new") } }); e.target.reset(); toast("Şifreniz değiştirildi"); } catch (er) { toast(er.message); }
  } else if (e.target.id === "u-form") {
    const err = document.getElementById("u-err"); err.hidden = true;
    try { await api("user_save", { body: { id: v("u-id"), kullanici: v("u-k").trim(), ad: v("u-ad").trim(), rol: v("u-rol"), sifre: v("u-p") } }); closeModal(); toast("Kullanıcı kaydedildi"); loadAyar(); }
    catch (er) { err.textContent = er.message; err.hidden = false; }
  }
});
document.addEventListener("keydown", e => { if (e.key === "Escape" && document.getElementById("modal-root").innerHTML) closeModal(); });
let qT;
document.addEventListener("input", e => {
  const id = e.target.id;
  if (["m-matrah", "m-kdv", "m-tev", "m-stop"].includes(id)) updCalc();
  if (["m-satis", "m-alis", "m-agkdv"].includes(id)) updAg();
  if (id === "m-acik" && S.modal && S.modal.kind === "fatura") { clearTimeout(S.acikT); S.acikT = setTimeout(personelAciklamadan, 400); }
  if (e.target.dataset && e.target.dataset.bId) updBulkTotal();
  if (e.target.dataset && e.target.dataset.dagF && S.modal) { const x = num(e.target.value); if (x > 0) S.modal.dag[e.target.dataset.dagF] = r2(x); else delete S.modal.dag[e.target.dataset.dagF]; S.modal.dagManual = true; updDagSummary(); }
  if (id === "m-tutar" && S.modal && S.modal.kind === "hareket") { if (S.modal.dagManual) updDagSummary(); else updDag(); }
  if (id === "ag-gun") { S.agGun = e.target.value; try { localStorage.setItem("spcari-ag-gun", e.target.value); } catch (x) {} clearTimeout(qT); qT = setTimeout(render, 150); }
  if (id === "r-q") { S.rQ = e.target.value; clearTimeout(qT); qT = setTimeout(render, 150); }
  if (id === "f-q") { S.fQ = e.target.value; clearTimeout(qT); qT = setTimeout(render, 150); }
  if (id === "h-q") { S.hQ = e.target.value; clearTimeout(qT); qT = setTimeout(render, 150); }
  const row = e.target.closest && e.target.closest("tr[data-row]");
  if (row && e.target.dataset.f) { const r = S.imp[+row.dataset.row]; const f = e.target.dataset.f; r[f] = f === "matrah" ? num(e.target.value) : e.target.value; if (f === "tarih" && e.target.value && !r.donemEdited) { r.donem = e.target.value.slice(0, 7); const d = row.querySelector('[data-f="donem"]'); if (d) d.value = r.donem; } if (f === "donem") r.donemEdited = true; }
});
document.addEventListener("change", e => {
  const id = e.target.id;
  const sd = e.target.dataset && e.target.dataset.sel;
  if (sd) {
    if (sd === "per") e.target.checked ? S.selPer.add(e.target.value) : S.selPer.delete(e.target.value);
    else if (sd === "per-all") S.data.cariler.filter(c => c.tip === "personel").forEach(c => e.target.checked ? S.selPer.add(c.id) : S.selPer.delete(c.id));
    else if (sd === "fat") e.target.checked ? S.selFat.add(e.target.value) : S.selFat.delete(e.target.value);
    else if (sd === "fat-all") S.data.faturalar.filter(f => f.cariId === S.selFatCari).forEach(f => e.target.checked ? S.selFat.add(f.id) : S.selFat.delete(f.id));
    render(); return;
  }
  if (id === "donem-sec" && e.target.value) { S.donem = e.target.value; render(); }
  if (id === "f-donem") { S.fDonem = e.target.value; render(); }
  if (id === "r-bas") { S.rBas = e.target.value; render(); }
  if (id === "r-bit") { S.rBit = e.target.value; render(); }
  if (id === "imp-files") { addImportFiles(e.target.files); e.target.value = ""; return; }
  if (id === "m-pdf" && e.target.files[0]) { pickPdf(e.target.files[0]); return; }
  const row = e.target.closest && e.target.closest("tr[data-row]");
  if (row && e.target.dataset.f === "tur") { const r = S.imp[+row.dataset.row]; r.tur = e.target.value; r.cariId = ""; render(); return; }
  if (["m-kdv", "m-tev", "m-stop"].includes(id)) updCalc();
  if (id === "m-cari") {
    updHint();
    if (S.modal && S.modal.kind === "hareket") { S.modal.dag = {}; S.modal.dagManual = false; updDag(); }
    if (S.modal && S.modal.kind === "fatura" && !S.modal.rec) {
      const c = cari(e.target.value), mt = document.getElementById("m-matrah");
      if (c && c.tip === "personel" && c.kdvOran != null) { const k = document.getElementById("m-kdv"); if (k && [...k.options].some(o => +o.value === +c.kdvOran)) { k.value = c.kdvOran; updCalc(); } }
      if (c && c.tip === "musteri") { const ps = document.getElementById("m-proje"); if (ps) ps.innerHTML = projeOpts(ps.value, c.id); }
    }
    if (S.modal && S.modal.kind === "hareket") { const c = cari(e.target.value); if (c && c.tip === "musteri") { const ps = document.getElementById("m-proje"); if (ps) ps.innerHTML = projeOpts(ps.value, c.id); } }
  }
  if (id === "m-personel" && S.modal && !S.modal.rec) {
    const p = cari(e.target.value), mt = document.getElementById("m-matrah"), cs = document.getElementById("m-cari");
    if (p) { if (p.kdvOran != null) { const k = document.getElementById("m-kdv"); if (k && [...k.options].some(o => +o.value === +p.kdvOran)) { k.value = p.kdvOran; updCalc(); } } if (p.musteriId && !cs.value) { cs.value = p.musteriId; updHint(); } }
  }
});
["dragover", "dragenter"].forEach(ev => document.addEventListener(ev, e => { const d = e.target.closest && e.target.closest("#drop"); if (d) { e.preventDefault(); d.classList.add("over"); } }));
document.addEventListener("dragleave", e => { const d = e.target.closest && e.target.closest("#drop"); if (d) d.classList.remove("over"); });
document.addEventListener("drop", e => { const d = e.target.closest && e.target.closest("#drop"); if (d) { e.preventDefault(); d.classList.remove("over"); addImportFiles(e.dataTransfer.files); } });
window.addEventListener("beforeunload", e => { if (S.imp.some(r => r.st !== "ok") || (S.modal && S.modal.file)) { e.preventDefault(); e.returnValue = ""; } });

/* ---------- açılış ---------- */
(async () => {
  try {
    const me = await api("me");
    S.me = me.user; S.csrf = me.csrf; S.softpeakVkn = me.softpeakVkn || ""; S.pdfMaxMb = me.pdfMaxMb || 20; S.canWrite = me.user.rol === "admin";
    const all = await api("all");
    COLS.forEach(c => S.data[c] = all.data[c] || []);
    S.data.dagitim = all.data.dagitim || [];
    S.loaded = true;
    const h = location.hash.slice(1); if (navItems().some(([k]) => k === h)) S.view = h;
    render();
  } catch (e) {
    document.getElementById("main").innerHTML = '<div class="panel"><div class="empty"><strong>Kayıtlar yüklenemedi</strong>' + esc(e.message) + "</div></div>";
  }
})();
})();
