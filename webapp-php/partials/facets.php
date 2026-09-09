<?php
/**
 * The facet sidebar: every filter group, with its counts.
 *
 * Rendered twice per filter change — once inside the form on a full page load,
 * and once as an `hx-swap-oob` fragment on every HTMX request, which is what
 * keeps the counts live. That costs no extra queries: `list.php` computes all
 * of them before it checks `$is_htmx`, so until this partial existed every
 * filter click already paid for the counts and then discarded them, leaving
 * stale numbers on screen until the next full page load.
 *
 * Swapping whole groups rather than the individual count `<span>`s is
 * deliberate: narrowing a filter drops values out of a sibling group's list
 * and widening one brings them back, so there is no fixed set of spans to
 * address. The client state a swap would drop — focus, per-group scroll,
 * `<details>` open/closed — is restored in list.php's script.
 *
 * Expects list.php's scope: the `$*_options` arrays and the `$*_sel` /
 * `$*_flags` selections.
 */
?>
<div id="facet-list"<?= $is_htmx ? ' hx-swap-oob="true"' : '' ?>>
        <?php
        /**
         * A facet as a <details> disclosure: native keyboard + screen-reader
         * behaviour for free. The open/closed state is client state and this
         * markup is swapped on every filter change, so it is re-applied from
         * localStorage by applyFacetState() in list.php's script.
         */
        function facet_group(string $label, array $items, string $name, array $active, bool $open = false): void {
            if (!$items) return;
            $active = array_map('strval', $active);
            $values = array_map(fn($i) => (string)($i['val'] ?? $i['value'] ?? ''), $items);

            // Facets are capped (județ shows the top 25 of ~197 slugs). A selected
            // value outside that window has no checkbox, so the next HTMX submit
            // serialises the form without it and the filter silently disappears.
            // Pin any such value to the top of its group.
            // The count is left blank rather than set to 0: an orphan's real
            // count is simply unknown here (it fell outside the display cap),
            // and a rendered "0" would read as "this selection matches nothing".
            foreach (array_reverse(array_diff($active, $values)) as $orphan) {
                array_unshift($items, [
                    'val'   => $orphan,
                    'label' => filter_value_label($name, $orphan),
                    'cnt'   => '',
                ]);
                $values[] = $orphan;
            }

            $selected = array_intersect($active, $values);
            $is_open  = $open || $selected;   // never hide a filter that is switched on
            $mode     = facet_mode($name);
            ?>
            <details class="facet-group mb-1 border-b border-line/60 pb-1" data-facet="<?= e($name) ?>"<?= $is_open ? ' open' : '' ?>>
              <summary class="flex cursor-pointer list-none items-center justify-between py-2 text-xs font-semibold uppercase tracking-widest text-ink-muted marker:content-none hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                <span><?= e($label) ?><?php if ($selected): ?> <span class="font-mono text-gov normal-case tracking-normal">(<?= count($selected) ?>)</span><?php endif; ?></span>
                <span aria-hidden="true" class="facet-caret text-ink-muted transition-transform">▾</span>
              </summary>
              <?php if (isset(FACET_MODE_PARAMS[$name]) && $selected): ?>
                <?php /* The any/all switch, shown from the first pick onward — with
                   nothing or one value checked the two modes agree, so before that
                   it would be a control with no visible effect. Radios inside the
                   filter form, so the form's `change` trigger re-runs the query. */ ?>
                <div role="group" aria-label="Cum se combină valorile bifate"
                     class="mb-1.5 flex items-center gap-1 text-[11px] text-ink-muted">
                  <?php foreach ([['any', 'oricare', 'Postul are cel puțin una dintre valorile bifate'],
                                  ['all', 'toate',   'Postul le are pe toate']] as [$m, $mlabel, $hint]): ?>
                    <?php /* The radio itself is sr-only, so the ring has to come
                       from the label or the control is invisible to a keyboard. */ ?>
                    <label title="<?= e($hint) ?>"
                           class="cursor-pointer rounded px-1.5 py-0.5 focus-within:outline-none focus-within:ring-2 focus-within:ring-focus <?= $mode === $m ? 'bg-gov-light font-semibold text-gov' : 'hover:text-ink' ?>">
                      <input type="radio" name="<?= e(facet_mode_field($name)) ?>" value="<?= e($m) ?>"
                             class="sr-only"<?= checked_if($mode === $m) ?>><?= e($mlabel) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php /* `facet-options` is what list.php's scroll save/restore
                 addresses. It cannot use `.facet-group > div`: the any/all
                 switch above is also a direct child, and being first it is what
                 a `querySelector` would return. */ ?>
              <div class="facet-options space-y-0.5 pb-2 pr-1 lg:max-h-56 lg:overflow-y-auto">
                <?php foreach ($items as $item):
                    $val = (string)($item['val'] ?? $item['value'] ?? '');
                    $lbl = $item['label'] ?? $val;
                    $cnt = $item['cnt'] ?? $item['count'] ?? 0;
                    $on  = in_array($val, array_map('strval', $active), true);
                    // A count of 0 is a dead end: checking it can only empty the
                    // result list. Disable it rather than let it look like any
                    // other option — but never disable a *checked* one, or the
                    // filter could not be taken off again. Some groups (Anomalii)
                    // pass '' for "no count available", which is not a zero.
                    $dead = !$on && $cnt !== '' && (int)$cnt === 0; ?>
                  <label class="group flex items-center gap-2 py-1 <?= $dead ? 'cursor-not-allowed opacity-40' : 'cursor-pointer' ?>">
                    <input type="checkbox" name="<?= e(param_field($name)) ?>" value="<?= e($val) ?>"
                           class="facet-check accent-gov shrink-0"<?= checked_if($on) ?><?= $dead ? ' disabled' : '' ?>>
                    <span class="flex-1 truncate text-sm text-ink <?= $dead ? '' : 'transition-colors group-hover:text-gov' ?>"><?= e($lbl) ?></span>
                    <span class="shrink-0 font-mono text-xs text-ink-muted"><?= $cnt === '' ? '' : $cnt ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </details>
            <?php
        }

        // Open by default: the four facets people reach for first.
        facet_group('Domeniu',           $family_options,    'family',        $families,     true);
        facet_group('Județ',             $judet_options,     'judet',         $judet_slugs,  true);
        facet_group('Nivel',             $level_options,     'level',         $levels,       true);
        facet_group('Tip',               $type_options,      'type',          $types,        true);
        facet_group('Categorie',         $cat_options,       'categorie',     $categories);
        facet_group('Grad/funcție',      $seniority_options, 'seniority',     $seniorities);
        facet_group('Tip normă',         $work_type_options, 'work_type',     $work_types);
        facet_group('Experiență minimă', $exp_options,       'exp_level',     $exp_levels);
        facet_group('Studii minime',     $studies_options,   'studies_level', $studies_lvls);

        // Prompt-v3 facets. Each is omitted while its column is empty, so they
        // appear on their own once a v3 extraction has run.
        facet_group('Domeniu de studii', $isced_options,  'isced', $isced_sel, true);
        facet_group('Competențe',        $skill_options,  'skill', $skill_sel, true);
        facet_group('Domeniu activitate',$domain_options, 'domain', $domain_sel);
        facet_group('Nivel studii (EQF)',$eqf_options,    'eqf',   $eqf_sel);
        facet_group('Limbi străine',     $lang_options,   'lang',  $lang_sel);

        if ($remote_count) {
            facet_group('Telemuncă', [['val' => '1', 'label' => 'Disponibil remote', 'cnt' => $remote_count]],
                        'remote', $remote ? ['1'] : []);
        }
        ?>

        <!-- Advanced -->
        <details class="facet-group mt-2 border-t border-line pt-2" data-facet="advanced"<?= ($sal_bucket || $emp_cats || $anomaly_flags || $exp_before || $exp_after || $schema_filter) ? ' open' : '' ?>>
          <summary class="flex cursor-pointer list-none items-center justify-between py-2 text-xs font-semibold uppercase tracking-widest text-gov marker:content-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus">
            <span>Mai multe filtre</span>
            <span aria-hidden="true" class="facet-caret transition-transform">▾</span>
          </summary>
          <div class="pt-1">
            <?php
            facet_group('Salariu',   $salary_options,  'salary_bucket', $sal_bucket ? [$sal_bucket] : [], true);
            facet_group('Angajator', $emp_cat_options, 'employer_cat',  $emp_cats,                        true);

            facet_group('Anomalii',  $anomaly_options, 'anomaly', $anomaly_flags, true);
            facet_group('Descriere',  $schema_options, 'schema',     $schema_filter ? [$schema_filter] : [], true);
            facet_group('Documente necesare', $cred_options, 'credential', $cred_sel, true);
            facet_group('Etape concurs',      $stage_options, 'stage',     $stage_sel, true);
            ?>

            <fieldset class="mb-4 pt-2">
              <legend class="mb-2 text-xs font-semibold uppercase tracking-widest text-ink-muted">Termen limită exact</legend>
              <div class="space-y-1.5">
                <div>
                  <label for="expires_after" class="text-xs text-ink-muted">De la</label>
                  <input type="date" id="expires_after" name="expires_after" value="<?= e($exp_after) ?>"
                         class="mt-0.5 w-full rounded border border-line-strong bg-surface px-2 py-1 text-xs text-ink focus:border-gov focus:outline-none focus:ring-1 focus:ring-focus">
                </div>
                <div>
                  <label for="expires_before" class="text-xs text-ink-muted">Până la</label>
                  <input type="date" id="expires_before" name="expires_before" value="<?= e($exp_before) ?>"
                         class="mt-0.5 w-full rounded border border-line-strong bg-surface px-2 py-1 text-xs text-ink focus:border-gov focus:outline-none focus:ring-1 focus:ring-focus">
                </div>
              </div>
            </fieldset>
          </div>
        </details>

        <?php if ($active_chips): ?>
          <a href="/" class="mt-2 block py-1 text-center text-xs text-gov hover:underline">✕ Șterge filtrele</a>
        <?php endif; ?>
</div>
