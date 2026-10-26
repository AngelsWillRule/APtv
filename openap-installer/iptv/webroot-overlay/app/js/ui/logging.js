(function () {
  'use strict';

  var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-log-tab]'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('[data-log-panel]'));

  function activateTab(service) {
    tabs.forEach(function (tab) {
      var active = tab.dataset.logTab === service;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.setAttribute('tabindex', active ? '0' : '-1');
    });
    panels.forEach(function (panel) {
      var active = panel.dataset.logPanel === service;
      panel.classList.toggle('active', active);
      panel.hidden = !active;
    });
  }

  tabs.forEach(function (tab, index) {
    tab.addEventListener('click', function () { activateTab(tab.dataset.logTab); });
    tab.addEventListener('keydown', function (event) {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      var target = index;
      if (event.key === 'ArrowRight') target = (index + 1) % tabs.length;
      if (event.key === 'ArrowLeft') target = (index - 1 + tabs.length) % tabs.length;
      if (event.key === 'Home') target = 0;
      if (event.key === 'End') target = tabs.length - 1;
      activateTab(tabs[target].dataset.logTab);
      tabs[target].focus();
    });
  });

  function fallbackCopy(text) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    var copied = document.execCommand('copy');
    document.body.removeChild(area);
    return copied ? Promise.resolve() : Promise.reject(new Error('copy failed'));
  }

  document.querySelectorAll('[data-copy-log]').forEach(function (button) {
    button.addEventListener('click', function () {
      var output = document.getElementById('log-' + button.dataset.copyLog);
      if (!output) return;
      var copy = navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(output.textContent || '') : fallbackCopy(output.textContent || '');
      copy.then(function () {
        var label = button.querySelector('span');
        var icon = button.querySelector('i');
        var original = label.textContent;
        label.textContent = 'Copied';
        icon.className = 'fas fa-check';
        button.classList.add('is-copied');
        window.setTimeout(function () { label.textContent = original; icon.className = 'far fa-copy'; button.classList.remove('is-copied'); }, 1600);
      });
    });
  });
}());
