/* Client-side validation for forms marked data-validate. Server-side validation is always authoritative. */
(function () {
  'use strict';
  const MSG = {
    valueMissing: 'This field is required.', typeMismatch: 'Please enter a valid value.',
    patternMismatch: 'Please match the requested format.', tooShort: 'This value is too short.',
    tooLong: 'This value is too long.', rangeUnderflow: 'This value is too small.', rangeOverflow: 'This value is too large.',
    stepMismatch: 'Please enter a valid number.'
  };
  function message(input) {
    if (input.title && input.validity.patternMismatch) return input.title;
    for (const k in MSG) if (input.validity[k]) return MSG[k];
    return input.validationMessage;
  }
  function show(input) {
    const field = input.closest('.field'); if (!field) return;
    const err = field.querySelector('.field-error');
    const bad = !input.checkValidity();
    field.classList.toggle('has-error', bad);
    if (err) err.textContent = bad ? message(input) : '';
  }
  document.querySelectorAll('form[data-validate]').forEach(form => {
    form.noValidate = true;
    const inputs = () => Array.from(form.querySelectorAll('input:not([type=hidden]), select, textarea'));
    inputs().forEach(i => { i.addEventListener('blur', () => show(i)); i.addEventListener('input', () => { if (i.closest('.has-error')) show(i); }); });
    const pw = form.querySelector('input[data-match]');
    form.addEventListener('submit', e => {
      if (pw) { const other = form.querySelector(pw.dataset.match); pw.setCustomValidity(other && pw.value !== other.value ? 'Passwords do not match.' : ''); }
      let first = null;
      inputs().forEach(i => { show(i); if (!i.checkValidity() && !first) first = i; });
      if (first) { e.preventDefault(); first.focus(); window.GMS && GMS.toast('Please correct the highlighted fields.', 'warning', 3500); }
    });
  });
  /* live password strength hint */
  document.querySelectorAll('input[data-strength]').forEach(inp => {
    const hint = document.getElementById(inp.dataset.strength); if (!hint) return;
    inp.addEventListener('input', () => {
      const v = inp.value; const checks = [v.length >= 10, /[a-z]/.test(v), /[A-Z]/.test(v), /\d/.test(v), /[^A-Za-z0-9]/.test(v)];
      const n = checks.filter(Boolean).length; hint.textContent = v ? ['Very weak', 'Weak', 'Fair', 'Good', 'Strong', 'Very strong'][n] + ' (' + n + '/5 rules)' : '';
    });
  });
})();
