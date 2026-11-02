/*
 * UX-EMP-01 — the content of the anunț, the payload, and the .docx file.
 *
 * Three parts, none of which touches the page:
 *   sections(draft, data)  the anunț as neutral blocks; the preview and the .docx render the same blocks
 *   buildPayload(data)     the ADR 0002 object
 *   buildDocx(draft, data) docx.js (static/vendor/docx.min.js) + the Custom XML part and the custom
 *                          property of ADR 0002
 *
 * `data` is v5 data (schema_models.JobPostingExtractionV5), already pruned by employer-builder.js.
 * `draft.meta` holds what v5 has no field for: the institution and the department.
 *
 * docx.js 9.11.0 writes no Custom XML part. `Packer.compiler.compile(doc, false, [])` returns the
 * JSZip object before compression (an internal API). This file adds the parts. A docx.js upgrade
 * must pass tests/browser/employer_builder.spec.js.
 */
(function () {
  'use strict';

  var FORMAT = 'posturi.posting.v1';
  var NS = 'https://posturi.gov2.ro/ns/posting/v1';
  var CODE_VERSION = '2026-11-02';
  var DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

  // Same list as DOCUMENT_CODE_LABELS in inc/v5.php. webapp/tests/test_employer_payload.py compares the two.
  var DOCUMENT_LABELS = {
    formular_inscriere: 'Formular de înscriere',
    act_identitate: 'Copia actului de identitate',
    certificat_casatorie: 'Copia certificatului de căsătorie (dacă e cazul)',
    acte_studii: 'Copii ale actelor de studii',
    carnet_munca_adeverinta_vechime: 'Carnetul de muncă sau adeverința de vechime',
    cazier_judiciar: 'Cazier judiciar',
    adeverinta_medicala: 'Adeverință medicală',
    certificat_integritate: 'Certificat de integritate comportamentală',
    cv: 'Curriculum vitae',
    declaratie_conformitate: 'Declarație privind conformitatea copiilor',
    dosar_plic: 'Dosar plic'
  };
  var STUDY_LABELS = {
    generala: 'Studii generale', profesionala: 'Școală profesională', liceala: 'Studii liceale',
    postliceala: 'Studii postliceale', licenta: 'Studii superioare (licență)',
    master: 'Master', doctorat: 'Doctorat'
  };
  var DURATION_LABELS = { nedeterminata: 'Durată nedeterminată', determinata: 'Durată determinată', sezonier: 'Sezonier', proiect: 'Pe durata unui proiect' };
  var SCHEDULE_LABELS = { norma_intreaga: 'Normă întreagă', norma_partiala: 'Normă parțială', schimburi: 'Program în schimburi', tura_noapte: 'Tură de noapte', flexibil: 'Program flexibil' };
  var CHANNEL_LABELS = { sediu: 'la sediul instituției', posta: 'prin poștă sau curier', email: 'prin e-mail', online: 'online' };
  var STAGE_LABELS = {
    publicare: 'Publicarea anunțului',
    depunere_dosare: 'Ultima zi de depunere a dosarelor',
    selectie_dosare: 'Selecția dosarelor',
    rezultate_selectie_dosare: 'Rezultatele selecției dosarelor',
    contestatii_selectie_dosare: 'Contestații la selecția dosarelor',
    rezultate_contestatii_selectie_dosare: 'Rezultatele contestațiilor la selecție',
    proba_scrisa: 'Proba scrisă / practică',
    rezultate_proba_scrisa: 'Rezultatele probei scrise / practice',
    contestatii_proba_scrisa: 'Contestații la proba scrisă / practică',
    rezultate_contestatii_proba_scrisa: 'Rezultatele contestațiilor la proba scrisă / practică',
    interviu: 'Interviul',
    rezultate_interviu: 'Rezultatele interviului',
    contestatii_interviu: 'Contestații la interviu',
    rezultate_contestatii_interviu: 'Rezultatele contestațiilor la interviu',
    rezultate_finale: 'Rezultatele finale'
  };

  // The 42 canonical counties of webapp/apps/jobs/judete.py (COUNTIES). test_employer_payload.py compares them.
  var COUNTIES = ['Alba', 'Arad', 'Argeș', 'Bacău', 'Bihor', 'Bistrița-Năsăud', 'Botoșani', 'Brăila', 'Brașov', 'București', 'Buzău',
    'Călărași', 'Caraș-Severin', 'Cluj', 'Constanța', 'Covasna', 'Dâmbovița', 'Dolj', 'Galați', 'Giurgiu', 'Gorj', 'Harghita',
    'Hunedoara', 'Ialomița', 'Iași', 'Ilfov', 'Maramureș', 'Mehedinți', 'Mureș', 'Neamț', 'Olt', 'Prahova', 'Sălaj', 'Satu Mare',
    'Sibiu', 'Suceava', 'Teleorman', 'Timiș', 'Tulcea', 'Vâlcea', 'Vaslui', 'Vrancea'];

  function isFilled(v) {
    if (v === null || v === undefined) return false;
    if (typeof v === 'string') return v.trim() !== '';
    return true;
  }

  function dmy(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
    return m ? m[3] + '.' + m[2] + '.' + m[1] : (iso || '');
  }

  function bulletLines(s) {
    return String(s || '').split('\n').map(function (l) { return l.replace(/^\s*[-•*]\s*/, '').trim(); }).filter(Boolean);
  }

  function money(n) {
    return Number(n).toLocaleString('ro-RO', { maximumFractionDigits: 2 });
  }

  function slug(s) {
    var out = String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60).replace(/-+$/g, '');
    return out || 'post';
  }

  function today() {
    var d = new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }

  function fileName(data, day) {
    return 'anunt-' + slug(data.occupation_title) + '-' + (day || today()) + '.docx';
  }

  // ---- content blocks ---------------------------------------------------------
  function sections(draft, data) {
    var meta = (draft && draft.meta) || {};
    var inst = meta.institution || {};
    var out = [];
    var title = data.occupation_title || '';
    var status = data.contract && data.contract.post_status;

    out.push({ t: 'h1', text: 'Anunț de concurs' + (title ? ': ' + title : '') });
    if (inst.name) out.push({ t: 'p', text: inst.name + (inst.cui ? ' (CUI ' + inst.cui + ')' : '') });
    var where = [inst.address, inst.locality, inst.county].filter(isFilled).join(', ');
    if (where) out.push({ t: 'p', text: where });

    // art. 18 alin. (2) a)
    var post = [];
    if (title) post.push(['Denumirea postului', title]);
    if (data.vacancies_total) post.push(['Numărul de posturi', String(data.vacancies_total)]);
    if (meta.department) post.push(['Structura', meta.department]);
    if (status) post.push(['Statutul postului', status === 'vacant' ? 'Post vacant' : 'Post temporar vacant']);
    var c = data.contract || {};
    var dur = c.duration ? DURATION_LABELS[c.duration] : '';
    if (dur && c.duration_months) dur += ', ' + c.duration_months + ' luni';
    if (dur) post.push(['Durata contractului', dur]);
    var sch = c.schedule ? SCHEDULE_LABELS[c.schedule] : '';
    if (sch && c.hours_per_week) sch += ', ' + c.hours_per_week + ' ore pe săptămână';
    if (sch) post.push(['Programul de lucru', sch]);
    var place = [data.jobLocation].concat(data.work_localities || []).filter(isFilled).join('; ');
    if (place) post.push(['Locul muncii', place]);
    if (post.length) { out.push({ t: 'h2', text: 'Postul' }); out.push({ t: 'kv', rows: post }); }

    // art. 18 alin. (2) c)
    var req = [];
    var edu = data.education || {};
    var eduParts = [];
    if (edu.minimum_level) eduParts.push(STUDY_LABELS[edu.minimum_level] || edu.minimum_level);
    var fields = (edu.fields_of_study || []).map(function (f) { return f.label_ro; }).filter(isFilled);
    if (fields.length) eduParts.push('domeniul: ' + fields.join(', '));
    if (edu.specialization) eduParts.push('specializarea: ' + edu.specialization);
    if (eduParts.length) req.push(['Studii', eduParts.join('; ')]);
    var exp = data.experience || {};
    if (exp.none_required) req.push(['Experiență', 'Nu se cere experiență']);
    else if (exp.years_minimum !== undefined && exp.years_minimum !== null) {
      req.push(['Experiență', 'Minimum ' + exp.years_minimum + ' ani' + (exp.in_specialty ? ' în specialitate' : '')]);
    }
    var langs = (data.language_list || []).map(function (l) { return l.language + (l.cefr ? ' (' + l.cefr + ')' : ''); });
    if (langs.length) req.push(['Limbi străine', langs.join(', ')]);
    var creds = (data.credentials || []).map(function (k) { return k.label; }).filter(isFilled);
    if (creds.length) req.push(['Atestate și autorizații', creds.join('; ')]);
    if (data.qualifications) req.push(['Alte condiții specifice', data.qualifications]);
    out.push({ t: 'h2', text: 'Condiții de participare' });
    out.push({ t: 'p', text: 'Candidații îndeplinesc condițiile generale de la art. 15 din HG 1336/2022.' });
    if (req.length) out.push({ t: 'kv', rows: req });

    var sal = data.baseSalary;
    if (sal && (isFilled(sal.minValue) || isFilled(sal.maxValue))) {
      var lo = isFilled(sal.minValue) ? sal.minValue : sal.maxValue;
      var hi = isFilled(sal.maxValue) ? sal.maxValue : sal.minValue;
      var txt = (lo === hi ? money(lo) : money(lo) + ' – ' + money(hi)) + ' lei brut pe lună';
      out.push({ t: 'h2', text: 'Salariul' });
      out.push({ t: 'p', text: txt });
    }

    var duties = bulletLines(data.responsibilities);
    if (duties.length) { out.push({ t: 'h2', text: 'Atribuțiile postului' }); out.push({ t: 'ul', items: duties }); }

    // art. 18 alin. (2) b)
    var docs = ((data.documents && data.documents.standard) || []).map(function (k) { return DOCUMENT_LABELS[k] || k; });
    ((data.documents && data.documents.specific) || []).forEach(function (d) { if (d.label) docs.push(d.label); });
    if (docs.length) { out.push({ t: 'h2', text: 'Dosarul de concurs' }); out.push({ t: 'ul', items: docs }); }

    // art. 18 alin. (2) d)
    var bib = data.bibliography_topics || [];
    if (bib.length) { out.push({ t: 'h2', text: 'Bibliografie și tematică' }); out.push({ t: 'ul', items: bib }); }

    var ap = [];
    var ch = (data.application && data.application.channels) || [];
    if (ch.length) ap.push(['Dosarul se depune', ch.map(function (k) { return CHANNEL_LABELS[k] || k; }).join(', ')]);
    var ct = data.application_contact || {};
    if (ct.address) ap.push(['Adresa', ct.address]);
    if (ct.email) ap.push(['E-mail', ct.email]);
    if (ct.phone) ap.push(['Telefon', ct.phone]);
    if (ct.name) ap.push(['Persoană de contact', ct.name]);
    var fee = data.application_fee;
    if (fee && (isFilled(fee.amount) || isFilled(fee.details))) {
      ap.push(['Taxa de participare', (isFilled(fee.amount) ? money(fee.amount) + ' lei' : '') + (fee.details ? (isFilled(fee.amount) ? '. ' : '') + fee.details : '')]);
    }
    if (ap.length) { out.push({ t: 'h2', text: 'Depunerea dosarului' }); out.push({ t: 'kv', rows: ap }); }

    // art. 18 alin. (2) e)
    var ev = ((data.competition_calendar && data.competition_calendar.events) || []).filter(function (e) { return e.date; });
    if (ev.length) {
      out.push({ t: 'h2', text: 'Calendarul concursului' });
      out.push({
        t: 'table', head: ['Etapa', 'Data', 'Ora'],
        rows: ev.map(function (e) { return [STAGE_LABELS[e.stage] || e.verbatim || e.stage, dmy(e.date), e.time || '']; })
      });
    }
    return out;
  }

  // ---- payload (ADR 0002) -----------------------------------------------------
  /** The `employer` block of ADR 0002 from draft.meta, or undefined when it is empty. */
  function employerBlock(meta) {
    meta = meta || {};
    var inst = meta.institution || {};
    var out = {};
    function put(k, v) { v = typeof v === 'string' ? v.trim() : ''; if (v) out[k] = v; }
    put('name', inst.name);
    // cui: digits only. "RO 4562583" and "RO4562583" become "4562583".
    put('cui', String(inst.cui || '').replace(/^\s*RO/i, '').replace(/\D/g, ''));
    put('address', inst.address);
    put('locality', inst.locality);
    if (COUNTIES.indexOf(inst.county) >= 0) out.county = inst.county;
    put('department', meta.department);
    return Object.keys(out).length ? out : undefined;
  }

  function fold(t) {
    return String(t || '').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  }

  /** `gradul I A` -> `gradul IA`: the compact spelling of a grade in a title. */
  function gradeText(token) {
    return String(token || '').replace(/\s([IVX]+) A$/, ' $1A');
  }

  /** The title that the dialog composes: the label, then the grade. */
  function composeTitle(label, grade) {
    return grade ? label + ', ' + gradeText(grade) : label;
  }

  /**
   * The `occupation` block of ADR 0002, or undefined. It needs a pick (meta.occupation) with a COR code,
   * and a title that still starts with the label: an employer who replaced the text replaced the occupation.
   */
  function occupationBlock(meta, data) {
    var o = (meta || {}).occupation;
    if (!o || typeof o.label !== 'string' || !o.label || typeof o.cor !== 'string' || !o.cor) return undefined;
    var title = fold((data || {}).occupation_title);
    var label = fold(o.label);
    // fold() turns every comma and dash into a space, so the label ends at a word boundary when
    // the title ends there or the next character is a space.
    if (!label || title.indexOf(label) !== 0) return undefined;
    if (title.length > label.length && title.charAt(label.length) !== ' ') return undefined;
    var out = { cor_code: o.cor, label: o.label };
    if (typeof o.grade === 'string' && o.grade) out.grade = o.grade;
    return out;
  }

  function buildPayload(data, created, employer, occupation) {
    var p = {
      format: FORMAT,
      schema: 'v5',
      generator: 'posturi.gov2.ro/pentru-angajatori/anunt@' + CODE_VERSION,
      created: created || new Date().toISOString().replace(/\.\d+Z$/, 'Z')
    };
    if (employer) p.employer = employer;
    if (occupation) p.occupation = occupation;
    p.data = data;
    return p;
  }

  function sha256Hex(str) {
    var bytes = new TextEncoder().encode(str);
    return crypto.subtle.digest('SHA-256', bytes).then(function (buf) {
      return Array.prototype.map.call(new Uint8Array(buf), function (b) { return (b < 16 ? '0' : '') + b.toString(16); }).join('');
    });
  }

  function xmlEscape(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // ---- .docx ------------------------------------------------------------------
  function docxBlocks(blocks) {
    var d = window.docx;
    var kids = [];
    blocks.forEach(function (b) {
      if (b.t === 'h1') kids.push(new d.Paragraph({ heading: d.HeadingLevel.HEADING_1, children: [new d.TextRun(b.text)] }));
      else if (b.t === 'h2') kids.push(new d.Paragraph({ heading: d.HeadingLevel.HEADING_2, spacing: { before: 240 }, children: [new d.TextRun(b.text)] }));
      else if (b.t === 'p') kids.push(new d.Paragraph({ children: [new d.TextRun(b.text)] }));
      else if (b.t === 'kv') {
        b.rows.forEach(function (r) {
          kids.push(new d.Paragraph({ children: [new d.TextRun({ text: r[0] + ': ', bold: true }), new d.TextRun(r[1])] }));
        });
      } else if (b.t === 'ul') {
        b.items.forEach(function (it) { kids.push(new d.Paragraph({ bullet: { level: 0 }, children: [new d.TextRun(it)] })); });
      } else if (b.t === 'table') {
        var cell = function (text, bold) {
          return new d.TableCell({ children: [new d.Paragraph({ children: [new d.TextRun({ text: text, bold: !!bold })] })] });
        };
        var rows = [new d.TableRow({ tableHeader: true, children: b.head.map(function (h) { return cell(h, true); }) })];
        b.rows.forEach(function (r) { rows.push(new d.TableRow({ children: r.map(function (t) { return cell(t, false); }) })); });
        kids.push(new d.Table({ width: { size: 100, type: d.WidthType.PERCENTAGE }, rows: rows }));
      }
    });
    kids.push(new d.Paragraph({
      spacing: { before: 360 },
      children: [new d.TextRun({ text: 'Document întocmit cu instrumentul neoficial posturi.gov2.ro/pentru-angajatori/anunt/.', italics: true, size: 18 })]
    }));
    return kids;
  }

  function addCustomXml(zip, payloadJson) {
    var itemId = '{3F7B8E2A-5C41-4D6E-9A10-7E5D2B4C8F11}';
    zip.file('customXml/item1.xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<posting xmlns="' + NS + '">' + xmlEscape(payloadJson) + '</posting>');
    zip.file('customXml/itemProps1.xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<ds:datastoreItem ds:itemID="' + itemId +
      '" xmlns:ds="http://schemas.openxmlformats.org/officeDocument/2006/customXml"><ds:schemaRefs><ds:schemaRef ds:uri="' + NS + '"/></ds:schemaRefs></ds:datastoreItem>');
    zip.file('customXml/_rels/item1.xml.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXmlProps" Target="itemProps1.xml"/></Relationships>');

    return Promise.all([
      zip.file('word/_rels/document.xml.rels').async('string'),
      zip.file('[Content_Types].xml').async('string')
    ]).then(function (parts) {
      var rels = parts[0], ct = parts[1];
      if (rels.indexOf('</Relationships>') < 0 || ct.indexOf('</Types>') < 0) throw new Error('unexpected docx package layout');
      rels = rels.replace('</Relationships>',
        '<Relationship Id="rIdPosturiCustomXml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml" Target="../customXml/item1.xml"/></Relationships>');
      var add = '<Override PartName="/customXml/itemProps1.xml" ContentType="application/vnd.openxmlformats-officedocument.customXmlProperties+xml"/>';
      if (!/<Default[^>]*Extension="xml"/.test(ct)) add = '<Default Extension="xml" ContentType="application/xml"/>' + add;
      ct = ct.replace('</Types>', add + '</Types>');
      zip.file('word/_rels/document.xml.rels', rels);
      zip.file('[Content_Types].xml', ct);
      return zip;
    });
  }

  /** Resolves { blob, payload, fileName }. */
  function buildDocx(draft, data) {
    var d = window.docx;
    if (!d || !d.Packer || !d.Packer.compiler || typeof d.Packer.compiler.compile !== 'function') {
      return Promise.reject(new Error('docx.js is not loaded'));
    }
    var payload = buildPayload(data, null, employerBlock(draft.meta), occupationBlock(draft.meta, data));
    var json = JSON.stringify(payload);
    return sha256Hex(json).then(function (hash) {
      var doc = new d.Document({
        creator: 'posturi.gov2.ro',
        title: data.occupation_title || 'Anunț',
        customProperties: [{ name: 'posturi.posting', value: FORMAT + ';sha256=' + hash }],
        sections: [{ children: docxBlocks(sections(draft, data)) }]
      });
      var zip = d.Packer.compiler.compile(doc, false, []);
      return addCustomXml(zip, json);
    }).then(function (zip) {
      return zip.generateAsync({ type: 'blob', mimeType: DOCX_MIME, compression: 'DEFLATE' });
    }).then(function (blob) {
      return { blob: blob, payload: payload, fileName: fileName(data) };
    });
  }

  window.EmployerDoc = {
    FORMAT: FORMAT, NS: NS,
    DOCUMENT_LABELS: DOCUMENT_LABELS, STUDY_LABELS: STUDY_LABELS, DURATION_LABELS: DURATION_LABELS,
    SCHEDULE_LABELS: SCHEDULE_LABELS, CHANNEL_LABELS: CHANNEL_LABELS, STAGE_LABELS: STAGE_LABELS,
    COUNTIES: COUNTIES, employerBlock: employerBlock, occupationBlock: occupationBlock, composeTitle: composeTitle, gradeText: gradeText, fold: fold, sections: sections, buildPayload: buildPayload, buildDocx: buildDocx,
    sha256Hex: sha256Hex, slug: slug, fileName: fileName, dmy: dmy, today: today, bulletLines: bulletLines
  };
})();
