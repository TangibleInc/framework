/* is-selected follows the native input without a framework — one delegated
   listener covers every option card the page renders. */
document.addEventListener('change', function (e) {
  var toggle = e.target.closest && e.target.closest('.tgbl-toggle');
  if (toggle) {
    var row = toggle.closest('.tgbl-toggle-row');
    if (row) row.classList.toggle('is-on', toggle.checked);
    var out = document.querySelector('[data-tgbl-estimate]');
    if (out) {
      var total = 0;
      document.querySelectorAll('.tgbl-toggle[data-count]').forEach(function (t) {
        if (t.checked) total += parseInt(t.dataset.count, 10) || 0;
      });
      out.textContent = total.toLocaleString();
    }
    return;
  }
  var input = e.target.closest && e.target.closest('.tui-option-card__input');
  if (!input) return;
  document.querySelectorAll('.tui-option-card__input[name="' + input.name + '"]').forEach(function (i) {
    var card = i.closest('[data-tgbl-option]');
    if (card) card.classList.toggle('is-selected', i.checked);
  });
});
