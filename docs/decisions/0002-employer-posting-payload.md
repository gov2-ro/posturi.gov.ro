# 0002 — Employer posting payload inside the .docx

Date: 2026-11-02. Status: accepted. Applies to UX-EMP-01 (the employer posting builder) and to a later pipeline reader.

## Context

The employer posting builder (`/pentru-angajatori/anunt/`) writes a posting in the shape of our v5 extraction schema. The employer downloads a .docx and publishes it on posturi.gov.ro. They usually upload it as an attachment.

Our pipeline downloads attachments (`download-attachments.py`) and extracts structure with an LLM (`llm-schema.py`). If the .docx carries the structured data, the pipeline can read the data exactly and skip the LLM call. The format must be fixed before the first employer downloads a file. A file that is already in circulation cannot change.

## Decision

### Payload

The payload is one JSON object:

```json
{
  "format": "posturi.posting.v1",
  "schema": "v5",
  "generator": "posturi.gov2.ro/pentru-angajatori/anunt@<code-version>",
  "created": "2026-11-02T10:15:00Z",
  "employer": {
    "name": "Primăria Comunei Ciugud",
    "cui": "4562583",
    "address": "Str. Principală nr. 1",
    "locality": "Ciugud",
    "county": "Alba",
    "department": "Compartimentul urbanism"
  },
  "data": { }
}
```

- `data` must validate against `schema_models.JobPostingExtractionV5`. Only fields that the employer filled are present.
- `employer` identifies the institution. The v5 schema has no field for it, because the pipeline takes the employer from the posturi.gov.ro listing. Each key is optional; an empty key is left out, and an empty block is left out. `cui` is digits only, without `RO`. `county` is one of the 42 canonical names of `webapp/apps/jobs/judete.py`. A reader matches the employer by `cui` first, then by name. (Amended 2026-11-02, pax: an envelope block, not a schema change.)
- `format` changes only for a change that breaks compatibility. A reader rejects a `format` that it does not know.
- `schema` names the extraction model that `data` follows. It is not the same as `format`.

### Carrier

The payload goes in a **Custom XML data part**, the part that Office uses for data stored with a document:

| Part | Content |
|---|---|
| `customXml/item1.xml` | `<posting xmlns="https://posturi.gov2.ro/ns/posting/v1">` + the payload JSON, XML-escaped + `</posting>` |
| `customXml/itemProps1.xml` | `ds:datastoreItem` with a fixed `ds:itemID` and `ds:schemaRef` = the namespace above |
| `customXml/_rels/item1.xml.rels` | relationship `customXmlProps` → `itemProps1.xml` |
| `word/_rels/document.xml.rels` | add relationship `customXml` → `../customXml/item1.xml` |
| `[Content_Types].xml` | add `Override` for `itemProps1.xml` (`customXmlProperties+xml`) and `Default` for `xml` if it is missing |

A reader finds the part by the namespace, not by the file name. Office can rename `item1.xml`.

The file also has one **custom document property**, `posturi.posting` = `posturi.posting.v1;sha256=<hex of the payload JSON bytes>`. The value is shorter than 255 characters. This marker lets a reader identify a builder file cheaply. With the hash, the reader can also check that the Custom XML part was not changed.

### How the builder writes it

docx.js 9.11.0 (vendored, pinned) does not write Custom XML parts. The builder calls `docx.Packer.compiler.compile(doc)`, which returns the JSZip object before compression. Then the builder adds the parts above and patches the two existing files. This is an internal API. The pinned version and a Playwright test that unzips the download protect it. A docx.js upgrade must pass that test.

## Measured (spike, 2026-11-02)

The spike is a docx.js 9.11.0 file with a 4,000-character payload that contains diacritics, „ghilimele”, `&` and `<`. Both carriers were present.

| Round trip | Custom XML part | Custom property (full JSON) |
|---|---|---|
| As generated | intact | intact |
| LibreOffice 26.8 headless, `--convert-to docx` | intact | intact |
| Microsoft Word, open and save | **not measured** (no Word on the Mac) | **not measured** |

Assumption: Word keeps Custom XML parts, because Office was designed to keep them. pax must test this once in Word before the page is linked publicly. If Word drops the part, record the result here and move the payload to the next carrier.

## Consequences

- The payload is **lost when the employer converts the file to PDF**, or copies the text into another document. The page says this next to the download button.
- An employer can edit the visible text after the download. Then the payload and the text disagree. The hash only detects changes to the payload itself. The later pipeline reader must compare a few facts (title, deadline, number of posts) with the visible text. When they disagree, it must fall back to LLM extraction and record the mismatch.
- An extraction that comes from a payload gets the provenance `employer-structured`. It does not get `llm`.
- The payload carries what the employer typed. It is not verified. The site shows it with the same caveats as an extraction.
- Alternative not taken: only the custom property, with the full JSON. It is simpler to write (docx.js `customProperties`). Cost: Word shows text properties of at most 255 characters in its UI, and we have not measured whether Word keeps a longer value on save. A payload of 5–20 KB is normal.
- Alternative not taken: a hidden paragraph or white text in the body. Cost: it is visible to screen readers and copy-paste, and an employer can delete it by accident.
- Alternative not taken: a separate .json file next to the .docx. The builder still offers it. Cost as the only carrier: employers upload one file, not two.
