<?php
/**
 * The live region of the county selection and the JSON block that static/home.js reads (#home-data):
 * { total, counties: {slug: n}, items: {id: {params: {field: [values]}, counties: {slug: n}}} }.
 * The server writes lists as objects (JSON_FORCE_OBJECT). It is the same for every reader.
 *
 * In: $home_data = array in that shape
 */
declare(strict_types=1);
?>
<p data-home-live role="status" aria-live="polite" class="sr-only"></p>
<script type="application/json" id="home-data"><?= json_encode($home_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_FORCE_OBJECT) ?></script>
