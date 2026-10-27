(function () {
  var el = document.querySelector('input[name="jumlah"]');
  if (!el) return;

  el.type = 'text';
  el.removeAttribute('min');
  el.removeAttribute('step');
  el.setAttribute('inputmode', 'numeric');

  function format(v) {
    var angka = v.replace(/\D/g, '');
    return angka ? 'Rp ' + angka.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';
  }

  el.addEventListener('input', function () {
    el.value = format(el.value);
  });

  if (el.form) {
    el.form.addEventListener('submit', function () {
      el.value = el.value.replace(/\D/g, '');
    });
  }
})();