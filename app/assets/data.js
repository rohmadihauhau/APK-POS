function cetakData() {
    const awal = document.querySelector('input[name="tanggal_awal"]').value;
    const akhir = document.querySelector('input[name="tanggal_akhir"]').value;
    const url =
        'cetak_data.php?tanggal_awal=' +
        encodeURIComponent(awal) +
        '&tanggal_akhir=' +
        encodeURIComponent(akhir) +
        '&tampil=' +
        encodeURIComponent(TAMPIL);
    window.open(url, '_blank');
}
