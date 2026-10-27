/* ==========================================================
   EXPORT PDF - memakai isian filter SAAT INI (tanpa harus Tampilkan dulu)
   ========================================================== */
document.getElementById('btnExportPdf').addEventListener('click', function () {
    const f = document.getElementById('formFilter');
    if (!f.reportValidity()) return;
    const p = new URLSearchParams(new FormData(f));
    window.open('mutasi_stok_pdf.php?' + p.toString(), '_blank');
});
/* ==========================================================
   FORM STOK MASUK / KELUAR
   ========================================================== */
const produkSelect = document.getElementById('produk_id');
const jenisSelect  = document.getElementById('jenis');
const qtyInput     = document.getElementById('qty');
const infoStok     = document.getElementById('infoStok');
function updateInfoStok() {
    const option = produkSelect.options[produkSelect.selectedIndex];
    if (!option || !option.value) { infoStok.style.display = 'none'; return; }
    const stok   = Number(option.dataset.stok || 0);
    const satuan = option.dataset.satuan || '';
    infoStok.style.display = 'block';
    infoStok.innerHTML = 'Stok saat ini: <b>' + stok.toFixed(3) + ' ' + String(satuan).replace(/[&<>"']/g, '') + '</b>' +
        (jenisSelect.value === 'keluar'
            ? '<br>Pastikan jumlah stok keluar tidak melebihi stok tersedia.' : '');
}
produkSelect.addEventListener('change', updateInfoStok);
jenisSelect.addEventListener('change', updateInfoStok);
document.getElementById('formStok').addEventListener('submit', function (e) {
    const option = produkSelect.options[produkSelect.selectedIndex];
    if (!option || !option.value) {
        e.preventDefault(); alert('Silakan pilih produk terlebih dahulu.'); return;
    }
    const qty = Number(qtyInput.value);
    if (!Number.isFinite(qty) || qty <= 0) {
        e.preventDefault(); alert('Jumlah stok harus lebih dari 0.'); return;
    }
    qtyInput.value = qty.toFixed(3);
    if (jenisSelect.value === 'keluar') {
        const stok = Number(option.dataset.stok || 0);
        if (qty > stok) {
            e.preventDefault();
            alert('Stok tidak mencukupi.\n\nStok tersedia: ' + stok.toFixed(3) +
                  '\nStok keluar: ' + qty.toFixed(3));
        }
    }
});
updateInfoStok();
