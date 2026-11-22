/* global.js — shared JS utilities for pages in user/
   This file is loaded by the JS shims so no PHP changes are necessary.
*/
(function (window) {
  window._userUtils = window._userUtils || {};

  window._userUtils.formatDate = function (iso) {
    if (!iso) return '-';
    var d = new Date(iso);
    return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
  };

  // Simple DOM ready helper
  window._userUtils.ready = function (fn) {
    if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn);
  };

  // Remove toast(s) after timeout (default 5000ms)
  window._userUtils.removeToasts = function (timeout) {
    timeout = typeof timeout === 'number' ? timeout : 5000;
    setTimeout(function () {
      document.querySelectorAll('.toast').forEach(function (el) { el.remove(); });
    }, timeout);
  };

  // Convenience: create and show a toast 
  window._userUtils.showToast = function (message, timeout) {
    var t = document.createElement('div');
    t.className = 'toast';
    t.textContent = message || '';
    document.body.appendChild(t);
    if (typeof timeout === 'number') window._userUtils.removeToasts(timeout);
  };

  console.log('user/global.js loaded');
})(window);
