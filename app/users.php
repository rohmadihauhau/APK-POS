<?php require 'config.php'; need('admin');
$me = $_SESSION['u']['id']; $msg = '';
$e = ['id'=>'','nama'=>'','username'=>'','role'=>'karyawan'];

if(isset($_GET['del'])){
  if(hash_equals(security_csrf_token(), (string)($_GET['t'] ?? '')) && (int)$_GET['del'] !== (int)$me) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$_GET['del']]);
  header('Location: users.php'); exit;
}
if($_SERVER['REQUEST_METHOD']=='POST'){
  $id=(int)$_POST['id']; $nama=trim($_POST['nama']); $un=trim($_POST['username']); $pw=$_POST['password']; $pin=trim($_POST['pin'] ?? '');
  $role = in_array($_POST['role'],['admin','karyawan','kanan_admin'], true) ? $_POST['role'] : 'karyawan';
  if($id === (int)$me) $role = 'admin'; // admin tidak bisa menurunkan role dirinya sendiri
  $e = ['id'=>$id?:'','nama'=>$nama,'username'=>$un,'role'=>$role];
  try{
    if($pin!=='' && !preg_match('/^\d{6}$/',$pin)) throw new Exception('PIN harus 6 digit angka');
    if(!$id && strlen($pw)<6) throw new Exception('Password minimal 6 karakter');
    if($id && $pw!=='' && strlen($pw)<6) throw new Exception('Password baru minimal 6 karakter');
    if($id){
      $pdo->prepare('UPDATE users SET nama=?,username=?,role=? WHERE id=?')->execute([$nama,$un,$role,$id]);
      if($pw!=='') $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($pw,PASSWORD_DEFAULT),$id]);
      if($pin!=='') $pdo->prepare('UPDATE users SET pin_hash=? WHERE id=?')->execute([password_hash($pin,PASSWORD_DEFAULT),$id]);
      if(isset($_POST['reset_pin'])) $pdo->prepare('UPDATE users SET pin_hash=NULL WHERE id=?')->execute([$id]);
      if($id===(int)$me) $_SESSION['u']['nama']=$nama;
    } else {
      $pdo->prepare('INSERT INTO users(nama,username,password,role) VALUES(?,?,?,?)')->execute([$nama,$un,password_hash($pw,PASSWORD_DEFAULT),$role]);
      if($pin!=='') $pdo->prepare('UPDATE users SET pin_hash=? WHERE id=?')->execute([password_hash($pin,PASSWORD_DEFAULT),$pdo->lastInsertId()]);
    }
    header('Location: users.php?ok=1'); exit;
  } catch(PDOException $x){ $msg = $x->getCode()==23000 ? 'Username sudah dipakai, pilih yang lain.' : 'Gagal menyimpan data.'; }
    catch(Exception $x){ $msg = $x->getMessage(); }
} elseif(isset($_GET['edit'])){
  $s=$pdo->prepare('SELECT id,nama,username,role FROM users WHERE id=?'); $s->execute([$_GET['edit']]); $e=$s->fetch() ?: $e;
}
include 'header.php';
$list = $pdo->query('SELECT * FROM users ORDER BY role, nama')->fetchAll(); ?>
<div class="panel"><h3><?= $e['id']?'Edit':'Tambah' ?> User</h3>
<?php if($msg): ?><div class="alert" style="margin:10px 0"><?= h($msg) ?></div><?php endif; ?>
<?php if(isset($_GET['ok'])): ?><div class="alert okmsg" style="margin:10px 0">✅ Data user berhasil disimpan.</div><?php endif; ?>
<form method="post" class="frm"><input type="hidden" name="id" value="<?= $e['id'] ?>">
<input name="nama" value="<?= h($e['nama']) ?>" placeholder="Nama lengkap" required>
<input name="username" value="<?= h($e['username']) ?>" placeholder="Username" required>
<input name="password" type="password" placeholder="<?= $e['id']?'Password baru (kosongkan jika tidak diganti)':'Password (min. 6 karakter)' ?>" <?= $e['id']?'':'required' ?>>
<input name="pin" type="password" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" placeholder="<?= $e['id']?'PIN baru 6 digit (kosongkan jika tidak diganti)':'PIN 6 digit (admin / kanan admin, boleh kosong)' ?>">
<?php if($e['id']): ?><label style="font-size:.8rem;display:flex;gap:6px;align-items:center"><input type="checkbox" name="reset_pin" value="1" style="min-width:0;flex:none"> Reset PIN (dibuat ulang saat login)</label><?php endif; ?>
<select name="role" <?= $e['id']==$me?'disabled title="Role akun sendiri tidak bisa diubah"':'' ?>>
<option value="karyawan" <?= $e['role']=='karyawan'?'selected':'' ?>>Karyawan</option>
<option value="admin" <?= $e['role']=='admin'?'selected':'' ?>>Admin</option>
<option value="kanan_admin" <?= $e['role']=='kanan_admin'?'selected':'' ?>>Kanan Admin</option></select>
<button class="btn">Simpan</button><?php if($e['id']): ?> <a href="users.php" class="btn ghost" style="text-decoration:none">Batal</a><?php endif; ?></form></div>
<div class="panel"><h3>Daftar User</h3><div class="tw"><table>
<tr><th>Nama</th><th>Username</th><th>Role</th><th>Aksi</th></tr>
<?php foreach($list as $u): ?><tr><td><?= h($u['nama']) ?><?= $u['id']==$me?' <small>(Anda)</small>':'' ?></td><td><?= h($u['username']) ?></td>
<td><span class="tag <?= $u['role']=='admin'?'adm':($u['role']=='kanan_admin'?'adm':'') ?>"><?= $u['role'] === 'kanan_admin' ? 'Kanan Admin' : ucfirst($u['role']) ?></span></td>
<td><a href="?edit=<?= $u['id'] ?>">Edit</a><?php if($u['id']!=$me): ?> · <a href="?del=<?= $u['id'] ?>&amp;t=<?= security_csrf_token() ?>" class="red" onclick="return confirm('Hapus user ini?')">Hapus</a><?php endif; ?></td></tr>
<?php endforeach; ?></table></div></div>
<?php include 'footer.php'; ?>
