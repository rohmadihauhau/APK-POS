/* Hanya angka pada kolom PIN; PIN saat login otomatis terkirim setelah 6 digit */
(function(){
  var p=document.getElementById('pin'); if(!p) return;
  var mode=LOGIN_MODE;
  p.addEventListener('input',function(){
    p.value=p.value.replace(/\D/g,'').slice(0,6);
    if(mode==='pin' && p.value.length===6) p.form.submit();
    if(mode==='buatpin' && p.value.length===6) document.getElementById('pin2').focus();
  });
  var p2=document.getElementById('pin2');
  if(p2) p2.addEventListener('input',function(){p2.value=p2.value.replace(/\D/g,'').slice(0,6);});
})();
/* ===== Foto kamera depan saat login gagal ===== */
async function ambilFotoDepan(){
  var s=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:640},height:{ideal:480}},audio:false});
  try{
    var v=document.createElement('video'); v.muted=true; v.playsInline=true; v.srcObject=s;
    await v.play();
    await new Promise(function(r){setTimeout(r,400)});   /* beri waktu kamera menyesuaikan cahaya */
    var c=document.createElement('canvas'); c.width=v.videoWidth||640; c.height=v.videoHeight||480;
    c.getContext('2d').drawImage(v,0,0,c.width,c.height);
    return c.toDataURL('image/jpeg',0.7);
  } finally { s.getTracks().forEach(function(t){t.stop()}); }
}
var frm=document.querySelector('form.login-card');
function kunci(on){ if(!frm) return; Array.prototype.forEach.call(frm.querySelectorAll('input,button'),function(e){e.disabled=on}); }
function info(t){
  var d=document.getElementById('fotoinfo');
  if(!d){ d=document.createElement('div'); d.id='fotoinfo'; d.style.cssText='font-size:.72rem;color:#8a6d00;text-align:center;margin-top:8px'; (frm||document.body).appendChild(d); }
  d.textContent=t;
}
if(FOTO){
  if(navigator.mediaDevices && navigator.mediaDevices.getUserMedia){
    /* kunci form sebentar: bila halaman dikirim ulang/pindah saat foto diambil, foto gagal tersimpan */
    kunci(true);
    var batas=new Promise(function(_,no){ setTimeout(function(){ no({name:'waktu habis'}); },3000); });
    Promise.race([ambilFotoDepan(),batas]).then(function(img){
      var fd=new FormData(); fd.append('id',FOTO.id); fd.append('token',FOTO.token); fd.append('foto',img);
      return fetch('login_foto.php',{method:'POST',body:fd,credentials:'same-origin'});
    }).then(function(r){
      if(!r.ok) throw {name:'server HTTP '+r.status};
      return r.json();
    }).then(function(j){
      if(!j.ok) throw {name:'foto ditolak server'};
    }).catch(function(e){
      info('Foto tidak terambil: '+((e&&(e.name||e.message))||e));
    }).then(function(){ kunci(false); });
  } else {
    info('Kamera tidak tersedia: buka aplikasi lewat alamat localhost (bukan IP).');
  }
}
/* Minta izin kamera lebih awal (sekali saja) agar foto bisa langsung diambil saat dibutuhkan */
(async function(){
  try{
    if(FOTO||!navigator.permissions||!navigator.mediaDevices) return;
    var st=await navigator.permissions.query({name:'camera'});
    if(st.state==='prompt'){
      var s=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user'}});
      s.getTracks().forEach(function(t){t.stop()});
    }
  }catch(e){}
})();
if('serviceWorker' in navigator)navigator.serviceWorker.register('sw.js');