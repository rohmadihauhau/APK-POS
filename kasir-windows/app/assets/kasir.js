let diskon = 0;
const cart =
new Map();
let metode =
    'tunai';
let busy = false;
let transaksiSukses = false;
let closingData =
    null;
const $ =
id =>
document.getElementById(id);
const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const rp =
n =>
'Rp ' +
Number(n || 0)
.toLocaleString(
    'id-ID'
);
const pf =
id =>
P.find(
    x =>
        x.id == id
);
/* ============================================================
   TOTAL
   ============================================================ */
function subtotal()
{
    return Math.round(
        [...cart]
        .reduce(
            (s,[id,item]) => {
                const p =
                    pf(id);
                if (!p)
                    return s;
                return s +
                    (
                        Number(item.qty) *
                        Number(p.harga)
                    );
            },
            0
        )
    );
}
/* ============================================================
   DISKON
   ============================================================ */
function diskonNominal()
{
    return Math.round(
        subtotal() *
        diskon / 100
    );
}
function total()
{
    return subtotal() -
        diskonNominal();
}
function renderDiskon()
{
    $('dgrid').innerHTML =
        `<button class="nol ${diskon === 0 ? 'on' : ''}"
            onclick="setDiskon(0)">Tanpa</button>`
        +
        DISKON_LIST
        .map(
            d => `<button class="${diskon === d ? 'on' : ''}"
                onclick="setDiskon(${d})">${d}%</button>`
        )
        .join('');
    const ada =
        diskon > 0 &&
        cart.size > 0;
    $('dlabel').textContent =
        diskon > 0
            ? 'Diskon ' + diskon + '%'
            : 'Tanpa diskon';
    $('drsub').classList
        .toggle('hide', !ada);
    $('drdis').classList
        .toggle('hide', !ada);
    $('dsub').textContent =
        rp(subtotal());
    $('dnom').textContent =
        '- ' + rp(diskonNominal());
}
function setDiskon(d)
{
    if (transaksiSukses)
        return;
    diskon =
        DISKON_LIST.includes(d)
            ? d
            : 0;
    draw();
    $('scan').focus();
}
/* ============================================================
   TOAST
   ============================================================ */
function toast(
    m,
    err=false
)
{
    const t =
        $('toast');
    t.textContent =
        m;
    t.className =
        'toast show' +
        (
            err
                ? ' err'
                : ''
        );
    clearTimeout(
        t._t
    );
    t._t =
        setTimeout(
            () =>
                t.className='toast',
            2200
        );
}
/* ============================================================
   BEEP
   ============================================================ */
function beep()
{
    try {
        const a =
            new AudioContext();
        const o =
            a.createOscillator();
        o.frequency.value =
            900;
        o.connect(
            a.destination
        );
        o.start();
        setTimeout(
            () =>
                o.stop(),
            90
        );
    } catch(e) {}
}
/* ============================================================
   ADD PRODUK
   ============================================================ */
function add(
    id,
    qty=1,
    isWeight=false
)
{
    const p =
        pf(id);
    if (!p) {
        toast(
            'Produk tidak ditemukan',
            true
        );
        return;
    }
    qty =
        Number(qty);
    if (
        !Number.isFinite(qty) ||
        qty <= 0
    ) {
        return;
    }
    /*
     * Barang timbang:
     * maksimum 3 angka desimal.
     */
    if (isWeight) {
        qty =
            Math.round(
                qty * 1000
            ) / 1000;
    } else {
        qty =
            Math.floor(qty);
    }
    const old =
        cart.get(id);
    if (old) {
        old.qty =
            Number(old.qty) +
            qty;
        old.qty =
            Math.round(
                old.qty * 1000
            ) / 1000;
        old.weight =
            old.weight ||
            isWeight;
    } else {
        cart.set(
            id,
            {
                qty: qty,
                weight: Boolean(isWeight)
            }
        );
    }
    beep();
    const item =
        cart.get(id);
    $('last').innerHTML = `
<span>
${esc(p.nama)}
${
    item.weight
        ? ' · ' +
          Number(item.qty)
          .toFixed(3) +
          ' kg'
        : ''
}
</span>
<b>
${
    rp(
        Number(p.harga) *
        Number(item.qty)
    )
}
</b>
`;
    draw();
}
/* ============================================================
   UBAH QTY
   ============================================================ */
function chg(
    id,
    d
)
{
    const item =
        cart.get(id);
    if (!item)
        return;
    const p =
        pf(id);
    if (!p)
        return;
    const step =
        item.weight
            ? 0.001
            : 1;
    let q =
        Number(item.qty) +
        (
            d * step
        );
    q =
        Math.round(
            q * 1000
        ) / 1000;
    if (q <= 0) {
        cart.delete(id);
    } else {
        /*
         * Barang biasa tidak boleh
         * melebihi stok yang tersedia.
         *
         * Barang timbang tetap bisa
         * menggunakan stok decimal.
         */
        if (
            q > Number(p.stok)
        ) {
            return toast(
                'Stok ' +
                p.nama +
                ' tidak cukup',
                true
            );
        }
        item.qty =
            q;
    }
    draw();
}
/* ============================================================
   DELETE
   ============================================================ */
function del(id)
{
    cart.delete(id);
    draw();
}
/* ============================================================
   CLEAR
   ============================================================ */
function clr()
{
    cart.clear();
    diskon = 0;
    draw();
    $('bayar').value =
        '';
    $('scan').value =
        '';
    $('scan').focus();
}
/* ============================================================
   DRAW
   ============================================================ */
function draw()
{
    const t =
        total();
    $('rows').innerHTML =
        cart.size
        ?
        [...cart]
        .map(
            ([id,item],x) => {
                const p =
                    pf(id);
                if (!p)
                    return '';
                const q =
                    Number(item.qty);
                return `
<tr>
<td>
    ${x+1}
</td>
<td class="mono">
    ${esc(p.kode)}
</td>
<td>
    <b>
        ${esc(p.nama)}
    </b>
</td>
<td>
    ${rp(p.harga)}/${esc(p.satuan)}
</td>
<td>
<div class="qty">
<button
    onclick="chg(${id},-1)"
>
    −
</button>
<span>
${
    item.weight
        ? q.toFixed(3) + ' kg'
        : q
}
</span>
<button
    onclick="chg(${id},1)"
>
    +
</button>
</div>
</td>
<td>
<b>
    ${rp(
        Number(p.harga) * q
    )}
</b>
</td>
<td>
<button
    class="x"
    onclick="del(${id})"
>
    ✕
</button>
</td>
</tr>
`;
            }
        )
        .join('')
        :
        `
<tr>
<td
    colspan="7"
    class="empty"
>
    Scan barcode atau masukkan produk manual untuk memulai transaksi
</td>
</tr>
`;
    $('total').textContent =
        rp(t);
    const jumlah =
        [...cart.values()]
        .reduce(
            (a,b) =>
                a +
                Number(b.qty),
            0
        );
    $('cnt').textContent =
        Number.isInteger(jumlah)
            ? jumlah + ' item'
            : jumlah.toFixed(3) + ' kg';
    $('quick').innerHTML =
        [
            t,
            50000,
            100000,
            200000
        ]
        .filter(
            (v,i,a) =>
                t > 0 &&
                v >= t &&
                a.indexOf(v) == i
        )
        .map(
            v => `
<button
    onclick="setBayar(${v})"
>
    ${
        v == t
            ? 'Uang pas'
            : rp(v)
    }
</button>
`
        )
        .join('');
    renderDiskon();
    kem();
}
/* ============================================================
   NILAI UANG
   ============================================================ */
function nilaiUang(el)
{
    return Number(
        String(
            el.value || ''
        )
        .replace(/\D/g,'')
    ) || 0;
}
/* ============================================================
   KEMBALIAN
   ============================================================ */
function kem()
{
    const bayar =
        nilaiUang(
            $('bayar')
        );
    const k =
        bayar -
        total();
    $('kembali')
        .textContent =
        rp(
            k > 0
                ? k
                : 0
        );
}
/* ============================================================
   METODE
   ============================================================ */
function setM(m)
{
    metode =
        m;
    $('m1')
        .classList
        .toggle(
            'on',
            m === 'tunai'
        );
    $('m2')
        .classList
        .toggle(
            'on',
            m === 'qris'
        );
    $('cash').style.display =
        m === 'tunai'
            ? 'block'
            : 'none';
}
/* ============================================================
   SUGGESTION
   ============================================================ */
function sug()
{
    const q =
        $('scan')
        .value
        .trim()
        .toLowerCase();
    const s =
        $('sug');
    const m =
        q &&
        !/^\d{6,}$/.test(q)
        ?
        P.filter(
            p =>
                p.nama
                    .toLowerCase()
                    .includes(q)
                ||
                (
                    p.kode &&
                    p.kode.includes(q)
                )
        )
        .slice(
            0,
            6
        )
        :
        [];
    s.style.display =
        m.length
            ? 'block'
            : 'none';
    s.innerHTML =
        m.map(
            p => `
<div
    onclick="pick(${p.id})"
>
<span>
<b>
    ${esc(p.nama)}
</b>
<small class="mono">
    ${esc(p.kode)}
</small>
</span>
<span>
    ${rp(p.harga)}/${esc(p.satuan)}
</span>
</div>
`
        )
        .join('');
}
/* ============================================================
   PICK
   ============================================================ */
function pick(id)
{
    add(
        id,
        1,
        false
    );
    $('scan').value =
        '';
    sug();
    $('scan').focus();
}
/* ============================================================
   BARCODE TIMBANGAN
   Format label timbang (EAN-13, 13 digit):
     0555001 00524 5
     |       |     |
     |       |     +-- 1 digit check digit
     |       +-------- 5 digit berat dalam GRAM (00524 = 0,524 kg)
     +---------------- 7 digit kode PLU produk
   Digit ke-13 (check digit) bukan bagian dari berat.
   ============================================================ */
function eanValid(v)
{
    if (!/^\d{13}$/.test(v))
        return false;
    let sum = 0;
    for (let i = 0; i < 12; i++)
        sum += (i % 2 === 0 ? 1 : 3) * (v.charCodeAt(i) - 48);
    return ((10 - (sum % 10)) % 10) === (v.charCodeAt(12) - 48);
}
function parseScaleBarcode(raw)
{
    const v =
        String(raw)
        .replace(/\D/g,'');
    /*
     * Harus 13 digit dan check digit-nya benar.
     * Ini mencegah salah baca kamera menghasilkan berat ngawur.
     */
    if (!eanValid(v))
        return null;
    const plu =
        v.substring(0, 7);
    const beratGram =
        parseInt(
            v.substring(7, 12),
            10
        );
    if (
        !Number.isFinite(beratGram) ||
        beratGram <= 0
    )
        return null;
    return {
        barcode:
            v,
        kode:
            plu,
        berat:
            beratGram / 1000
    };
}
/* ============================================================
   CARI PRODUK SCALE
   ============================================================ */
function findProductByScaleCode(
    kode
)
{
    /*
     * 1. Cocok persis dengan 7 digit PLU (0555001).
     */
    let p =
        P.find(
            x =>
                String(x.kode) ===
                String(kode)
        );
    if (p)
        return p;
    /*
     * 2. Abaikan angka 0 di depan, jadi produk yang
     *    disimpan sebagai 555001 tetap ketemu.
     */
    const normalized =
        String(kode)
        .replace(
            /^0+/,
            ''
        );
    if (!normalized)
        return undefined;
    return P.find(
        x =>
            String(x.kode)
            .replace(
                /^0+/,
                ''
            ) ===
            normalized
    );
}
/* ============================================================
   PROCESS SCAN
   Mengembalikan { ok, text } supaya layar kamera bisa
   menampilkan hasilnya. Pemanggil lain boleh mengabaikan.
   ============================================================ */
function processScan(
    raw
)
{
    const v =
        String(raw)
        .replace(
            /#/g,
            ''
        )
        .trim();
    if (!v)
        return { ok:false, text:'' };
    /*
     * 1. Barcode produk biasa (kode persis sama).
     *    Dicek lebih dulu supaya produk kemasan 13 digit
     *    tidak salah dianggap label timbangan.
     */
    let p =
        P.find(
            x =>
                String(x.kode) ===
                v
        );
    if (p) {
        add(
            p.id,
            1,
            false
        );
        return {
            ok:true,
            text:p.nama
        };
    }
    /*
     * 2. Label timbangan: 7 digit PLU + 5 digit gram.
     */
    const scale =
        parseScaleBarcode(v);
    if (scale) {
        const sp =
            findProductByScaleCode(
                scale.kode
            );
        if (!sp) {
            const msg =
                'PLU ' +
                scale.kode +
                ' belum terdaftar di produk';
            toast(
                msg,
                true
            );
            return { ok:false, text:msg };
        }
        const berat =
            scale.berat;
        add(
            sp.id,
            berat,
            true
        );
        return {
            ok:true,
            text:
                sp.nama +
                ' · ' +
                berat.toFixed(3) +
                ' kg · ' +
                rp(
                    Number(sp.harga) *
                    berat
                )
        };
    }
    /*
     * 3. Ketik nama buah (hanya bila cocok tepat 1 produk).
     */
    if (!/^\d+$/.test(v)) {
        const m =
            P.filter(
                x =>
                    x.nama
                    .toLowerCase()
                    .includes(
                        v.toLowerCase()
                    )
            );
        if (
            m.length === 1
        ) {
            add(
                m[0].id,
                1,
                false
            );
            return {
                ok:true,
                text:m[0].nama
            };
        }
    }
    const msg =
        'Kode "' +
        v +
        '" tidak ditemukan';
    toast(
        msg,
        true
    );
    return { ok:false, text:msg };
}
/* ============================================================
   INPUT BAYAR
   ============================================================ */
$('bayar').oninput =
e => {
    rpi(
        e.target
    );
    kem();
};
/* ============================================================
   ENTER = BAYAR
   ============================================================ */
$('bayar').onkeydown =
e => {
    if (
        e.key === 'Enter'
    ) {
        e.preventDefault();
        $('pay').click();
    }
};
/* ============================================================
   SCAN INPUT
   ============================================================ */
$('scan').oninput =
e => {
    e.target.value =
        e.target.value
        .replace(
            /#/g,
            ''
        );
    sug();
};
$('scan').addEventListener(
    'keydown',
    e => {
        if (
            e.key === '#'
        ) {
            e.preventDefault();
            return;
        }
        if (
            e.key !== 'Enter'
        )
            return;
        e.preventDefault();
        const v =
            e.target.value
            .replace(
                /#/g,
                ''
            )
            .trim();
        if (!v)
            return;
        processScan(v);
        e.target.value =
            '';
        sug();
        $('scan').focus();
    }
);
/* ============================================================
   BAYAR
   ============================================================ */
$('pay').onclick =
() => {
    if (!cart.size) {
        return toast(
            'Pesanan masih kosong',
            true
        );
    }
    if (
        metode === 'tunai'
    ) {
        const bayar =
            nilaiUang(
                $('bayar')
            );
        if (
            bayar < total()
        ) {
            return toast(
                'Uang diterima kurang',
                true
            );
        }
        send();
    } else {
        $('qtot')
            .textContent =
            rp(total());
        $('qimg').innerHTML =
            Q
            ?
            `<img src="${Q}">`
            :
            `
<div class="noq">
Taruh gambar QRIS toko di
<br>
<b>
    assets/qris.png
</b>
</div>
`;
        $('qris')
            .classList
            .add('show');
    }
};
/* ============================================================
   SEND TRANSACTION
   ============================================================ */
async function send()
{
    /*
     * Jangan kirim transaksi kalau sedang diproses
     * atau transaksi sebelumnya sudah berhasil.
     */
    if (busy || transaksiSukses) {
        return;
    }
    /*
     * Pastikan ada barang.
     */
    if (!cart.size) {
        return toast(
            'Pesanan masih kosong',
            true
        );
    }
    /*
     * Kunci proses transaksi.
     */
    busy = true;
    /*
     * Kunci tombol BAYAR.
     */
    const payBtn =
        $('pay');
    if (payBtn) {
        payBtn.disabled = true;
        payBtn.innerHTML =
            '⏳ Memproses...';
        payBtn.style.opacity =
            '0.6';
        payBtn.style.pointerEvents =
            'none';
    }
    /*
     * Ambil semua produk dari cart.
     *
     * Contoh:
     *
     * Telur
     * Kentang
     * Apel
     *
     * semuanya dikirim dalam SATU request.
     */
    const ids =
        [...cart.keys()];
    /*
     * Data untuk struk.
     */
    const lines =
        ids.map(
            id => {
                const p =
                    pf(id);
                const item =
                    cart.get(id);
                const q =
                    Number(item.qty);
                return [
                    p.nama,
                    q,
                    Number(p.harga) * q,
                    Boolean(item.weight)
                ];
            }
        );
    /*
     * Payload ke API.
     *
     * SEMUA ITEM dikirim sekaligus.
     */
    const payload = {
        items:
            ids.map(
                id => ({
                    id:
                        Number(id),
                    qty:
                        Number(
                            cart.get(id).qty
                        )
                })
            ),
        /*
         * Kalau QRIS:
         * otomatis dianggap bayar sesuai total.
         *
         * Kalau tunai:
         * ambil nominal dari input bayar.
         */
        bayar:
            metode === 'qris'
                ? total()
                : nilaiUang(
                    $('bayar')
                ),
        metode:
            metode,
        diskon:
            diskon
    };
    try {
        /*
         * Kirim SATU request ke API.
         */
        const r =
            await fetch(
                'api.php',
                {
                    method:
                        'POST',
                    headers: {
                        'Content-Type':
                            'application/json',
                        'X-Requested-With':
                            'XMLHttpRequest'
                    },
                    body:
                        JSON.stringify(
                            payload
                        )
                }
            );
        /*
         * Session kasir habis.
         */
        if (
            r.status === 401
        ) {
            alert(
                'Sesi kasir berakhir, silakan login ulang'
            );
            location.reload();
            return;
        }
        /*
         * Ambil response API.
         */
        const d =
            await r.json();
        /*
         * Tutup popup QRIS.
         */
        $('qris')
            .classList
            .remove(
                'show'
            );
        /*
         * Kalau API mengembalikan error,
         * transaksi belum dianggap berhasil.
         */
        if (!d.ok) {
            busy =
                false;
            if (payBtn) {
                payBtn.disabled =
                    false;
                payBtn.innerHTML =
                    'Bayar <kbd>Enter</kbd>';
                payBtn.style.opacity =
                    '1';
                payBtn.style.pointerEvents =
                    '';
            }
            return toast(
                d.msg ||
                'Transaksi gagal',
                true
            );
        }
        /*
         * ====================================================
         * TRANSAKSI BERHASIL
         * ====================================================
         *
         * Setelah berhasil, kunci permanen
         * sampai halaman transaksi baru dibuka.
         */
        transaksiSukses =
            true;
        /*
         * Simpan mode yang aktif saat transaksi terjadi
         * supaya "Transaksi Baru" bisa kembali ke mode yang sama.
         */
        modeSaatTransaksi = currentMode;
        busy =
            false;
        /*
         * Informasi pembayaran.
         */
        $('dinfo').innerHTML = `
${d.kode}
<br>
Metode:
<b>
${metode.toUpperCase()}
</b>
<br>
${
    Number(d.diskon_nominal) > 0
    ?
    `Subtotal:
<b>${rp(d.subtotal)}</b>
<br>
Diskon ${d.diskon_persen}%:
<b>- ${rp(d.diskon_nominal)}</b>
<br>`
    :
    ''
}
Total:
<b>
${rp(d.total)}
</b>
${
    metode === 'tunai'
    ?
    `
<br>
Kembalian:
<b class="gr">
${rp(d.kembali)}
</b>
`
    :
    ''
}
`;
        /*
         * ====================================================
         * BUAT SATU STRUK
         * ====================================================
         *
         * Semua item dari cart dimasukkan
         * ke SATU struk.
         */
        $('rcp').innerHTML = `
<h3>
${NAMA}
</h3>
<small>
${d.kode}
·
${new Date().toLocaleString('id-ID')}
</small>
<hr>
`
+
lines
.map(
    l => `
<div>
<span>
${esc(l[0])}
x${
    l[3]
        ? Number(l[1]).toFixed(3) + ' kg'
        : l[1]
}
</span>
<span>
${rp(l[2])}
</span>
</div>
`
)
.join('')
+
`
<hr>
${
    Number(d.diskon_nominal) > 0
    ?
    `<div>
Subtotal
<span>${rp(d.subtotal)}</span>
</div>
<div>
Diskon ${d.diskon_persen}%
<span>- ${rp(d.diskon_nominal)}</span>
</div>`
    :
    ''
}
<div>
<b>
Total
</b>
<span>
${rp(d.total)}
</span>
</div>
<div>
${metode.toUpperCase()}
<span>
${
    metode === 'tunai'
        ?
        rp(
            Number(d.total) +
            Number(d.kembali)
        )
        :
        'LUNAS'
}
</span>
</div>
${
    metode === 'tunai'
    ?
    `
<div>
Kembali
<span>
${rp(d.kembali)}
</span>
</div>
`
    :
    ''
}
<hr>
<p>
Terima kasih 🙏
</p>
`;
        /*
         * Tampilkan popup berhasil.
         */
        $('done')
            .classList
            .add(
                'show'
            );
        /*
         * Fokus ke tombol Cetak Struk
         * supaya Enter langsung cetak.
         */
        $('btnCetakStruk').focus();
        /*
         * Tombol BAYAR tetap terkunci.
         */
        if (payBtn) {
            payBtn.disabled =
                true;
            payBtn.innerHTML =
                '✓ Transaksi Berhasil';
            payBtn.style.opacity =
                '0.5';
        }
    } catch(e) {
        /*
         * Kalau koneksi gagal,
         * transaksi boleh dicoba lagi.
         */
        busy =
            false;
        if (payBtn) {
            payBtn.disabled =
                false;
            payBtn.innerHTML =
                'Bayar <kbd>Enter</kbd>';
            payBtn.style.opacity =
                '1';
            payBtn.style.pointerEvents =
                '';
        }
        toast(
            'Gagal terhubung ke server/API',
            true
        );
        console.error(e);
    }
}
/* ============================================================
   BUKA POPUP CLOSING
   ============================================================ */
async function closing()
{
    if (
        cart.size &&
        !confirm(
            'Masih ada pesanan belum dibayar. Lanjut closing?'
        )
    )
        return;
    try {
        const response =
            await fetch(
                'index.php?ringkas=1'
            );
        const r =
            await response.json();
        if (!r.ok) {
            return toast(
                'Gagal mengambil data closing',
                true
            );
        }
        $('cn')
            .textContent =
            r.n;
        closingData =
            r;
        $('pend').value =
            '';
        $('closingInfo').innerHTML = `
<div>
Modal awal:
<b>
${rp(r.modal || 0)}
</b>
</div>
<div>
Penjualan tunai:
<b>
${rp(r.tunai)}
</b>
</div>
<div>
QRIS:
<b>
${rp(r.qris)}
</b>
</div>
<hr>
<div>
Uang fisik seharusnya:
<b>
${rp(r.seharusnya)}
</b>
</div>
`;
        $('selisihConfirm')
            .style.display =
            'none';
        $('btnClosing')
            .style.display =
            'block';
        $('clos')
            .classList
            .add('show');
        $('pend')
            .focus();
    } catch(e) {
        toast(
            'Gagal mengambil data closing',
            true
        );
    }
}
/* ============================================================
   CLOSING PERTAMA
   ============================================================ */
async function doClose(
    lanjut=false
)
{
    const v =
        num(
            $('pend')
        );
    if (v === '') {
        return toast(
            'Isi total uang kas akhir dulu',
            true
        );
    }
    const nominal =
        Number(v);
    try {
        const body =
            new URLSearchParams();
        body.append(
            'aksi',
            'closing'
        );
        body.append(
            'pendapatan',
            nominal
        );
        if (lanjut) {
            body.append(
                'lanjut',
                '1'
            );
        }
        const response =
            await fetch(
                'index.php',
                {
                    method:
                        'POST',
                    headers:{
                        'Content-Type':
                            'application/x-www-form-urlencoded'
                    },
                    body:
                        body.toString()
                }
            );
        const d =
            await response.json();
        if (!d.ok) {
            return toast(
                d.msg ||
                'Closing gagal',
                true
            );
        }
        if (d.confirm) {
            tampilSelisih(d);
            return;
        }
        if (d.closed) {
            location.href =
                'index.php?closed=1';
        }
    } catch(e) {
        console.error(e);
        toast(
            'Gagal memproses closing',
            true
        );
    }
}
/* ============================================================
   TAMPILKAN POPUP SELISIH
   ============================================================ */
function tampilSelisih(d)
{
    const box =
        $('selisihBox');
    const nominal =
        Math.abs(
            Number(d.selisih)
        );
    $('selisihTitle')
        .textContent =
        d.status === 'lebih'
            ? '⚠️ UANG LEBIH'
            : '⚠️ UANG KURANG';
    $('selisihNominal')
        .textContent =
        rp(nominal);
    $('selisihDetail').innerHTML = `
Uang kas akhir:
<b>
${rp(d.input)}
</b>
<br>
Uang seharusnya:
<b>
${rp(d.seharusnya)}
</b>
<br>
Modal:
${rp(d.modal)}
<br>
Penjualan tunai:
${rp(d.tunai)}
<br><br>
${
    d.status === 'lebih'
        ?
        '<b>Uang fisik lebih dari yang seharusnya.</b>'
        :
        '<b>Uang fisik kurang dari yang seharusnya.</b>'
}
`;
    box.classList.toggle(
        'kurang',
        d.status === 'kurang'
    );
    $('selisihConfirm')
        .style.display =
        'block';
    $('btnClosing')
        .style.display =
        'none';
    $('selisihConfirm')
        .scrollIntoView({
            behavior:'smooth',
            block:'center'
        });
}
/* ============================================================
   UBAH NOMINAL
   ============================================================ */
function ubahNominal()
{
    $('selisihConfirm')
        .style.display =
        'none';
    $('btnClosing')
        .style.display =
        'block';
    $('pend')
        .focus();
    $('pend')
        .select();
}
/* ============================================================
   LANJUT CLOSING
   ============================================================ */
function lanjutClosing()
{
    doClose(true);
}
/* ============================================================
   FORMAT RUPIAH INPUT
   ============================================================ */
const rpi =
el => {
    const d =
        el.value
        .replace(
            /\D/g,
            ''
        )
        .slice(
            0,
            12
        );
    el.value =
        d
        ?
        'Rp ' +
        Number(d)
        .toLocaleString(
            'id-ID'
        )
        :
        '';
};
/* ============================================================
   AMBIL ANGKA
   ============================================================ */
const num =
el =>
el.value.replace(
    /\D/g,
    ''
);
/* ============================================================
   SET BAYAR
   ============================================================ */
function setBayar(v)
{
    $('bayar').value =
        String(v);
    rpi(
        $('bayar')
    );
    kem();
    $('bayar').focus();
}
/* ============================================================
   MODAL
   ============================================================ */
$('mval').oninput =
e =>
rpi(e.target);
$('pend').oninput =
e =>
rpi(e.target);
$('mval').onkeydown =
e => {
    if (
        e.key === 'Enter'
    )
        saveModal();
};
$('pend').onkeydown =
e => {
    if (
        e.key === 'Enter'
    ) {
        e.preventDefault();
        doClose(false);
    }
};
/* ============================================================
   SET MODAL CEPAT
   ============================================================ */
function setMv(v)
{
    $('mval').value =
        v;
    rpi(
        $('mval')
    );
}
/* ============================================================
   SIMPAN MODAL
   ============================================================ */
async function saveModal()
{
    const v =
        num(
            $('mval')
        );
    if (v === '') {
        return toast(
            'Isi uang modal dulu',
            true
        );
    }
    const r =
        await fetch(
            'index.php',
            {
                method:
                    'POST',
                headers:{
                    'Content-Type':
                        'application/x-www-form-urlencoded'
                },
                body:
                    'aksi=modal&modal=' +
                    encodeURIComponent(v)
            }
        );
    const d =
        await r.json();
    if (!d.ok) {
        return toast(
            d.msg,
            true
        );
    }
    location.reload();
}
/* ============================================================
   CAMERA
   Kamera dipakai seperti scanner:
   - kamera tetap terbuka, arahkan label satu per satu
   - label dibaca otomatis (tanpa Enter), langsung masuk keranjang
   - label yang sama tidak terhitung dua kali selama masih di depan kamera
   - hasil dibaca 2x berturut-turut dulu supaya tidak salah baca
   ============================================================ */
/* Kamera bawaan aplikasi Android (APK): scan terus-menerus.
   Tekan tombol tutup/kembali di layar kamera untuk berhenti. */
window.onNativeScan = function (raw) {
    processScan(raw);
    setTimeout(function () {
        if (window.AndroidApp && AndroidApp.scan) AndroidApp.scan();
    }, 900);
};
window.onNativeScanEnd = function () {
    $('scan').focus();
};
const CAM = {
    on: false,
    stream: null,
    timer: 0,
    det: null,
    cv: null,
    ctx: null,
    cand: '',
    candAt: 0,
    last: '',
    lastSeen: 0
};
function camStatus(text, kind)
{
    const el = $('camst');
    el.textContent = text;
    el.className = 'camst ' + (kind || '');
}
function camSummary()
{
    $('camtot').textContent =
        cart.size +
        ' item · ' +
        rp(total());
}
function camClose()
{
    CAM.on = false;
    clearTimeout(CAM.timer);
    if (CAM.stream) {
        CAM.stream
            .getTracks()
            .forEach(
                t => t.stop()
            );
    }
    CAM.stream = null;
    const v = $('vid');
    try { v.pause(); } catch (e) {}
    v.srcObject = null;
    $('camw').classList.remove('show');
    $('scan').focus();
}
function camAccept(code)
{
    const now = Date.now();
    /*
     * Label yang sama dan masih terlihat kamera:
     * jangan dihitung lagi. Boleh dihitung lagi setelah
     * label menghilang dari kamera lebih dari 1,5 detik.
     */
    if (
        code === CAM.last &&
        now - CAM.lastSeen < 1500
    ) {
        CAM.lastSeen = now;
        return;
    }
    /*
     * Konfirmasi: kode harus terbaca 2x berturut-turut.
     */
    if (
        code !== CAM.cand ||
        now - CAM.candAt > 900
    ) {
        CAM.cand = code;
        CAM.candAt = now;
        return;
    }
    CAM.cand = '';
    CAM.last = code;
    CAM.lastSeen = now;
    const res = processScan(code);
    camStatus(
        (res.ok ? '✓ ' : '✗ ') + res.text,
        res.ok ? 'ok' : 'err'
    );
    camSummary();
    if (navigator.vibrate) {
        navigator.vibrate(
            res.ok ? 60 : [80, 60, 80]
        );
    }
    const f = $('camf');
    f.className = 'camf ' + (res.ok ? 'ok' : 'err');
    setTimeout(
        () => { f.className = 'camf'; },
        450
    );
}
async function camTick()
{
    if (!CAM.on)
        return;
    const v = $('vid');
    let raw = null;
    if (
        v.readyState >= 2 &&
        v.videoWidth
    ) {
        try {
            /*
             * 1) Pembaca bawaan browser (cepat) bila ada.
             */
            if (CAM.det) {
                const r =
                    await CAM.det.detect(v);
                if (r.length)
                    raw = r[0].rawValue;
            }
            /*
             * 2) Pembaca EAN-13 bawaan aplikasi (assets/ean-scan.js).
             *    Jalan di semua browser.
             */
            if (
                !raw &&
                window.EanScan
            ) {
                const sc =
                    Math.min(
                        1,
                        1280 / v.videoWidth
                    );
                const w =
                    Math.round(
                        v.videoWidth * sc
                    );
                const h =
                    Math.round(
                        v.videoHeight * sc
                    );
                if (!CAM.cv) {
                    CAM.cv =
                        document.createElement(
                            'canvas'
                        );
                    CAM.ctx =
                        CAM.cv.getContext(
                            '2d',
                            { willReadFrequently:true }
                        );
                }
                if (
                    CAM.cv.width !== w ||
                    CAM.cv.height !== h
                ) {
                    CAM.cv.width = w;
                    CAM.cv.height = h;
                }
                CAM.ctx.drawImage(
                    v,
                    0,
                    0,
                    w,
                    h
                );
                raw =
                    EanScan.decodeRGBA(
                        CAM.ctx
                            .getImageData(
                                0,
                                0,
                                w,
                                h
                            ).data,
                        w,
                        h
                    );
            }
        } catch (e) {}
    }
    if (!CAM.on)
        return;
    if (raw) {
        raw =
            String(raw)
            .replace(
                /#/g,
                ''
            )
            .trim();
        if (raw)
            camAccept(raw);
    }
    CAM.timer =
        setTimeout(
            camTick,
            70
        );
}
async function cam()
{
    if (window.AndroidApp && typeof AndroidApp.scan === 'function') {
        AndroidApp.scan();
        return;
    }
    if (CAM.on)
        return;
    if (
        !navigator.mediaDevices ||
        !navigator.mediaDevices.getUserMedia
    ) {
        return toast(
            'Kamera butuh alamat https:// atau http://localhost. ' +
            'Buka aplikasi lewat localhost atau pasang HTTPS.',
            true
        );
    }
    if (
        !('BarcodeDetector' in window) &&
        !window.EanScan
    ) {
        return toast(
            'Pembaca barcode gagal dimuat (assets/ean-scan.js)',
            true
        );
    }
    let s;
    try {
        try {
            s =
                await navigator
                .mediaDevices
                .getUserMedia({
                    video:{
                        facingMode:{
                            ideal:'environment'
                        },
                        width:{ ideal:1280 },
                        height:{ ideal:720 }
                    },
                    audio:false
                });
        } catch (e1) {
            if (e1 && e1.name === 'NotAllowedError')
                throw e1;
            s =
                await navigator
                .mediaDevices
                .getUserMedia({
                    video:true,
                    audio:false
                });
        }
    } catch (e) {
        const n =
            e && e.name;
        return toast(
            n === 'NotAllowedError'
                ? 'Izin kamera ditolak. Izinkan kamera untuk situs ini di pengaturan browser.'
                : n === 'NotFoundError'
                    ? 'Kamera tidak ditemukan di perangkat ini'
                    : n === 'NotReadableError'
                        ? 'Kamera sedang dipakai aplikasi lain'
                        : 'Kamera tidak bisa diakses',
            true
        );
    }
    CAM.stream = s;
    /*
     * Fokus otomatis terus-menerus bila didukung.
     */
    try {
        const tr =
            s.getVideoTracks()[0];
        const cap =
            tr.getCapabilities
                ? tr.getCapabilities()
                : {};
        if (
            cap.focusMode &&
            cap.focusMode.includes('continuous')
        ) {
            await tr.applyConstraints({
                advanced:[
                    { focusMode:'continuous' }
                ]
            });
        }
    } catch (e) {}
    CAM.det = null;
    if ('BarcodeDetector' in window) {
        try {
            const want = [
                'ean_13',
                'ean_8',
                'upc_a',
                'upc_e',
                'code_128',
                'code_39'
            ];
            let fm = want;
            if (BarcodeDetector.getSupportedFormats) {
                const sup =
                    await BarcodeDetector
                    .getSupportedFormats();
                fm =
                    want.filter(
                        f =>
                            sup.includes(f)
                    );
            }
            if (fm.length)
                CAM.det =
                    new BarcodeDetector({
                        formats:fm
                    });
        } catch (e) {
            CAM.det = null;
        }
    }
    const v = $('vid');
    v.srcObject = s;
    try {
        await v.play();
    } catch (e) {}
    CAM.cand = '';
    CAM.last = '';
    CAM.lastSeen = 0;
    camStatus(
        'Arahkan barcode ke garis tengah',
        ''
    );
    camSummary();
    $('camw')
        .classList
        .add('show');
    CAM.on = true;
    camTick();
}
$('camx').onclick = camClose;
/*
 * Tombol Esc menutup kamera; pindah tab/aplikasi
 * juga mematikan kamera supaya tidak menyala terus.
 */
document.addEventListener(
    'keydown',
    e => {
        if (
            e.key === 'Escape' &&
            CAM.on
        )
            camClose();
    }
);
document.addEventListener(
    'visibilitychange',
    () => {
        if (
            document.hidden &&
            CAM.on
        )
            camClose();
    }
);
/* ============================================================
   MODAL AWAL
   ============================================================ */
if (MODAL_KOSONG) {
    $('mdl')
        .classList
        .add('show');
    $('mval')
        .focus();
}
/* ============================================================
   JAM
   ============================================================ */
setInterval(
    () => {
        $('clk')
            .textContent =
            new Date()
            .toLocaleTimeString(
                'id-ID'
            );
    },
    1000
);
/* ============================================================
   START
   ============================================================ */
draw();
/* Restore mode dari sessionStorage (setelah Transaksi Baru) */
(function() {
    const savedMode = sessionStorage.getItem('kasirMode');
    if (savedMode && savedMode !== 'scan') {
        sessionStorage.removeItem('kasirMode');
        setMode(savedMode);
    } else {
        sessionStorage.removeItem('kasirMode');
        $('scan').focus();
    }
})();
document.addEventListener(
    'click',
    e => {
        if (
            !e.target.closest(
                'input,button,.modal,.sug'
            )
        ) {
            /* Fokus ke input yang sesuai mode aktif */
            if (currentMode === 'manual') {
                $('mKode').focus();
            } else {
                $('scan').focus();
            }
        }
    }
);
/* ============================================================
   KEYBOARD
   ============================================================ */
document.addEventListener(
    'keydown',
    e => {
        /* F2 = mode Scan */
        if (e.key === 'F2') {
            e.preventDefault();
            setMode('scan');
        }
        /* Ctrl+M = mode Manual (F3 diambil browser untuk Find) */
        if (e.key === 'm' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            setMode('manual');
        }
        /* F1 = Transaksi Baru (hanya saat popup done terbuka) */
        if (e.key === 'F1') {
            e.preventDefault();
            if ($('done').classList.contains('show')) {
                transaksiBaruDenganMode();
            }
        }
    }
);

/* ============================================================
   MODE: SCAN / MANUAL
   ============================================================ */
let currentMode = 'scan';
let modeSaatTransaksi = 'scan'; /* mode aktif saat transaksi terakhir */

/* ============================================================
   TRANSAKSI BARU – kembali ke mode sebelumnya
   ============================================================ */
function transaksiBaruDenganMode() {
    /*
     * Simpan mode sebelum reload supaya bisa di-restore.
     * Gunakan sessionStorage agar tetap ada setelah reload.
     */
    sessionStorage.setItem('kasirMode', modeSaatTransaksi);
    location.reload();
}

function setMode(m) {
    currentMode = m;
    $('btnModeScan').classList.toggle('active', m === 'scan');
    $('btnModeManual').classList.toggle('active', m === 'manual');
    $('panelScan').style.display = m === 'scan' ? '' : 'none';
    $('panelManual').style.display = m === 'manual' ? '' : 'none';
    if (m === 'scan') {
        $('scan').focus();
    } else {
        manualReset();
        $('mKode').focus();
    }
}

/* ============================================================
   MANUAL – state
   ============================================================ */
let manualProduk = null;

function manualReset() {
    manualProduk = null;
    ['mKode','mNama','mHarga','mSatuan','mQty','mSub'].forEach(id => {
        $( id ).value = '';
    });
    $('mNama').classList.remove('mf-found','mf-notfound');
    $('mKode').classList.remove('mf-found','mf-notfound');
    $('mKodeHint').textContent = '';
    $('mKodeHint').className = 'mf-hint';
    $('lastManual').innerHTML = '<span>Masukkan kode untuk mulai</span>';
    $('mBtnAdd').disabled = true;
}

/* ============================================================
   MANUAL – LOOKUP produk dari data P (sudah ada di client)
   ============================================================ */
function manualLookup() {
    const raw = $('mKode').value.trim();
    if (!raw) {
        manualClear();
        return;
    }
    /* Cari persis kode */
    let p = P.find(x => String(x.kode) === raw);
    /* Cari partial nama jika bukan angka murni */
    if (!p && !/^\d+$/.test(raw)) {
        const matches = P.filter(x =>
            x.nama.toLowerCase().includes(raw.toLowerCase())
        );
        if (matches.length === 1) p = matches[0];
    }
    /* Cari dengan abaikan leading zero */
    if (!p) {
        const norm = raw.replace(/^0+/, '');
        if (norm) {
            p = P.find(x =>
                String(x.kode).replace(/^0+/, '') === norm
            );
        }
    }
    if (p) {
        manualSetProduk(p);
    } else {
        manualClear();
        $('mKode').classList.add('mf-notfound');
        $('mKodeHint').textContent = '✗ Produk tidak ditemukan';
        $('mKodeHint').className = 'mf-hint err';
    }
}

function manualClear() {
    manualProduk = null;
    $('mNama').value = '';
    $('mHarga').value = '';
    $('mSatuan').value = '';
    $('mSub').value = '';
    $('mNama').classList.remove('mf-found','mf-notfound');
    $('mKode').classList.remove('mf-found','mf-notfound');
    $('mKodeHint').textContent = '';
    $('mKodeHint').className = 'mf-hint';
    $('mBtnAdd').disabled = true;
}

function manualSetProduk(p) {
    manualProduk = p;
    $('mKode').value = p.kode;
    $('mNama').value = p.nama;
    $('mHarga').value = 'Rp ' + Number(p.harga).toLocaleString('id-ID') + ' / ' + p.satuan;
    $('mSatuan').value = p.satuan;
    $('mKode').classList.remove('mf-notfound');
    $('mKode').classList.add('mf-found');
    $('mNama').classList.remove('mf-notfound');
    $('mNama').classList.add('mf-found');
    $('mKodeHint').textContent = '✓ ' + p.nama + ' · Stok: ' + Number(p.stok).toLocaleString('id-ID') + ' ' + p.satuan;
    $('mKodeHint').className = 'mf-hint ok';
    /* Default qty = 1 jika kosong */
    if (!$('mQty').value || Number($('mQty').value) <= 0) {
        $('mQty').value = p.satuan.toLowerCase().includes('kg') ? '' : '1';
    }
    manualCalcSub();
    $('mBtnAdd').disabled = false;
    /* Fokus ke qty */
    setTimeout(() => $('mQty').focus(), 60);
}

/* ============================================================
   MANUAL – hitung subtotal otomatis saat qty berubah
   ============================================================ */
function manualCalcSub() {
    if (!manualProduk) {
        $('mSub').value = '';
        return;
    }
    const qty = parseFloat($('mQty').value);
    if (!qty || qty <= 0) {
        $('mSub').value = '';
        return;
    }
    const sub = Math.round(Number(manualProduk.harga) * qty);
    $('mSub').value = 'Rp ' + sub.toLocaleString('id-ID');
}

/* ============================================================
   MANUAL – tambah ke keranjang
   ============================================================ */
function manualAdd() {
    if (!manualProduk) {
        toast('Masukkan kode produk terlebih dahulu', true);
        $('mKode').focus();
        return;
    }
    const qtyRaw = parseFloat($('mQty').value);
    if (!qtyRaw || qtyRaw <= 0 || !Number.isFinite(qtyRaw)) {
        toast('Isi qty dengan benar', true);
        $('mQty').focus();
        return;
    }
    /* Tentukan apakah produk timbang */
    const isWeight = manualProduk.satuan.toLowerCase().includes('kg');
    const p = manualProduk;
    /* Gunakan fungsi add() yang sama dengan scan */
    add(p.id, qtyRaw, isWeight);
    /* Feedback */
    const qty = isWeight
        ? qtyRaw.toFixed(3) + ' kg'
        : String(Math.floor(qtyRaw));
    const sub = Math.round(Number(p.harga) * qtyRaw);
    $('lastManual').innerHTML = `
<span>✓ ${esc(p.nama)} &times; ${qty}</span>
<b>${rp(sub)}</b>
`;
    /* Reset form untuk input berikutnya */
    $('mKode').value = '';
    $('mNama').value = '';
    $('mHarga').value = '';
    $('mSatuan').value = '';
    $('mQty').value = '';
    $('mSub').value = '';
    $('mNama').classList.remove('mf-found','mf-notfound');
    $('mKode').classList.remove('mf-found','mf-notfound');
    $('mKodeHint').textContent = '';
    $('mKodeHint').className = 'mf-hint';
    manualProduk = null;
    $('mBtnAdd').disabled = true;
    $('mKode').focus();
}

/* ============================================================
   EVENT LISTENERS – manual form
   ============================================================ */
/* Enter di kode: lookup lalu fokus ke qty */
$('mKode').addEventListener('keydown', e => {
    if (e.key === 'Enter') {
        e.preventDefault();
        manualLookup();
    }
});
/* Oninput kode: real-time lookup saat ada kode */
$('mKode').addEventListener('input', () => {
    const v = $('mKode').value.trim();
    if (!v) { manualClear(); return; }
    /* Jika angka murni, tunggu Enter supaya tidak salah PLU */
    if (/^\d+$/.test(v) && v.length < 4) return;
    /* Cari nama secara real-time untuk nama buah */
    if (!/^\d+$/.test(v)) {
        const matches = P.filter(x =>
            x.nama.toLowerCase().includes(v.toLowerCase())
        );
        if (matches.length === 1) {
            manualSetProduk(matches[0]);
            return;
        }
        manualClear();
        $('mKodeHint').textContent = matches.length > 1
            ? matches.length + ' produk ditemukan, ketik lebih spesifik'
            : '';
        $('mKodeHint').className = 'mf-hint';
    }
});
/* Qty: recalc subtotal setiap kali berubah */
$('mQty').addEventListener('input', manualCalcSub);
/* Enter di qty: langsung tambah */
$('mQty').addEventListener('keydown', e => {
    if (e.key === 'Enter') {
        e.preventDefault();
        manualAdd();
    }
});
