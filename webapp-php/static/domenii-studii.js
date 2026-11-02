/*
 * UX-EMP-03 — suggestions for „Domeniul sau specializarea” in the employer posting assistant.
 * Each entry is { label_ro, isced_field } and follows schema_models.FieldOfStudy. Sorted by label.
 *
 * Source: the local v3/v4 extractions (Postgres posturi_dev, jobs_jobpostingschemavariant,
 * prompt_version in ('v3','v4'), 848 extractions, 1,620 labels, 319 distinct after lower-casing).
 * Labels are lower-cased and the cedilla ş/ţ is repaired to ș/ț before counting.
 *
 * Merged into one label (the counts add up before the threshold):
 *   economie, economic, economice, științe economice      -> științe economice
 *   asistent medical generalist, asistență medicală generalistă,
 *     asistență medicală generală                          -> asistență medicală generală
 *   juridic, juridice, științe juridice                    -> științe juridice
 *   asistenţă socială (cedilla)                            -> asistență socială
 *   administrație                                          -> administrație publică
 *   științe inginerești                                    -> inginerie
 * Not merged on purpose: „asistență medicală” (163 uses) stays its own label; „drept” stays apart from
 * „științe juridice”; „medicină” and „medicină generală” stay apart.
 *
 * Dropped, an occupation and not a field of study: electrician, bucătar (+ bucatar), instalator, moașă.
 * Dropped, too general to help: tehnic, administrativ, sanitare, ocrotire, educație.
 *
 * Kept: labels with at least 3 uses after the merge (58 entries; the limit is 120). Each entry takes the
 * most frequent isced_field of its uses.
 *
 * Later: replace this list with the official nomenclature of the university fields of study.
 */
window.DOMENII_STUDII = [
  {"label_ro": "administrație publică", "isced_field": "04_afaceri_administratie_drept"},
  {"label_ro": "agricultură", "isced_field": "08_agricultura_silvicultura_veterinara"},
  {"label_ro": "agronomie", "isced_field": "08_agricultura_silvicultura_veterinara"},
  {"label_ro": "amenajări și construcții hidrotehnice", "isced_field": "07_inginerie_constructii"},
  {"label_ro": "asistență medicală", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "asistență medicală generală", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "asistență socială", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "biblioteconomie", "isced_field": "02_arte_umanioare"},
  {"label_ro": "biologie", "isced_field": "05_stiinte_naturale_matematica"},
  {"label_ro": "chimie", "isced_field": "05_stiinte_naturale_matematica"},
  {"label_ro": "contabilitate", "isced_field": "04_afaceri_administratie_drept"},
  {"label_ro": "contabilitate și informatică de gestiune", "isced_field": "04_afaceri_administratie_drept"},
  {"label_ro": "drept", "isced_field": "04_afaceri_administratie_drept"},
  {"label_ro": "farmacie", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "finanțe", "isced_field": "04_afaceri_administratie_drept"},
  {"label_ro": "fizică", "isced_field": "05_stiinte_naturale_matematica"},
  {"label_ro": "fizică medicală", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "geografie", "isced_field": "05_stiinte_naturale_matematica"},
  {"label_ro": "horticultură", "isced_field": "08_agricultura_silvicultura_veterinara"},
  {"label_ro": "informatică", "isced_field": "06_tic"},
  {"label_ro": "informatică de gestiune", "isced_field": "06_tic"},
  {"label_ro": "ingineria mediului", "isced_field": "07_inginerie_constructii"},
  {"label_ro": "inginerie", "isced_field": "07_inginerie_constructii"},
  {"label_ro": "inginerie civilă", "isced_field": "07_inginerie_constructii"},
  {"label_ro": "inginerie medicală", "isced_field": "07_inginerie_constructii"},
  {"label_ro": "istoria artei", "isced_field": "02_arte_umanioare"},
  {"label_ro": "istorie", "isced_field": "02_arte_umanioare"},
  {"label_ro": "kinetoterapie", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "management", "isced_field": "04_afaceri_administratie_drept"},
  {"label_ro": "matematică", "isced_field": "05_stiinte_naturale_matematica"},
  {"label_ro": "matematică și științele naturii", "isced_field": "05_stiinte_naturale_matematica"},
  {"label_ro": "mecanică", "isced_field": "07_inginerie_constructii"},
  {"label_ro": "medicină", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "medicină dentară", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "medicină generală", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "medicină veterinară", "isced_field": "08_agricultura_silvicultura_veterinara"},
  {"label_ro": "muzică", "isced_field": "02_arte_umanioare"},
  {"label_ro": "obstetrică-ginecologie", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "pedagogie", "isced_field": "01_educatie"},
  {"label_ro": "pediatrie", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "protecția mediului", "isced_field": "05_stiinte_naturale_matematica"},
  {"label_ro": "psihologie", "isced_field": "03_stiinte_sociale"},
  {"label_ro": "psihopedagogie specială", "isced_field": "01_educatie"},
  {"label_ro": "radiologie", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "radiologie și imagistică medicală", "isced_field": "09_sanatate_asistenta_sociala"},
  {"label_ro": "resurse umane", "isced_field": "04_afaceri_administratie_drept"},
  {"label_ro": "sociologie", "isced_field": "03_stiinte_sociale"},
  {"label_ro": "știința mediului", "isced_field": "05_stiinte_naturale_matematica"},
  {"label_ro": "științe administrative", "isced_field": "04_afaceri_administratie_drept"},
  {"label_ro": "științe ale comunicării", "isced_field": "03_stiinte_sociale"},
  {"label_ro": "științe economice", "isced_field": "04_afaceri_administratie_drept"},
  {"label_ro": "științe juridice", "isced_field": "04_afaceri_administratie_drept"},
  {"label_ro": "științe politice", "isced_field": "03_stiinte_sociale"},
  {"label_ro": "științe sociale", "isced_field": "03_stiinte_sociale"},
  {"label_ro": "științe umaniste și arte", "isced_field": "02_arte_umanioare"},
  {"label_ro": "științele naturii", "isced_field": "05_stiinte_naturale_matematica"},
  {"label_ro": "telecomunicații", "isced_field": "07_inginerie_constructii"},
  {"label_ro": "zootehnie", "isced_field": "08_agricultura_silvicultura_veterinara"}
];
