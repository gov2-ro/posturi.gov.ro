# /quality-review

Review the data quality report for the posturi.gov.ro pipeline and produce a
qualitative narrative assessment, going beyond the automated scores.

## Steps

### 1. Load the report

Read `data/quality_report.json`. If it does not exist, tell the user to run
`python quality_check.py --no-llm` first to generate it.

Extract:
- `aggregate` stats (avg completeness, duplication rate, schema valid rate)
- The full `postings` array

### 2. Identify the interesting cases

Flag postings in these categories (a posting can appear in multiple):
- **Worst CSV completeness** — lowest `csv.completeness_score`
- **Attachment problems** — `attachment.readability` is `garbled`, `empty`, or `missing_file`
- **Low infer confidence** — `inferred.profession_family_confidence` < 0.4
- **Schema failures** — `schema.valid` is false or schema was skipped
- **Body duplication** — `csv.body_duplication` is true
- **Anomaly flags** — `inferred.anomaly_flags` is non-empty

### 3. Deep-read each flagged posting

For each flagged posting (up to 5 total, prioritising the worst):

a. Read the raw CSV row from `data/anunturi/anunturi.csv` (match on Source URL)
b. If the posting has an attachment file listed, read or excerpt its text from
   `data/downloads/<filename>` using the Read tool
c. If `data/schema/<slug>.json` exists, read it
d. Look at the `inferred` block in the report for that posting

### 4. Write the qualitative assessment

For each flagged posting, produce:

```
## [slug] — [title] @ [employer], [location]

**Issues found:** [list]

**Root cause analysis:**
[explain WHY the score is low — is the body thin? is the attachment garbled?
did the profession family mismatch? did the LLM fail to populate a required field?]

**What the LLM schema got right / wrong:**
[cite specific fields from the schema JSON, compare to the raw CSV data]

**Recommended fix:**
[concrete action — e.g. "fix HTML parser for repeated blocks", "add docx2txt fallback",
"expand FAMILIES dict with keyword X", "improve schema prompt to always include validThrough"]
```

### 5. Aggregate summary

After the individual posting reviews, write a short aggregate section:

- Overall data health verdict (good / needs attention / poor)
- Top 2–3 systemic issues (patterns seen across multiple postings)
- Suggested next steps in priority order

### 6. Offer Playwright visual verification (optional)

If any finding suggests the issue might be a **scraping error** (e.g. missing
fields that look like they should be on the page, body that seems truncated),
offer:

> "Want me to open the source page for [posting title] in a browser to verify
> what the live page shows? (Uses Playwright)"

If the user says yes, use the Playwright browser tools to navigate to the
`source_url` from the report, take a screenshot, and compare what's visible
on the page to what was scraped.

## Notes

- Be specific and cite actual values from the report and source files — avoid
  vague generalities like "the data quality is poor"
- If schema.org JSON is present, check that `hiringOrganization.name` matches
  the `Employer` field in the CSV — mismatches reveal LLM hallucination
- Body duplication is a known parsing artifact from the HTML structure; flag it
  but note it is tracked in `docs/backlog.md` and should be fixed in
  `parse-anunturi.py`, not the LLM prompt
- If the report was generated with `--no-llm`, schema results will be null —
  note this and offer to run the full LLM pass for specific postings
