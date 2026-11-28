(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var toggle = e.target.closest('[data-sidebar-toggle]');
    if (toggle) document.querySelector('.sidebar').classList.toggle('open');

    var add = e.target.closest('[data-add-row]');
    if (add) {
      var wrap = document.querySelector(add.getAttribute('data-add-row'));
      if (!wrap) return;
      var tpl = wrap.querySelector('template');
      if (tpl) wrap.insertBefore(tpl.content.cloneNode(true), tpl);
    }
    var del = e.target.closest('[data-remove-row]');
    if (del) {
      var row = del.closest('[data-row]');
      if (row) row.remove();
    }
    var copy = e.target.closest('[data-copy]');
    if (copy) {
      if (navigator.clipboard) navigator.clipboard.writeText(copy.getAttribute('data-copy'));
      var old = copy.textContent;
      copy.textContent = '已复制';
      setTimeout(function () { copy.textContent = old; }, 1200);
    }
    var fill = e.target.closest('[data-fill]');
    if (fill) {
      var target = document.querySelector(fill.getAttribute('data-fill'));
      if (target) { target.value = fill.getAttribute('data-text') || ''; target.focus(); }
    }
    var tab = e.target.closest('[data-tab]');
    if (tab) {
      e.preventDefault();
      document.querySelectorAll('[data-tab]').forEach(function (t) { t.classList.remove('active'); });
      tab.classList.add('active');
      var target = tab.getAttribute('data-tab');
      document.querySelectorAll('[data-tab-pane]').forEach(function (p) {
        p.style.display = p.getAttribute('data-tab-pane') === target ? '' : 'none';
      });
    }
  });
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.hasAttribute('data-confirm')) {
      if (!window.confirm(form.getAttribute('data-confirm') || '确定执行该操作？')) e.preventDefault();
    }
  });
})();
