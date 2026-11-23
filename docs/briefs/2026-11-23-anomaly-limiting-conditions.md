# Brief — anomaly checks for limiting conditions (R21–R25)

Date: 2026-11-23. Author: Opus. Builder: Sonnet. Owner decision: D3 of `docs/audits/2026-11-23-eu-rules-pcidif.md` (yes, 2026-11-23).
Backlog: ANOMALY-PUBLIC (phase 2 input).

## Goal

Add five checks to `ops/anomaly-survey.py` and to the register `ops/anomaly-checks.json`. Each check finds a condition in the posting text that narrows the field of candidates. Each is a signal, not a verdict: no act that we read forbids R21–R24 (audit § 5, „Verified limits”).

## Read first

- `docs/audits/2026-11-23-eu-rules-pcidif.md` § 2, § 4, § 5.
- `docs/specs/2026-11-23-anomaly-public.md` (kinds, states, the wording rule: a signal, not an accusation).
- `ops/anomaly-survey.py`: the `@check` registry, `Ctx`, R13 and E05.
- `webapp/tests/test_anomaly_survey.py`: one small fixture per check.

## The checks

The text is `posting_text.body_markdown`. Load it in `Ctx` once, lazily, like `events()`. A check that finds no `posting_text` table reports `unavailable` (the existing `needs` mechanism).

| Id | Signal | Rule | Kind | State |
|---|---|---|---|---|
| R21 | Advantage for domicile | `avantaj[^.\n;]{0,15}domicili` (case-insensitive) | `unusual` | `listed` |
| R22 | Advantage for work in a named project | `avantaj[^.\n;]{0,80}(proiect|MySMIS)` (case-insensitive) | `unusual` | `listed` |
| R23 | Experience in EU-funded projects, required or an advantage | `experien[țţt][aă][^.\n]{0,80}proiect[e]?[^.\n]{0,40}fonduri (europene|nerambursabile|structurale)` | `unusual` | `listed` |
| R24 | Several conditions above the occupation | see below | `unusual` | `hidden` |
| R25 | Maximum age or a required sex | see below | `error_posting` | `hidden` |

- **R21–R23.** One finding per posting. `value` = the matched text (at most 120 characters). `peer` = empty.
- **R24.** Spike inside the build. Count the postings of E05 that a rule "years ≥ 2 × the occ_group median **and** a language level ≥ C1 **or** a named credential" also finds. If more than half of R24 findings are E05 findings, do not add R24: report the overlap and stop. Otherwise add it as `hidden`.
- **R25.** Age: `v[âa]rst[aă] (maxim[aă]|de (cel mult|p[âa]n[aă] la))\s*\d` and `nu (mai )?(mare|peste) de \d{2} (de )?ani`. Sex: `(sexul|de sex) (masculin|feminin)`. Exclude „vârsta minimă” and the HG 1336/2022 art. 15 general conditions. `state: hidden`: a person reviews every hit before it shows (some are legal, for example military posts).

`explain_ro` text, one per check, in the style of the register. Draft for R21: „Anunțul acordă un avantaj candidaților cu domiciliul în localitate. Legea permite criteriul, dar anunțul nu spune cum se punctează. Comparație: anunțurile aceleiași ocupații fără acest criteriu.” Write R22–R25 the same way; the owner reviews the text.

## Measure and report

Run the survey on `data/_dev/full-2026-11-23.sqlite`. Report per check: count, denominator, 5 sample URLs, and the share among project hires (`project_hire = 1`) and among other postings. Expected order: R21 ≈ 244+, R22 ≈ 224+, R23 ≈ 23, R25 ≈ 24 (audit § 2). Read 10 hits of each by hand and report the false positives.

## Out of scope; stop and ask

- No change to existing checks, the kinds or the states of other checks.
- No `published` state (only the owner sets it).
- No change to the webapp pages. The admin page reads the register.
- Stop and ask if a check needs a new column in the export.

## Done when

- Tests: one fixture per new check (a hit and a near miss), whole `test_anomaly_survey.py` passes.
- Numbers and false positives in `docs/activity-log.md`; ANOMALY-PUBLIC note updated in `docs/backlog.md`.
