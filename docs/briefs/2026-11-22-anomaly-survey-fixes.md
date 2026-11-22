# Brief: fix two survey checks (ANOMALY-SURVEY, Step 1b)

Date: 2026-11-22. Author: Opus. Builder: Sonnet.
Files: `ops/anomaly-survey.py`, `webapp/tests/test_anomaly_survey.py` only.

## Problem (VPS, live export of 2026-11-22)

- **E06, declared pay against the grid: 27 of 33.** The check fires on every declared range that is
  wider than the grid row. Example `lacatus-mecanic-15`: declared 4325–5939 lei (the text says
  "în funcție de vechime"), grid 4325–4428. This is a seniority spread, not an anomaly.
- **E03, repost chain: 63.** Some pairs are two parallel posts on the same day (`administrator-24`
  and `administrator-25`, Municipiul Alexandria, both 2026-11-05). A repost needs a time gap.

## Decision (Opus)

- **E06:** compare the declared **minimum** with the grid minimum only. A hit is a declared
  minimum more than 20% below the grid minimum, or more than 20% above the grid **maximum**.
  Keep the exclusions for hourly rates and pay below the minimum wage. Report in `extra` how
  many declared ranges are wider than the grid (context, not a hit).
- **E03:** a pair is a repost only when the second posting is published at least 14 days after
  the first. Report pairs under 14 days in `extra` as parallel posts, not as hits. Make 14 a
  parameter.

## Work

1. Change the two checks as above. Keep the check ids.
2. Update the tests; add one fixture per new rule (a seniority spread is not a hit; a same-day
   pair is not a hit; a pair 20 days apart is a hit).
3. Run the tests and the script on `tmp/261118/grid153/posturi-after2.sqlite`. Report the
   E03 and E06 counts before and after.
4. Add a short activity-log entry. Commit only your files.

## Stop and ask

- Before you change another check, or a file outside the two above.
