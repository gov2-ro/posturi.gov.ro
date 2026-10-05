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
        function facet_group(string $label, array $items, string $name, array $active, bool $open = false, string $help = ''): void {
            $active = array_values(array_filter(array_map('strval', $active), fn($v) => $v !== ''));
            // An empty group with a live selection (a bookmarked value whose
            // options vanished) is still rendered: its pinned orphan keeps the
            // selection in the form and removable.
            if (!$items && !$active) return;
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
            <details class="facet-group mb-1 border-b border-line/60 pb-1" data-facet="<?= e($name) ?>"<?= $selected ? ' data-selected="1"' : '' ?><?= $is_open ? ' open' : '' ?>>
              <summary class="flex cursor-pointer list-none items-center justify-between py-2 text-xs font-semibold uppercase tracking-widest text-ink-muted marker:content-none hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                <span><?= e($label) ?><?php if ($selected): ?> <span class="font-mono text-gov normal-case tracking-normal">(<?= count($selected) ?>)</span><?php endif; ?></span>
                <span aria-hidden="true" class="facet-caret text-ink-muted transition-transform">▾</span>
              </summary>
              <?php if ($help !== ''): ?>
                <p class="facet-help mb-1.5 text-[11px] leading-snug text-ink-muted"><?= e($help) ?></p>
              <?php endif; ?>
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

        /**
         * A parent disclosure: groups related controls under one heading.
         * Open when any control inside it holds a selection (the same rule as a
         * single group, applied to every ancestor), never otherwise by default.
         * Renders nothing when it has no content and no selection, so the
         * v3/v4 groups still stay invisible until their data exists.
         *
         * @param string[] $params   query parameters living anywhere inside it
         */
        function facet_parent(string $key, string $label, array $params, callable $body, string $note = '', bool $nested = false): void {
            global $facet_sel;
            $on = false;
            foreach ($params as $param) {
                foreach ((array)($facet_sel[$param] ?? []) as $v) {
                    if ((string)$v !== '') { $on = true; break 2; }
                }
            }
            ob_start();
            $body();
            $html = ob_get_clean();
            if (trim($html) === '' && !$on) return;
            ?>
            <details class="facet-group facet-parent <?= $nested ? 'mt-1 border-l border-line pl-2' : 'mt-2 border-t border-line pt-2' ?>"
                     data-facet="<?= e($key) ?>"<?= $on ? ' data-selected="1" open' : '' ?>>
              <summary class="flex cursor-pointer list-none items-center justify-between py-2 text-xs font-semibold uppercase tracking-widest <?= $nested ? 'text-ink-muted hover:text-ink' : 'text-gov' ?> marker:content-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                <span><?= e($label) ?></span>
                <span aria-hidden="true" class="facet-caret transition-transform">▾</span>
              </summary>
              <?php if ($note !== ''): ?>
                <p class="facet-help mb-1.5 text-[11px] leading-snug text-ink-muted"><?= e($note) ?></p>
              <?php endif; ?>
              <div class="pt-1"><?= $html ?></div>
            </details>
            <?php
        }

        // Selections by query parameter, so a parent can tell whether anything
        // inside it is switched on (`facet_parent()` reads this).
        $facet_sel = [
            'judet' => $judet_slugs, 'family' => $families, 'occupation' => $occ_sel,
            'eqf' => $eqf_sel, 'isced' => $isced_sel, 'studies_level' => $studies_lvls,
            'exp_level' => $exp_levels, 'skill' => $skill_sel, 'lang' => $lang_sel, 'credential' => $cred_sel,
            'duration' => $duration_sel, 'schedule' => $schedule_sel,
            'shift' => $shift ? ['1'] : [], 'remote' => $remote ? ['1'] : [],
            'salary_bucket' => $sal_bucket ? [$sal_bucket] : [], 'sector' => $sector_sel, 'funding' => $funding_sel,
            'domain' => $domain_sel, 'stage' => $stage_sel,
            'expires_after' => [$exp_after], 'expires_before' => [$exp_before],
            'has_salary' => $has_salary ? ['1'] : [], 'computer' => $computer ? [$computer] : [],
            'level' => $levels, 'type' => $types, 'categorie' => $categories, 'seniority' => $seniorities,
            'work_type' => $work_types, 'employer_cat' => $emp_cats,
            'anomaly' => $anomaly_flags, 'schema' => $schema_filter ? [$schema_filter] : [],
        ];

        $auto_help = 'Clasificare automată din textul anunțului; poate fi incompletă. Cerințele valabile sunt cele din anunțul oficial.';

        // --- Location and profession. Only Județ and Domeniu profesional
        //     start open; Ocupație is the finer cut and starts closed.
        facet_group('Județ',                $judet_options,      'judet',      $judet_slugs, true);
        facet_group('Domeniu profesional',  $family_options,     'family',     $families,    true, $auto_help);
        facet_group('Ocupație',             $occupation_options, 'occupation', $occ_sel,     false, $auto_help);

        // --- Education. EQF is a nominal exact level per posting, the ISCED
        //     field is a different axis, and the text-derived level is the
        //     legacy field: three controls, none merged in SQL.
        facet_parent('studii', 'Studii', ['eqf', 'isced', 'studies_level'], function () use ($eqf_options, $eqf_sel, $isced_options, $isced_sel, $studies_options, $studies_lvls) {
            facet_group('Nivel de studii (EQF)',        $eqf_options,     'eqf',           $eqf_sel);
            facet_group('Domeniu de studii',            $isced_options,   'isced',         $isced_sel);
            facet_group('Studii identificate în text',  $studies_options, 'studies_level', $studies_lvls);
        }, 'Nivelurile filtrează cerințele anunțului, nu stabilesc dacă te califici.');

        // --- Experience, skills and documents.
        facet_parent('experienta', 'Experiență și cerințe', ['exp_level', 'skill', 'lang', 'credential'], function () use ($exp_options, $exp_levels, $skill_options, $skill_sel, $lang_options, $lang_sel, $cred_options, $cred_sel, $auto_help) {
            facet_group('Experiență minimă',  $exp_options,   'exp_level',  $exp_levels, false,
                        $auto_help . ' Unele intervale includ anunțuri fără experiență precizată.');
            facet_group('Competențe',         $skill_options, 'skill',      $skill_sel);
            facet_group('Limbi străine',      $lang_options,  'lang',       $lang_sel);
            facet_group('Documente necesare', $cred_options,  'credential', $cred_sel);
        });

        // --- Contract and working pattern.
        facet_parent('contract', 'Contract și program', ['duration', 'schedule', 'shift', 'remote'], function () use ($duration_options, $duration_sel, $schedule_options, $schedule_sel, $shift_count, $shift, $remote_count, $remote) {
            facet_group('Contract', $duration_options, 'duration', $duration_sel);
            facet_group('Program',  $schedule_options, 'schedule', $schedule_sel);
            if ($shift_count || $shift) {
                facet_group('Lucru în ture', [['val' => '1', 'label' => 'Ture, gărzi sau weekend', 'cnt' => $shift_count]],
                            'shift', $shift ? ['1'] : []);
            }
            if ($remote_count || $remote) {
                facet_group('Telemuncă', [['val' => '1', 'label' => 'Disponibil remote', 'cnt' => $remote_count]],
                            'remote', $remote ? ['1'] : []);
            }
        });
        ?>

        <!-- More filters -->
        <?php
        facet_parent('more', 'Mai multe filtre',
            ['salary_bucket', 'has_salary', 'sector', 'funding', 'domain', 'stage', 'expires_after', 'expires_before',
             'level', 'type', 'categorie', 'seniority', 'work_type', 'employer_cat', 'computer', 'anomaly', 'schema'],
            function () use ($salary_options, $sal_bucket, $has_salary, $sector_options, $sector_sel, $funding_options, $funding_sel,
                             $domain_options, $domain_sel, $stage_options, $stage_sel, $exp_after, $exp_before, $computer,
                             $level_options, $levels, $type_options, $types, $cat_options, $categories,
                             $seniority_options, $seniorities, $work_type_options, $work_types, $emp_cat_options, $emp_cats,
                             $anomaly_options, $anomaly_flags, $schema_options, $schema_filter) {
            // Estimated from the 2026 draft grid, not announced — postings
            // essentially never state a salary. See pages/detail.php for the
            // per-posting breakdown and the legal-status disclaimer.
            facet_group('Salariu estimat', $salary_options, 'salary_bucket', $sal_bucket ? [$sal_bucket] : []);
            // Legacy links only: `?has_salary=1` (a landing shortcut) and
            // `?computer=` have no sidebar control of their own, so an active
            // one is shown as a removable checkbox instead of being dropped by
            // the next unrelated form change.
            if ($has_salary) {
                facet_group('Cu salariu estimat', [['val' => '1', 'label' => 'Doar cu salariu estimat', 'cnt' => '']], 'has_salary', ['1']);
            }
            if ($computer) {
                facet_group('Calculator', [['val' => $computer, 'label' => filter_value_label('computer', $computer), 'cnt' => '']], 'computer', [$computer]);
            }
            facet_group('Sector angajator',     $sector_options,  'sector',  $sector_sel);
            facet_group('Finanțare',            $funding_options, 'funding', $funding_sel);
            facet_group('Domeniu de activitate', $domain_options, 'domain',  $domain_sel);
            facet_group('Etape concurs',        $stage_options,   'stage',   $stage_sel);
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
            <?php
            // The source's own labels sit beside the automatic ones, which is
            // easy to read as one list. They stay separate controls (no SQL is
            // merged), together under a heading that says where they come from.
            facet_parent('legacy', 'Clasificări din sursă și text',
                ['level', 'type', 'categorie', 'seniority', 'work_type', 'employer_cat'],
                function () use ($level_options, $levels, $type_options, $types, $cat_options, $categories,
                                 $seniority_options, $seniorities, $work_type_options, $work_types, $emp_cat_options, $emp_cats) {
                    facet_group('Nivel',        $level_options,     'level',        $levels);
                    facet_group('Tip',          $type_options,      'type',         $types);
                    facet_group('Categorie',    $cat_options,       'categorie',    $categories);
                    facet_group('Grad/funcție', $seniority_options, 'seniority',    $seniorities);
                    facet_group('Tip normă',    $work_type_options, 'work_type',    $work_types);
                    facet_group('Angajator',    $emp_cat_options,   'employer_cat', $emp_cats);
                },
                'Unele etichete vin direct din anunț, altele sunt deduse automat din text; nu sunt echivalente.', true);

            facet_parent('diagnostics', 'Diagnostic date', ['anomaly', 'schema'],
                function () use ($anomaly_options, $anomaly_flags, $schema_options, $schema_filter) {
                    facet_group('Anomalii', $anomaly_options, 'anomaly', $anomaly_flags);
                    facet_group('Descriere', $schema_options, 'schema', $schema_filter ? [$schema_filter] : []);
                }, '', true);
        });
        ?>

        <?php if ($active_chips): ?>
          <a href="/" class="mt-2 block py-1 text-center text-xs text-gov hover:underline">✕ Șterge filtrele</a>
        <?php endif; ?>
</div>
