/*
 * ean-scan.js
 *
 * Pembaca barcode EAN-13 murni JavaScript (tanpa library / tanpa internet).
 * Dipakai sebagai cadangan kamera bila browser tidak punya BarcodeDetector
 * (Firefox, Chrome desktop Windows/Linux, WebView lama, dsb).
 *
 * Pemakaian:
 *   EanScan.decodeRGBA(imageData.data, width, height)  ->  '0555001005245' | null
 *   EanScan.isValid13('0555001005245')                 ->  true
 *   EanScan.parseTimbangan('0555001005245')            ->  {plu:'0555001', gram:524, kg:0.524} | null
 */
(function (root) {
    'use strict';
    var L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
    var G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
    var R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];
    var PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];
    function toRuns(bits) {
        var out = [], n = 1, i;
        for (i = 1; i < bits.length; i++) {
            if (bits.charAt(i) === bits.charAt(i - 1)) n++;
            else { out.push(n); n = 1; }
        }
        out.push(n);
        return out;
    }
    var LEFT = [], RIGHT = [];
    (function () {
        for (var d = 0; d < 10; d++) {
            LEFT.push({ d: d, t: 'L', w: toRuns(L[d]) });
            LEFT.push({ d: d, t: 'G', w: toRuns(G[d]) });
            RIGHT.push({ d: d, t: 'R', w: toRuns(R[d]) });
        }
    })();
    /* ---------- check digit ---------- */
    function checkDigit12(s) {
        var sum = 0, i;
        for (i = 0; i < 12; i++) {
            var n = s.charCodeAt(i) - 48;
            sum += (i % 2 === 0) ? n : n * 3;
        }
        return (10 - (sum % 10)) % 10;
    }
    function isValid13(s) {
        s = String(s);
        return /^\d{13}$/.test(s) && checkDigit12(s) === (s.charCodeAt(12) - 48);
    }
    /*
     * Barcode timbangan toko:
     *   13 digit = [7 digit PLU][5 digit berat dalam gram][1 digit check]
     *   contoh   : 0555001 00524 5  ->  PLU 0555001, 0,524 kg
     */
    function parseTimbangan(raw) {
        var v = String(raw).replace(/\D/g, '');
        if (!isValid13(v)) return null;
        var gram = parseInt(v.substring(7, 12), 10);
        if (!isFinite(gram) || gram <= 0) return null;
        return { barcode: v, plu: v.substring(0, 7), gram: gram, kg: gram / 1000 };
    }
    /* ---------- 1 baris piksel -> run-length ---------- */
    function lineToRuns(lum, n) {
        var sm = new Float32Array(n), i;
        for (i = 0; i < n; i++) {
            var a = lum[i > 0 ? i - 1 : 0], b = lum[i], c = lum[i < n - 1 ? i + 1 : n - 1];
            sm[i] = (a + 2 * b + c) / 4;
        }
        /* ambang lokal = titik tengah (min+max) di sekitar piksel.
           Dihitung per blok 8 piksel supaya cepat. */
        var BL = 8, nb = Math.ceil(n / BL);
        var bmin = new Float32Array(nb), bmax = new Float32Array(nb), k;
        for (k = 0; k < nb; k++) {
            var mn = 255, mx = 0, e = Math.min(n, (k + 1) * BL), q;
            for (q = k * BL; q < e; q++) {
                if (sm[q] < mn) mn = sm[q];
                if (sm[q] > mx) mx = sm[q];
            }
            bmin[k] = mn; bmax[k] = mx;
        }
        var hb = Math.max(2, Math.floor(n / 9 / BL));
        var runs = [], cur = -1, len = 0;
        for (i = 0; i < n; i++) {
            var kb = (i / BL) | 0;
            var lo = kb - hb < 0 ? 0 : kb - hb;
            var hi = kb + hb >= nb ? nb - 1 : kb + hb;
            var wmin = 255, wmax = 0;
            for (k = lo; k <= hi; k++) {
                if (bmin[k] < wmin) wmin = bmin[k];
                if (bmax[k] > wmax) wmax = bmax[k];
            }
            var black = (wmax - wmin >= 40 && sm[i] < (wmin + wmax) / 2) ? 1 : 0;
            if (cur === -1) {
                if (!black) continue;     /* mulai dari bar hitam pertama */
                cur = 1; len = 1;
            } else if (black === cur) {
                len++;
            } else {
                runs.push(len);
                cur = black; len = 1;
            }
        }
        if (cur !== -1) runs.push(len);
        return runs;
    }
    /* ---------- cocokkan 4 run ke pola digit ---------- */
    function matchDigit(r, a, table) {
        var sum = a[r] + a[r + 1] + a[r + 2] + a[r + 3];
        if (sum <= 0) return null;
        var k = 7 / sum, n0 = a[r] * k, n1 = a[r + 1] * k, n2 = a[r + 2] * k, n3 = a[r + 3] * k;
        var best = null, bestE = 1e9, second = 1e9, i;
        for (i = 0; i < table.length; i++) {
            var w = table[i].w;
            var e = Math.abs(n0 - w[0]) + Math.abs(n1 - w[1]) + Math.abs(n2 - w[2]) + Math.abs(n3 - w[3]);
            if (e < bestE) { second = bestE; bestE = e; best = table[i]; }
            else if (e < second) second = e;
        }
        if (bestE > 2.0 || second - bestE < 0.12) return null;
        return best;
    }
    function decodeRuns(runs) {
        var n = runs.length, i;
        for (i = 0; i + 58 < n; i += 2) {       /* i genap = bar hitam */
            var g0 = runs[i], g1 = runs[i + 1], g2 = runs[i + 2];
            var mx = Math.max(g0, g1, g2), mn = Math.min(g0, g1, g2);
            if (mn < 1 || mx > mn * 3) continue;
            /* koreksi blur: bar melebar / spasi menyempit (atau sebaliknya) */
            var bS = ((runs[i] + runs[i + 2]) / 2 - runs[i + 1]) / 2;
            var bC = ((runs[i + 28] + runs[i + 30]) / 2 - (runs[i + 27] + runs[i + 29] + runs[i + 31]) / 3) / 2;
            var bE = ((runs[i + 56] + runs[i + 58]) / 2 - runs[i + 57]) / 2;
            var beta = (bS + bC + bE) / 3;
            var total = 0, j;
            for (j = 0; j < 59; j++) total += runs[i + j];
            var u = total / 95;
            if (u < 0.9) continue;
            var a = new Array(59);
            for (j = 0; j < 59; j++) {
                var w = runs[i + j] - ((j % 2 === 0) ? beta : -beta);   /* genap = bar */
                a[j] = w < 0.2 * u ? 0.2 * u : w;
            }
            var okG = true, gi = [0, 1, 2, 27, 28, 29, 30, 31, 56, 57, 58];
            for (j = 0; j < gi.length; j++) {
                var gw = a[gi[j]];
                if (gw < 0.5 * u || gw > 1.7 * u) { okG = false; break; }
            }
            if (!okG) continue;
            var digits = [], par = '', d, m;
            for (d = 0; d < 6; d++) {
                m = matchDigit(3 + d * 4, a, LEFT);
                if (!m) break;
                digits.push(m.d); par += m.t;
            }
            if (digits.length !== 6) continue;
            var first = PARITY.indexOf(par);
            if (first < 0) continue;
            for (d = 0; d < 6; d++) {
                m = matchDigit(32 + d * 4, a, RIGHT);
                if (!m) break;
                digits.push(m.d);
            }
            if (digits.length !== 12) continue;
            var s = String(first) + digits.join('');
            if (isValid13(s)) return s;
        }
        return null;
    }
    /* perbesar 2x (interpolasi linear) supaya lebar bar kecil lebih presisi */
    function upsample2(lum, n) {
        var out = new Float32Array(n * 2), i;
        for (i = 0; i < n - 1; i++) {
            out[2 * i] = lum[i];
            out[2 * i + 1] = (lum[i] + lum[i + 1]) / 2;
        }
        out[2 * n - 2] = out[2 * n - 1] = lum[n - 1];
        return out;
    }
    function decodeLine(lum, n) {
        if (n < 700) { lum = upsample2(lum, n); n *= 2; }
        var runs = lineToRuns(lum, n);
        if (runs.length < 59) return null;
        var r = decodeRuns(runs);
        if (r) return r;
        /* barcode terbalik: balik urutan, buang run putih di depan */
        var rev = runs.slice().reverse();
        if ((runs.length - 1) % 2 === 1) rev.shift();
        return decodeRuns(rev);
    }
    /* ---------- seluruh gambar: banyak garis & sudut ---------- */
    var ANGLES = [0, 4, -4, 8, -8, 14, -14, 22, -22, 90, 86, 94, 82, 98];
    var OFFS = [0, 0.1, -0.1, 0.2, -0.2, 0.3, -0.3, 0.38, -0.38];
    function decodeRGBA(data, w, h) {
        var ai, oi, t;
        var buf = new Float32Array(Math.ceil(Math.sqrt(w * w + h * h)) + 4);
        var cx = (w - 1) / 2, cy = (h - 1) / 2;
        for (ai = 0; ai < ANGLES.length; ai++) {
            var th = ANGLES[ai] * Math.PI / 180;
            var dx = Math.cos(th), dy = Math.sin(th);
            var px = -dy, py = dx;                              /* arah tegak lurus */
            var span = Math.abs(px) * w + Math.abs(py) * h;     /* rentang offset */
            for (oi = 0; oi < OFFS.length; oi++) {
                var ox = cx + px * OFFS[oi] * span;
                var oy = cy + py * OFFS[oi] * span;
                var n = 0, started = false;
                var T = Math.ceil(Math.sqrt(w * w + h * h) / 2) + 2;
                for (t = -T; t <= T; t++) {
                    var x = Math.round(ox + dx * t), y = Math.round(oy + dy * t);
                    if (x < 0 || y < 0 || x >= w || y >= h) {
                        if (started) break;
                        continue;
                    }
                    started = true;
                    var p = (y * w + x) * 4;
                    buf[n++] = (data[p] * 77 + data[p + 1] * 150 + data[p + 2] * 29) / 256;
                }
                if (n < 120) continue;
                var res = decodeLine(buf, n);
                if (res) return res;
            }
        }
        return null;
    }
    var api = {
        decodeRGBA: decodeRGBA,
        decodeLine: decodeLine,
        isValid13: isValid13,
        checkDigit12: checkDigit12,
        parseTimbangan: parseTimbangan
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.EanScan = api;
})(typeof window !== 'undefined' ? window : globalThis);
