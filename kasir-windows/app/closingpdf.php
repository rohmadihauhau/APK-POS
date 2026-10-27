<?php
require 'config.php';
need('admin');
$id=(int)($_GET['id'] ?? 0);
if($id<=0){ http_response_code(400); die('ID closing tidak valid'); }
$c=$pdo->prepare('SELECT status FROM shift WHERE id=?'); $c->execute([$id]);
if($c->fetchColumn()!=='tutup'){ http_response_code(404); die('Shift belum ditutup / tidak ditemukan'); }
try{ simpan_pdf($pdo,$id); }catch(Throwable $e){ http_response_code(500); die('Gagal membuat PDF: '.h($e->getMessage())); }
$f=__DIR__.'/laporan-closing/closing-'.$id.'.pdf';
if(!is_file($f)){ http_response_code(404); die('File PDF tidak ditemukan'); }
header_remove('Content-Security-Policy');
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="closing-'.$id.'.pdf"');
header('Content-Length: '.filesize($f));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
readfile($f); exit;
