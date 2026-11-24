/* Client-side validation for forms marked data-validate. Server-side validation is always authoritative. */
(function () {
  'use strict';

  const isAm = () => document.documentElement.lang === 'am';

  const MSG_EN = {
    valueMissing: 'This field is required.',
    typeMismatch: 'Please enter a valid value.',
    patternMismatch: 'Please match the requested format.',
    tooShort: 'This value is too short.',
    tooLong: 'This value is too long.',
    rangeUnderflow: 'This value is too small.',
    rangeOverflow: 'This value is too large.',
    stepMismatch: 'Please enter a valid number.'
  };

  const MSG_AM = {
    valueMissing: 'ይህ መስክ አስፈላጊ ነው።',
    typeMismatch: 'እባክዎ ትክክለኛ እሴት ያስገቡ።',
    patternMismatch: 'እባክዎ የተጠየቀውን ቅርጸት ይከተሉ።',
    tooShort: 'ይህ እሴት በጣም አጭር ነው።',
    tooLong: 'ይህ እሴት በጣም ረጅም ነው።',
    rangeUnderflow: 'ይህ እሴት በጣም አነስተኛ ነው።',
    rangeOverflow: 'ይህ እሴት በጣም ከፍተኛ ነው።',
    stepMismatch: 'እባክዎ ትክክለኛ ቁጥር ያስገቡ።'
  };

  const STRENGTH_EN = ['Very weak', 'Weak', 'Fair', 'Good', 'Strong', 'Very strong'];
  const STRENGTH_AM = ['በጣም ደካማ', 'ደካማ', 'መካከለኛ', 'ጥሩ', 'ጠንካራ', 'በጣም ጠንካራ'];

  function message(input) {
    if (input.title && input.validity.patternMismatch) return input.title;
    const msgTable = isAm() ? MSG_AM : MSG_EN;
    for (const k in msgTable) {
      if (input.validity[k]) return msgTable[k];
    }
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
    inputs().forEach(i => {
      i.addEventListener('blur', () => show(i));
      i.addEventListener('input', () => { if (i.closest('.has-error')) show(i); });
    });

    const pw = form.querySelector('input[data-match]');
    form.addEventListener('submit', e => {
      if (pw) {
        const other = form.querySelector(pw.dataset.match);
        const mismatchMsg = isAm() ? 'የይለፍ ቃሎች አይዛመዱም።' : 'Passwords do not match.';
        pw.setCustomValidity(other && pw.value !== other.value ? mismatchMsg : '');
      }
      let first = null;
      inputs().forEach(i => {
        show(i);
        if (!i.checkValidity() && !first) first = i;
      });
      if (first) {
        e.preventDefault();
        first.focus();
        const toastMsg = isAm() ? 'እባክዎ የተጠቆሙትን መስኮች ያስተካክሉ።' : 'Please correct the highlighted fields.';
        window.GMS && GMS.toast(toastMsg, 'warning', 3500);
      }
    });
  });

  /* live password strength hint */
  document.querySelectorAll('input[data-strength]').forEach(inp => {
    const hint = document.getElementById(inp.dataset.strength); if (!hint) return;
    inp.addEventListener('input', () => {
      const v = inp.value;
      const checks = [v.length >= 10, /[a-z]/.test(v), /[A-Z]/.test(v), /\d/.test(v), /[^A-Za-z0-9]/.test(v)];
      const n = checks.filter(Boolean).length;
      const labels = isAm() ? STRENGTH_AM : STRENGTH_EN;
      const suffix = isAm() ? ' (' + n + '/5 ህጎች)' : ' (' + n + '/5 rules)';
      hint.textContent = v ? labels[n] + suffix : '';
    });
  });
})();
