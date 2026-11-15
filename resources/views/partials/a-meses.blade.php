{{-- Solo con tarjeta de crédito: compra a meses (el gasto va completo en la fecha de compra). --}}
<label class="a-meses">¿A meses?
  <select name="meses">
    <option value="">De contado</option>
    @foreach (\App\Models\Gasto::OPCIONES_MESES as $n)<option value="{{ $n }}" @selected((string) $actual === (string) $n)>{{ $n }} meses</option>@endforeach
  </select>
</label>
<script>
  (() => {
    const lbl = document.currentScript.previousElementSibling, f = lbl.closest('form');
    const sync = () => {
      const credito = f.cuenta_id.selectedOptions[0]?.dataset.credito === '1';
      lbl.style.display = credito ? '' : 'none';
      if (! credito) lbl.querySelector('select').value = '';
    };
    f.cuenta_id.addEventListener('change', sync); sync();
  })();
</script>
