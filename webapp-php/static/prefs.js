/* Display preferences — currently just the visual skin.
 *
 * The stored value is applied before first paint by the inline boot script in
 * the <head> (pg_skin_boot()), so this file only wires up the control and keeps
 * it in sync with what is actually applied.
 *
 * `hartie` is plain app.css; every other option is a file in static/skins/,
 * scoped entirely under its own [data-skin="<filename>"]. The picker's options
 * are discovered server-side by inc/skins.php, so nothing here needs to know
 * which skins exist. */

function setSkin(skin) {
  document.documentElement.setAttribute('data-skin', skin);
  try { localStorage.setItem('pg.skin', skin); } catch (_) {}
  syncSkinControls();
}

function syncSkinControls() {
  var skin = document.documentElement.getAttribute('data-skin');
  if (!skin) return;
  document.querySelectorAll('[data-skin-select]').forEach(function (sel) {
    // The boot script falls back to the default when a stored skin no longer has
    // a file, so trust the attribute over localStorage — but don't force a value
    // the <select> has no option for.
    if (sel.querySelector('option[value="' + CSS.escape(skin) + '"]')) sel.value = skin;
  });
}

syncSkinControls();
document.addEventListener('DOMContentLoaded', syncSkinControls);
