/* ==========================================================
   KODE ACAK
   ========================================================== */
function buatKode() {
    let n =
        Math.floor(
            100000 +
            Math.random() * 900000
        );
    document.getElementById(
        'kode'
    ).value =
        String(n);
}
/* ==========================================================
   VALIDASI FORM PRODUK
   ========================================================== */
const frmProduk =
    document.getElementById(
        'frmProduk'
    );
if (frmProduk) {
    frmProduk.addEventListener(
        'submit',
        function(e) {
            const kode =
                this.querySelector(
                    '[name="kode"]'
                ).value.trim();
            const nama =
                this.querySelector(
                    '[name="nama"]'
                ).value.trim();
            const hargaModal =
                Number(
                    this.querySelector(
                        '[name="harga_modal"]'
                    ).value
                );
            const harga =
                Number(
                    this.querySelector(
                        '[name="harga"]'
                    ).value
                );
            const stokInput =
                this.querySelector(
                    '[name="stok"]'
                );
            const stok =
                Number(
                    stokInput.value
                );
            /* KODE */
            if (
                !/^\d{1,30}$/.test(kode)
            ) {
                e.preventDefault();
                alert(
                    'Kode produk harus berupa angka maksimal 30 digit.'
                );
                return;
            }
            /* NAMA */
            if (!nama) {
                e.preventDefault();
                alert(
                    'Nama produk wajib diisi.'
                );
                return;
            }
            /* HARGA MODAL */
            if (
                !Number.isFinite(hargaModal)
                ||
                hargaModal < 0
            ) {
                e.preventDefault();
                alert(
                    'Harga modal tidak valid.'
                );
                return;
            }
            /* HARGA JUAL */
            if (
                !Number.isFinite(harga)
                ||
                harga < 0
            ) {
                e.preventDefault();
                alert(
                    'Harga jual tidak valid.'
                );
                return;
            }
            /* STOK */
            if (
                !Number.isFinite(stok)
                ||
                stok < 0
            ) {
                e.preventDefault();
                alert(
                    'Stok tidak valid.'
                );
                return;
            }
            /*
             * Format harga menjadi 2 desimal.
             */
            this.querySelector(
                '[name="harga_modal"]'
            ).value =
                hargaModal.toFixed(2);
            this.querySelector(
                '[name="harga"]'
            ).value =
                harga.toFixed(2);
            /*
             * Format stok menjadi 3 desimal
             * hanya saat produk baru.
             */
            if (!stokInput.readOnly) {
                stokInput.value =
                    stok.toFixed(3);
            }
        }
    );
}
