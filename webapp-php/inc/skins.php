<?php
declare(strict_types=1);

/**
 * Skin discovery.
 *
 * A skin is a plain CSS file in static/skins/. Drop one in and it appears in the
 * picker on the next request — there is no registry to edit and no build step.
 * Delete it and it disappears, including for anyone whose browser had it
 * selected (see pg_skin_boot()).
 *
 * Skins live under static/ rather than assets/ for two reasons: deploy-php.sh
 * excludes assets/ (it is build input, not served), and a skin needs no build —
 * it is hand-written CSS that only re-declares the custom properties app.css
 * defines. Running them through Tailwind would strip them, since nothing in the
 * PHP references their selectors.
 *
 * Three conventions a skin file must follow:
 *
 *   1. Scope every rule under [data-skin="<filename>"]. Every skin file is
 *      loaded on every page; the attribute on <html> decides which one wins. A
 *      file that forgets to scope itself applies to all skins at once. The one
 *      exception is @font-face, which declares rather than applies and so is
 *      safe (and is how a skin ships its own typeface).
 *
 *   2. Declare a display name in a CSS comment near the top, using an "@skin"
 *      tag followed by the label — see static/skins/_template.css.
 *
 *   3. Re-declare tokens, don't restyle components. app.css is written against
 *      the variables, so the palette is the whole API.
 *
 * "hartie" is the built-in null skin: app.css's own :root with nothing layered
 * on top. It has no file, which is why it is added by hand below.
 */

define('SKINS_DIR',    __DIR__ . '/../static/skins');
define('SKINS_URL',    '/static/skins');
define('DEFAULT_SKIN', 'hartie');
define('BASE_SKIN',    'hartie');

/**
 * @return array<string,string> skin id => display label, base skin first.
 */
function pg_skins(): array
{
    static $skins = null;
    if ($skins !== null) return $skins;

    $skins = [BASE_SKIN => 'Hârtie'];

    foreach (glob(SKINS_DIR . '/*.css') ?: [] as $path) {
        $id = basename($path, '.css');

        // Leading underscore marks a template or a partial, not a skin.
        if ($id === '' || $id[0] === '_') continue;

        // The id ends up in a data- attribute, a CSS attribute selector and a
        // URL, so validate it rather than trusting whatever the filename is.
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id)) continue;

        $skins[$id] = pg_skin_label($path) ?? $id;
    }

    return $skins;
}

/** Read the `@skin <label>` declaration from the top of a skin file. */
function pg_skin_label(string $path): ?string
{
    $head = @file_get_contents($path, false, null, 0, 512);
    if ($head === false) return null;
    if (!preg_match('/@skin\s+(.+)$/mu', $head, $m)) return null;

    // Trim the comment terminator when the declaration is on one line.
    $label = trim(preg_replace('#\*/.*$#u', '', $m[1]) ?? '');
    return $label !== '' ? $label : null;
}

/**
 * <link> tags for every discovered skin.
 *
 * Loading all of them on every page is deliberate. Emitting only the active
 * skin's <link> needs either a cookie round-trip (which breaks shared-host page
 * caching) or a JS-injected stylesheet, and the latter reintroduces the flash of
 * unstyled content that the pre-paint boot script exists to prevent. The files
 * are a few KB each. If this folder ever grows past a handful, reconsider.
 *
 * mtime is appended so an edited skin shows up on reload without a hard refresh.
 */
function pg_skin_links(): string
{
    $out = [];
    foreach (array_keys(pg_skins()) as $id) {
        if ($id === BASE_SKIN) continue;
        $file = SKINS_DIR . '/' . $id . '.css';
        $v    = @filemtime($file) ?: 0;
        $out[] = '<link rel="stylesheet" href="' . SKINS_URL . '/'
               . rawurlencode($id) . '.css?v=' . $v . '">';
    }
    return implode("\n  ", $out);
}

/**
 * The pre-paint boot script, for the <head>. Applies the stored skin before
 * first paint so there is no flash of the default palette.
 *
 * The valid ids are baked in so a skin that has since been deleted from the
 * folder falls back to the default, instead of leaving <html> pointing at a
 * stylesheet that no longer exists.
 */
function pg_skin_boot(): string
{
    $ids     = json_encode(array_keys(pg_skins()), JSON_UNESCAPED_UNICODE);
    $default = json_encode(DEFAULT_SKIN);

    return '<script>(function(){try{'
         . 'var ok=' . $ids . ',s=localStorage.getItem("pg.skin");'
         . 'document.documentElement.setAttribute("data-skin",'
         . 'ok.indexOf(s)>=0?s:' . $default . ');'
         . '}catch(e){}})();</script>';
}

/**
 * The skin picker. `$id` is what a visible <label for> points at; `$class` adds
 * modifiers.
 */
function pg_skin_select(string $id = 'skin-picker', string $class = ''): string
{
    $cls = trim('skin-select ' . $class);

    $out = '<select id="' . e($id) . '" class="' . e($cls) . '" data-skin-select'
         . ' title="Stil vizual" onchange="setSkin(this.value)">';
    foreach (pg_skins() as $skin_id => $label) {
        $out .= '<option value="' . e($skin_id) . '">' . e($label) . '</option>';
    }
    return $out . '</select>';
}
