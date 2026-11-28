(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var toggle = e.target.closest('[data-nav-toggle]');
    if (toggle) {
      var nav = document.querySelector('.nav');
      if (nav) nav.classList.toggle('open');
    }
    var copy = e.target.closest('[data-copy]');
    if (copy) {
      var text = copy.getAttribute('data-copy');
      if (navigator.clipboard) navigator.clipboard.writeText(text);
      var old = copy.textContent;
      copy.textContent = '已复制';
      setTimeout(function () { copy.textContent = old; }, 1200);
    }
    var pw = e.target.closest('[data-reveal]');
    if (pw) {
      var input = document.querySelector(pw.getAttribute('data-reveal'));
      if (input) input.type = input.type === 'password' ? 'text' : 'password';
    }
  });
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.hasAttribute('data-confirm')) {
      if (!window.confirm(form.getAttribute('data-confirm') || '确定执行该操作？')) {
        e.preventDefault();
      }
    }
  });
  // 域名后缀快捷选择
  document.querySelectorAll('[data-tld]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var input = document.querySelector('input[name="keyword"]');
      if (!input) return;
      var base = input.value.split('.')[0];
      input.value = base + '.' + chip.getAttribute('data-tld');
      document.querySelectorAll('[data-tld]').forEach(function (c) { c.classList.remove('active'); });
      chip.classList.add('active');
    });
  });
})();
