# Brief — Spike S2: transparency lists, yield and cost

Date: 2026-11-18. Author: Opus. Builder: Sonnet. ADR: `docs/decisions/0008-salary-two-baselines.md` (proposed, rule 4b). Plan: salary estimator, round 2.

## Goal

Each public institution must publish its pay by function by 31 March and 30 September (Legea 153/2017, Art. 33). That list gives the real base pay at the employer of a posting. Measure how many active postings this source can cover, and at what cost. This is a spike: write a report, not production code.

## Steps

1. **The employers.** From `webapp-php/posturi.sqlite` (the active export), list the 30 employers with the most active postings, and the share of all active postings that they hold. Record the SQL that you used.
2. **Find the lists.** For each employer, find the list of 30 September 2026. If it is not available, use the list of 31 March 2026. Start from the site of the employer (sections like „Transparență venituri salariale”, „Informații de interes public”, „Salarizare”). Record:
   - the URL of the page and of the file;
   - the format (PDF with text, scanned PDF, XLSX, DOCX, HTML);
   - the period;
   - the time that you needed to find it (approximately);
   - „negăsit” if you did not find it in 10 minutes.
3. **Parse a sample.** For 10 lists in different formats, try to extract the rows `functie, grad/treapta, nivel studii, salariu_baza, sporuri`. Use `pdftotext -layout`, `openpyxl` or `python-docx`, as the format needs. Record whether the parse works without manual help.
4. **Match.** For the employers of step 3, match the titles of their active postings to the rows of the list. Use `ocupatii.parse_title()` for the title. Count exact matches, matches after normalisation, and no match.
5. **Compare.** For each matched posting, compare the base pay of the list with the current estimate of the site (the draft grid). Give the gap in %.

## Report

Write `docs/audits/2026-11-18-salary-s2-transparency-lists.md`:

- The table of step 2.
- The % of active postings that the lists of these 30 employers cover (found × parsed × matched).
- The cost to reach 80% of the active postings: the number of employers, and the share of them that need manual help.
- How often the lists change their URL or their format between March and September, if you can see both.
- A recommendation: build Phase 5 (a fetch and parse pipeline), do it by hand for the top N employers, or drop it. Say what you measured and what you assumed.

Add an entry to `docs/activity-log.md` (Done / Hard / Decided), and add the follow-up items to `docs/backlog.md`. If you recommend to drop it, add it under *Verified limits* with the reason.

## Constraints

- Change no code and no file in `data/`. Save the downloads in `tmp/261118/s2/`. Do not commit that folder.
- Stop and ask if a step would change a spec, an ADR or a file that this brief does not name.
- Use `random.uniform(0.5, 1.1)` seconds between requests to one site. Do not download more than 3 files from one site.
- The lists name people only in rare cases. If a list has names, do not copy the names into the report.
- Time box: one session.
