/*
 * The table of contents (partials/toc.php). Marks the link of the section in view with aria-current="true".
 * A section is "in view" when its heading is the last one that has passed the line at 12 % of the window height;
 * at the end of the page the last section is marked. Without this script the list works and has no mark.
 * No personal state is read or written here.
 */
(function () {
  'use strict';
  var nav = document.querySelector('[data-toc]');
  if (!nav || !('IntersectionObserver' in window)) return;

  var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
  var items = links.map(function (a) {
    return { a: a, h: document.getElementById(decodeURIComponent(a.getAttribute('href').slice(1))) };
  }).filter(function (it) { return it.h; });
  if (!items.length) return;

  function update() {
    var line = window.innerHeight * 0.12;
    var atEnd = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;
    var cur = null;
    items.forEach(function (it) { if (it.h.getBoundingClientRect().top <= line) cur = it; });
    if (atEnd) cur = items[items.length - 1];
    items.forEach(function (it) {
      if (it === cur) it.a.setAttribute('aria-current', 'true');
      else it.a.removeAttribute('aria-current');
    });
  }

  // A heading crossing the band above the line, and a sentinel at the end of the page, trigger an update.
  var io = new IntersectionObserver(update, { rootMargin: '0px 0px -88% 0px', threshold: [0, 1] });
  items.forEach(function (it) { io.observe(it.h); });
  var end = document.createElement('div');
  end.setAttribute('aria-hidden', 'true');
  end.style.cssText = 'height:1px;pointer-events:none';
  document.body.appendChild(end);
  new IntersectionObserver(update, { threshold: [0, 1] }).observe(end);   // no margin: it must show at the window bottom
  window.addEventListener('hashchange', function () { window.requestAnimationFrame(update); });
  update();
})();
