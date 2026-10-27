<?php
/* ============================================================
   PEMBUAT PDF SEDERHANA (tanpa library, jalan offline di XAMPP)
   $opt: landscape (bool), size (pt font), lead (pt jarak baris),
         per (baris/halaman), footer (bool: nomor halaman)
   ============================================================ */
function pdf_halaman($pages,$opt=[]){
  if(!$pages) $pages=[['']];
  $land=!empty($opt['landscape']); $fs=$opt['size']??9; $ld=$opt['lead']??12;
  $W=$land?842:595; $H=$land?595:842; $mx=$land?30:40; $y=$H-($land?30:42); $n=count($pages); $o=[];
  $o[1]='<</Type/Catalog/Pages 2 0 R>>';
  $kids=''; foreach($pages as $i=>$_) $kids.=(4+$i*2).' 0 R ';
  $o[2]='<</Type/Pages/Kids['.$kids.']/Count '.$n.'>>';
  $o[3]='<</Type/Font/Subtype/Type1/BaseFont/Courier>>';
  foreach($pages as $i=>$pl){
    $s="BT /F1 $fs Tf $mx $y Td $ld TL\n";
    foreach($pl as $l){ $l=preg_replace('/[^\x20-\x7E]/','?',(string)$l); $s.='('.strtr($l,['\\'=>'\\\\','('=>'\\(',')'=>'\\)']).") Tj T*\n"; }
    $s.="ET\n";
    if(!empty($opt['footer'])) $s.="BT /F1 8 Tf $mx 18 Td (Halaman ".($i+1)." dari $n) Tj ET\n";
    $o[4+$i*2]='<</Type/Page/Parent 2 0 R/MediaBox[0 0 '.$W.' '.$H.']/Resources<</Font<</F1 3 0 R>>>>/Contents '.(5+$i*2).' 0 R>>';
    $o[5+$i*2]='<</Length '.strlen($s).">>\nstream\n".$s."endstream";
  }
  $out="%PDF-1.4\n"; $off=[];
  foreach($o as $k=>$v){ $off[$k]=strlen($out); $out.=$k." 0 obj\n".$v."\nendobj\n"; }
  $x=strlen($out); $out.="xref\n0 ".(count($o)+1)."\n0000000000 65535 f \n";
  foreach($o as $k=>$v) $out.=sprintf("%010d 00000 n \n",$off[$k]);
  return $out."trailer\n<</Size ".(count($o)+1)."/Root 1 0 R>>\nstartxref\n$x\n%%EOF";
}
function pdf_buat($lines,$opt=[]){
  return pdf_halaman(array_chunk($lines,$opt['per']??60),$opt);
}
function pdf_format_qty($qty){
  $qty=(float)$qty;
  if(abs($qty-round($qty))<0.000001) return number_format($qty,0,',','.');
  return number_format($qty,3,',','.');
}
function pdf_closing($pdo,$sid){
  $s=$pdo->prepare("SELECT s.*,u.nama FROM shift s JOIN users u ON u.id=s.user_id WHERE s.id=?"); $s->execute([$sid]); $s=$s->fetch();
  if(!$s) return pdf_buat(['SHIFT TIDAK DITEMUKAN']);
  $tr=$pdo->prepare("SELECT * FROM transaksi WHERE shift_id=? ORDER BY id"); $tr->execute([$sid]); $tr=$tr->fetchAll();
  $dt=$pdo->prepare("SELECT * FROM detail WHERE transaksi_id=? ORDER BY id");
  $modal=$s['modal']===null ? 0 : (float)$s['modal']; $tunai=(float)$s['total_tunai']; $qris=(float)$s['total_qris']; $seharusnya=$modal+$tunai;
  $input=$s['input_pendapatan']===null ? null : (float)$s['input_pendapatan'];
  $sel=$input===null ? null : $input-$seharusnya; $ln=str_repeat('-',72);
  $totDiskon=0; $totBruto=0; foreach($tr as $t0){ $totDiskon+=(float)($t0['diskon_nominal']??0); $totBruto+=((float)($t0['subtotal']??0)>0 ? (float)$t0['subtotal'] : (float)$t0['total']); }
  $L=[NAMA_TOKO.' - LAPORAN CLOSING KASIR',str_repeat('=',72),'Shift             : #'.$s['id'],'Kasir             : '.$s['nama'],'Buka              : '.$s['buka'],'Tutup             : '.($s['tutup']?:'-'),$ln,'RINGKASAN','Uang Modal        : '.rp($modal),'Total Tunai       : '.rp($tunai),'Total QRIS        : '.rp($qris),'Kas Seharusnya    : '.rp($seharusnya),'Jumlah transaksi  : '.$s['jml'],'Penjualan Kotor   : '.rp($totBruto),'Total Diskon      : '.rp($totDiskon)];
  if($input===null){ $L[]='Uang Kas Akhir    : -'; $L[]='Selisih           : -'; $L[]='Status            : DITUTUP PAKSA ADMIN'; }
  else { $L[]='Uang Kas Akhir    : '.rp($input); if(abs($sel)<0.000001){$L[]='Selisih           : Rp 0';$L[]='Status            : SESUAI';} elseif($sel>0){$L[]='Selisih           : +'.rp($sel);$L[]='Status            : LEBIH';$L[]='Nominal Lebih     : '.rp($sel);} else {$k=abs($sel);$L[]='Selisih           : -'.rp($k);$L[]='Status            : KURANG';$L[]='Nominal Kurang    : '.rp($k);} }
  $L[]=$ln; $L[]='DETAIL TRANSAKSI'; $L[]='';
  foreach($tr as $t){ $L[]=sprintf('%s  %s  %-5s  Total %s',$t['kode'],date('H:i',strtotime($t['created_at'])),strtoupper($t['metode']),rp($t['total'])); $dt->execute([$t['id']]); foreach($dt->fetchAll() as $d){$qty=pdf_format_qty($d['qty']);$nama=substr($d['nama'],0,26);$L[]=sprintf('   %-26s %8s x %-12s = %s',$nama,$qty,rp($d['harga']),rp($d['subtotal']));}
    if((float)($t['diskon_nominal']??0)>0){ $L[]=sprintf('   %-26s %s','Subtotal',rp($t['subtotal'])); $L[]=sprintf('   %-26s %s','Diskon '.((float)$t['diskon_persen']+0).'%','-'.rp($t['diskon_nominal'])); $L[]=sprintf('   %-26s %s','Total Bayar',rp($t['total'])); } }
  if(!$tr)$L[]='(tidak ada transaksi)';
  return pdf_buat($L);
}
