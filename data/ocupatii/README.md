# Ocupații — reference data

## `cor.csv` — Clasificarea Ocupațiilor din România

The official Romanian occupation classification, 4,422 six-digit occupations.
Used to give every posting a stable occupation code so that titles written 40
different ways (`Îngrijitor` / `ÎNGRIJITOR` / `îngrijitor` / `Îngrijitoare`)
collapse to one facet.

COR is ISCO-08 aligned: digit 1 is the major group, 2 the sub-major, 3 the
minor group and 4 the basic group, so `cod_cor[:4]` **is** the ISCO-08 unit
group and is carried as `isco_grupa_de_baza` (400 distinct). That is what makes
the data comparable outside Romania, and it is derived here rather than asked
of an LLM.

| Column | Meaning |
|---|---|
| `cod_cor` | 6-digit COR code |
| `denumire` | official occupation name |
| `denumire_normalizata` | diacritic- and punctuation-stripped form, the join key |
| `grupa_majora`, `subgrupa_majora`, `grupa_minora` | 1-, 2- and 3-digit prefixes |
| `isco_grupa_de_baza` | 4-digit ISCO-08 unit group |

**Source**: [data.gov.ro, *Clasificarea Ocupațiilor din România — lista
alfabetică*](https://data.gov.ro/dataset/clasificarea-ocupatiilor-din-romania),
2024 edition, retrieved 2026-09-13 from
`https://data.gov.ro/dataset/695974d3-4be3-4bbe-a56a-bb639ad908e2/resource/cc7db3b5-da8a-4eaa-afcc-514dd373eac6/download/isco-08-lista-alfabetica-ocupatii-2024.xml`

The file is a Word document in Flat OPC XML, not XML data — the occupations are
one 4,537-row table inside `/word/document.xml`. 115 rows are vacant codes
(marked `*`, no name) and are excluded.

To refresh when a new edition is published, download the XML and re-run the
extraction: parse the single `w:tbl`, keep rows whose first cell matches
`\d{6}`, second cell is the name.

**Not** the salary grid. The grid's function names (`data/salarii/`) come from
the draft law and are a different, smaller vocabulary; COR is the stable
national classification and outlives the law.
