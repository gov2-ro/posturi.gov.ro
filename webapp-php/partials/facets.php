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
         *
         * `$open` no longer renders `open` (2026-10-16: on desktop every group
         * starts collapsed). It marks the group `data-default-open`, and the
         * script opens those below lg only, where the drawer is a screen of its
         * own. A group holding a selection is still rendered open everywhere.
         */
        function facet_group(string $label, array $items, string $name, array $active, bool $open = false, string $help = ''): void {
            $active = array_values(array_filter(array_map('strval', $active), fn($v) => $v !== ''));
            // An empty group with a live selection (a bookmarked value whose
            // options vanished) is still rendered: its pinned orphan keeps the
            // selection in the form and removable.
            if (!$items && !$active) return;
            // An item may carry its own `param` (a yes/no switch such as shift=1)
            // so one group can merge several parameters (261016: Program de lucru).
            $own_on = count(array_filter($items, fn($i) => isset($i['param']) && !empty($i['on'])));
            $values = array_map(fn($i) => (string)($i['val'] ?? $i['value'] ?? ''),
                                array_filter($items, fn($i) => !isset($i['param'])));
            // 261016: an excludable group carries a second, "fără" switch per value.
            $negatable = isset(NEGATABLE_PARAMS[$name]);
            $not = $negatable ? array_values(array_filter(array_map('strval', (array)($_GET[$name . '_not'] ?? [])),
                                                          fn($v) => trim($v) !== '')) : [];

            // Facets are capped (județ shows the top 25 of ~197 slugs). A selected
            // value outside that window has no checkbox, so the next HTMX submit
            // serialises the form without it and the filter silently disappears.
            // Pin any such value to the top of its group.
            // The count is left blank rather than set to 0: an orphan's real
            // count is simply unknown here (it fell outside the display cap),
            // and a rendered "0" would read as "this selection matches nothing".
            foreach (array_reverse(array_values(array_unique(array_diff(array_merge($active, $not), $values)))) as $orphan) {
                array_unshift($items, [
                    'val'   => $orphan,
                    'label' => filter_value_label($name, $orphan),
                    'cnt'   => '',
                ]);
                $values[] = $orphan;
            }

            $selected = array_merge(array_intersect($active, $values), array_intersect($not, $values));
            for ($k = 0; $k < $own_on; $k++) $selected[] = '';
            $is_open  = (bool)$selected;      // never hide a filter that is switched on
            $mode     = facet_mode($name);
            ?>
            <details class="facet-group mb-1 border-b border-line/60 pb-1" data-facet="<?= e($name) ?>"<?= $selected ? ' data-selected="1"' : '' ?><?= $open ? ' data-default-open' : '' ?><?= $is_open ? ' open' : '' ?>>
              <summary class="flex cursor-pointer list-none items-center justify-between py-2 text-xs font-semibold uppercase tracking-widest text-ink-muted marker:content-none hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                <span><?= e($label) ?><?php if ($selected): ?> <span class="font-mono text-gov normal-case tracking-normal">(<?= count($selected) ?>)</span><?php endif; ?></span>
                <span aria-hidden="true" class="facet-caret text-ink-muted transition-transform">▾</span>
              </summary>
              <?php /* .facet-body: everything under the summary, so the compact
                 layout can lift it into one dropdown panel (assets/app.css). */ ?>
              <div class="facet-body">
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
                    $field = isset($item['param']) ? (string)$item['param'] : param_field($name);
                    $on  = isset($item['param']) ? !empty($item['on']) : in_array($val, array_map('strval', $active), true);
                    // A count of 0 is a dead end: checking it can only empty the
                    // result list. Disable it rather than let it look like any
                    // other option — but never disable a *checked* one, or the
                    // filter could not be taken off again. Some groups (Anomalii)
                    // pass '' for "no count available", which is not a zero.
                    $dead = !$on && $cnt !== '' && (int)$cnt === 0; ?>
                  <?php $neg = $negatable && !isset($item['param']) && in_array($val, $not, true);
                        $dead = $dead && !$neg; ?>
                  <div class="facet-row flex items-center gap-1<?= $neg ? ' facet-row-not' : '' ?>">
                  <label class="group flex min-w-0 flex-1 items-center gap-2 py-1 <?= $dead ? 'cursor-not-allowed opacity-40' : 'cursor-pointer' ?>">
                    <input type="checkbox" name="<?= e($field) ?>" value="<?= e($val) ?>"
                           class="facet-check accent-gov shrink-0"<?= checked_if($on) ?><?= $dead ? ' disabled' : '' ?>>
                    <span class="flex-1 truncate text-sm text-ink <?= $dead ? '' : 'transition-colors group-hover:text-gov' ?>"><?= e($lbl) ?></span>
                    <span class="shrink-0 font-mono text-xs text-ink-muted"><?= $cnt === '' ? '' : $cnt ?></span>
                  </label>
                  <?php if ($negatable && !isset($item['param'])): ?>
                  <?php /* "Fără": excludes the value. A real checkbox (sr-only) inside
                     its own label, so it is one Tab stop and announces its state. */ ?>
                  <label class="facet-not" title="Exclude: <?= e($lbl) ?>">
                    <input type="checkbox" name="<?= e($name) ?>_not[]" value="<?= e($val) ?>" class="sr-only"<?= checked_if($neg) ?><?= $dead ? ' disabled' : '' ?>>
                    <span aria-hidden="true">fără</span><span class="sr-only">Exclude: <?= e($lbl) ?></span>
                  </label>
                  <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              </div>
            </details>
            <?php
        }

        // BETA-FILTERS (D4, UX-01, 261016): every group at the top level, nothing
        // behind "Mai multe filtre", the salary included; overlapping controls
        // merged (see list.php). Parameters that lost their control still filter:
        // they ride along as hidden fields, so the next change keeps them, and
        // their chip removes them.
        foreach (['type' => $types, 'seniority' => $seniorities, 'work_type' => $work_types, 'categorie' => $categories,
                  'anomaly' => $anomaly_flags, 'stage' => $stage_sel, 'eqf' => $eqf_sel] as $param => $vals) {
            foreach ((array)$vals as $v) {
                if ((string)$v === '') continue; ?>
        <input type="hidden" name="<?= e(param_field($param)) ?>" value="<?= e((string)$v) ?>">
        <?php }
        }
        foreach (['schema' => $schema_filter, 'has_salary' => $has_salary, 'computer' => $computer, 'published' => $published] as $param => $v) {
            if ((string)$v === '') continue; ?>
        <input type="hidden" name="<?= e($param) ?>" value="<?= e((string)$v) ?>">
        <?php }

        $auto_help = 'Clasificare automată din textul anunțului; poate fi incompletă. Cerințele valabile sunt cele din anunțul oficial.';

        // Every group starts collapsed on desktop; in the small-screen drawer
        // Județ and Domeniu profesional start open (data-default-open).
        // Order (261016): where, who employs, what job, pay, what it asks, how it
        // runs, then the long tail — skills and documents last, they are mostly fluff.
        facet_group('Județ',               $judet_options,      'judet',      $judet_slugs, true);
        facet_group('Localitate',          $loc_options,        'loc',        $loc_sel);
        facet_group('Instituție',          $inst_options,       'inst',       $inst_sel, false,
                    'Tipul instituției, după numele angajatorului.');
        facet_group('Sector angajator',    $sector_options,     'sector',     $sector_sel);
        facet_group('Nivel angajator',     $emp_cat_options,    'employer_cat', $emp_cats, false,
                    'Eticheta din sursă (posturi.gov.ro): local, județean, național.');
        facet_group('Domeniu profesional', $family_options,     'family',     $families,    true, $auto_help);
        facet_group('Ocupație',            $occupation_options, 'occupation', $occ_sel,     false, $auto_help);
        // Estimated from the 2026 draft grid unless the posting states a salary.
        facet_group('Salariu',             $salary_options,     'salary_bucket', $sal_bucket ? [$sal_bucket] : [], false,
                    'Salariul anunțat, altfel cel estimat din grila de salarizare propusă (brut lunar).');
        facet_group('Nivel de studii',     $studies_options,    'studies_level', $studies_lvls, false,
                    'Nivelul cerut de anunț; nu stabilește dacă vă calificați.');
        facet_group('Domeniu de studii',   $isced_options,      'isced',      $isced_sel);
        facet_group('Experiență minimă',   $exp_options,        'exp_level',  $exp_levels, false,
                    $auto_help . ' Unele intervale includ anunțuri fără experiență precizată.');
        facet_group('Funcție',             $level_options,      'level',      $levels);
        // 261016: the source's "Categorie" (contractuală / publică / militară) is the
        // institution's free pick — muncitori filed as "Funcție publică". Replaced by
        // the regime read off the title's grade and the hiring wording.
        facet_group('Tip funcție',         $regime_options,     'regime',     $regime_sel, false,
                    'Funcție publică: funcționar public (clasă și grad în titlu). Contractuală: contract de muncă. Dedus din textul anunțului.');
        facet_group('Contract',            $duration_options,   'duration',   $duration_sel);
        // 261016: Program, Ture, Telemuncă and Normă parțială were four groups
        // about one question. One group now; each switch keeps its own parameter.
        $program_items = $schedule_options;
        if ($shift_count || $shift) {
            $program_items[] = ['val' => '1', 'param' => 'shift', 'on' => (bool)$shift,
                                'label' => 'Ture, gărzi sau weekend', 'cnt' => $shift_count];
        }
        if ($remote_count || $remote) {
            $program_items[] = ['val' => '1', 'param' => 'remote', 'on' => (bool)$remote,
                                'label' => 'Telemuncă sau hibrid', 'cnt' => $remote_count];
        }
        if ($parttime_count || $parttime) {
            $program_items[] = ['val' => '1', 'param' => 'parttime', 'on' => (bool)$parttime,
                                'label' => 'Fracțiune de normă precizată', 'cnt' => $parttime_count];
        }
        facet_group('Program de lucru',    $program_items,      'schedule',   $schedule_sel);
        // BETA-V5 facts — offered once v5 extractions exist (a v4 row was never asked).
        facet_group('Tip concurs', $recr_options, 'recruitment', $recr_sel, false,
                    'Promovarea este un examen doar pentru angajații instituției.');
        foreach ([['temp_vacant', 'Statutul postului', 'Post temporar vacant', $temp_vacant_count, $temp_vacant],
                  ['remote_apply', 'Depunere', 'Fără deplasare (e-mail, poștă, online)', $remote_apply_count, $remote_apply]]
                 as [$param, $group, $label, $cnt, $on]) {
            if ($cnt || $on) facet_group($group, [['val' => '1', 'label' => $label, 'cnt' => $cnt]], $param, $on ? ['1'] : []);
        }
        facet_group('Finanțare',           $funding_options,    'funding',    $funding_sel);
        facet_group('Domeniu de activitate', $domain_options,   'domain',     $domain_sel);
        facet_group('Limbi străine',       $lang_options,       'lang',       $lang_sel);
        facet_group('Competențe',          $skill_options,      'skill',      $skill_sel);
        facet_group('Documente necesare',  $cred_options,       'credential', $cred_sel);
        $dates_on = $exp_after !== '' || $exp_before !== '';
        ?>
        <details class="facet-group mb-1 border-b border-line/60 pb-1" data-facet="termen"<?= $dates_on ? ' data-selected="1" open' : '' ?>>
          <summary class="flex cursor-pointer list-none items-center justify-between py-2 text-xs font-semibold uppercase tracking-widest text-ink-muted marker:content-none hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus">
            <span>Termen limită</span>
            <span aria-hidden="true" class="facet-caret text-ink-muted transition-transform">▾</span>
          </summary>
          <div class="facet-body">
            <div class="space-y-1.5 pb-2">
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
          </div>
        </details>

        <?php if ($active_chips): ?>
          <a href="/" class="pg-clear mt-2 block py-1 text-center text-xs text-gov hover:underline">✕ Șterge filtrele</a>
        <?php endif; ?>
</div>
