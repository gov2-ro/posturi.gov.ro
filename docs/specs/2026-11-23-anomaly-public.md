# Spec: anomaly signals on the public site (ANOMALY-PUBLIC)

Date: 2026-11-23. Author: Opus. Status: draft, waits for owner decisions (§ 7).

## 1. Goal

Show the anomaly signals on the public site, where the data is. The owner decided this on
2026-11-22. Some signals may be published openly.

An **anomaly** is one of two kinds:

- **Possible error.** A value in the posting or in our data is probably wrong. Example: an
  apply deadline before the publication day, a pay figure that is an hourly rate.
- **Unusual job.** A posting is far from its peers, or an employer behaves unlike its peers.
  Example: 12 years of experience for a role whose group mean is 3; the same post published
  again after 30 days.

## 2. What exists today (measured 2026-11-23)

- `inf_anomaly_flags` holds six flags from `infer_postings._infer_anomaly_flags`. The labels are in
  `ANOMALY_LABELS` (`webapp-php/helpers.php:955`).
- The detail page shows the flags as `note` chips (`pages/detail.php:453`). The chips have no
  explanation.
- `/statistici/` shows one count per flag, with a link `/?anomaly=<flag>`
  (`pages/stats.php:330`).
- The list page accepts `?anomaly=`, but the facet is not on the page (`pages/list.php:221`).
- `ops/anomaly-survey.py` runs 29 checks on an export SQLite. It is read-only and it writes
  JSON and CSV, not the export. Live counts are in the activity log (2026-11-22).

## 3. Design

### 3.1 One table in the export

A new pipeline step runs the survey **after** `export-to-sqlite.py` and **before**
`ops/check-export.py`. It writes one table into the export:

```
anomaly_signals(posting_id, check, kind, value, peer_value, detail_json)
```

- `check` is the survey id (`R07`, `E05`, …). `kind` is `error` or `unusual`.
- The survey stays the only place that computes a signal. The PHP code only reads the table.
- The six existing flags stay as they are. Filter URLs depend on their names (brief rule).
  A later item can move them into the table.

Reason: the survey already runs on the export, in 1.6 s, with no Django. A second
implementation in `infer_postings` would drift from the survey. Cost of the alternative:
two code paths for one check, and the Postgres inference pass must run for every change.

### 3.2 A register of checks

`ops/anomaly-checks.json` lists each check once: id, kind, Romanian label, Romanian
explanation (one or two sentences), method note, and **state**:

| State | Where it shows |
|---|---|
| `hidden` | Nowhere on the site. The survey still computes it. |
| `listed` | On `/anomalii/` only. The page has `noindex` and no link from the main menu. |
| `published` | On `/anomalii/`, as a chip with its explanation on the detail page, and as a filter. |

The owner changes a state. A check moves to `published` only after a person reads a sample
(§ 3.5). The PHP code reads the register; a state change needs a data deploy only.

### 3.3 The page `/anomalii/`

- Two sections: **Posibile erori** and **Posturi neobișnuite**.
- For each check: the label, the explanation, the count and the share, and the list of postings
  (title, employer, date, `value` against `peer_value`, link to the posting).
- Each signal shows the comparison, not a verdict. Example: „12 ani experiență cerută; media
  grupei: 3 ani (n = 140)”.
- A short method note at the top: how a signal is computed, and that a signal is not proof
  of an error or of an irregularity.
- `/statistici/` links to `/anomalii/`.

### 3.4 Romanian wording

- Say what the data shows. Do not judge. Use „semnal”, „neobișnuit”, „posibilă eroare”.
  Do not use „ilegal”, „fraudă”, „post cu dedicație” or „neregulă”.
- A possible error in **our** data says so: „Posibilă eroare în datele noastre”. The site
  does not blame an employer for our parser.
- Every explanation names the comparison group and its size.

### 3.5 Review before publication

- A file `data/anomaly-reviews.csv` (in git) records each review: `check, posting_id,
  verdict (real | false_positive | unclear), reviewer, date, note`.
- A check moves to `published` when a person has read at least 10 samples (or all, if
  fewer) and the false positives are 20% or less. The decision and the numbers go into the
  activity log.
- An **employer-level** check (same employer and title repeated, many postings on one day,
  many short deadlines) names an employer. It stays `listed` until the owner publishes it
  by name, check by check.
- A `false_positive` verdict hides that one signal on the site. The check stays.

### 3.6 Search engines

`/anomalii/` has `noindex` while any shown check is only `listed`. The detail-page chips
show only `published` checks, so the indexed pages hold only reviewed signals.

## 4. Initial states (proposal)

| Check | Kind | Initial state | Reason |
|---|---|---|---|
| R07 deadline before publication | error (ours) | listed | 0 live after DATA-DEADLINE-SANITY; keeps watch |
| R01 `nr_posturi` too large | error (ours) | listed | 13 live; a parser defect, DATA-02 |
| R04 hourly rate read as monthly pay | error (ours) | listed | 0 live |
| E06 declared pay outside the grid | error or unusual | listed | 6 live; 2 of 4 samples are our grid-matching error (SALARY-GRID-MATCH) |
| R12 "debutant" with experience | unusual | listed | 33 of 78; mixed titles, needs review |
| R13, R14 experience far above the group | unusual | listed | 3 and 2 live |
| R19 short publication | unusual | listed | 5 live |
| E01 working days to apply (distribution) | — | hidden | a distribution, not a list; on `/statistici/` later |
| E02 window across a holiday | unusual | listed | 1 live |
| E03 repost chain | unusual, employer | listed | 26 live; names an employer |
| E05 narrow requirements | unusual | hidden | the measures are weak (2026-11-22 samples) |
| R15, R16, R17 employer patterns | unusual, employer | listed | name an employer |
| R18 free-mail contact, R20 many skills | — | hidden | not an anomaly; R20 is a measure of the extraction |
| others | per owner | listed | |

No check starts as `published`. The first review round decides.

## 5. Phases

1. **Table and page (Sonnet).** The pipeline step, `anomaly_signals`, the register,
   `/anomalii/` with `noindex`, the link from `/statistici/`. A `check-export` rule: the
   table exists, and each `published` check has a count (the step limit is a later decision).
2. **Review round (owner or Opus).** Read 10 samples per check, fill `anomaly-reviews.csv`,
   and set the first `published` states.
3. **Chips and filter (Sonnet).** Detail-page chips with the explanation for `published`
   checks; the filter in `build_filters()`. **Tell the session that owns DATA-STUDIES-NOT
   before this phase changes `build_filters()`.**

## 6. Out of scope

- A new public flag computed in `infer_postings`.
- An email alert for anomalies.
- A change to the six existing flags or their names.

## 7. Decisions for the owner

1. Do we show possible errors in **our** data on the public page (§ 3.4), or fix them silently?
   Proposal: show them as „posibilă eroare în datele noastre”. It is honest, and it shows what
   is still to fix.
2. Is the threshold for `published` right: 10 samples and 20% or fewer false positives?
3. Is the route `/anomalii/` right? Alternative: a section of `/statistici/`.
4. Is `noindex` right for the start?
