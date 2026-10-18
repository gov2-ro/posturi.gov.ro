"""Deterministic labels read off a posting's own words (261016 filter notes).

Four questions the source labels or the LLM answered badly:

* **work mode** — the old `inf_remote_eligible` regex matched "remote" and
  "hibrid" anywhere, so "Remote Sensing of Environment" (a bibliography),
  "hibridizare" (a lab technique) and "Legea nr. 81/2018 privind reglementarea
  activității de telemuncă" (a law in the reading list) all made a posting
  "remote". Here a phrase must describe how *this* job is done.
* **funding** — the v4 field is nearly always empty or "nespecificat", while
  the text says "cod SMIS 123456" or "PNRR" outright.
* **regime** (funcție publică / contractuală / militară) — the source's
  `categorie` is whatever the institution picked: muncitori, îngrijitori and
  asistenți medicali appear as "Funcție publică". The grade in the title
  (clasa I, grad profesional superior …) and the legal phrasing in the body are
  reliable; the free choice is not.
* **employer kind** — the institution type from its name: Primărie, Spital,
  Școală, Minister …, which the source's category mixes with the regime.

Stdlib only: imported by export-to-sqlite.py (outside Django) and by the
infer step. Every function takes plain text and returns '' when unsure.
"""
from __future__ import annotations

import re
import unicodedata


def fold(text) -> str:
    """Lower-case, no diacritics (both cedilla and comma forms), single spaces."""
    s = unicodedata.normalize("NFD", str(text or ""))
    s = "".join(c for c in s if unicodedata.category(c) != "Mn").lower()
    s = s.replace("ş", "s").replace("ţ", "t").replace("ș", "s").replace("ț", "t")
    return re.sub(r"\s+", " ", s)


# ---- Work mode --------------------------------------------------------------

# The law on telework is read, not worked under: drop it before matching.
_TELEWORK_LAW = re.compile(
    r"(legea|lege|l\.)\s*(nr\.?\s*)?81\s*/\s*2018[^.;\n]{0,120}"
    r"|privind\s+reglementarea\s+activitatii\s+de\s+telemunca")
_TELEWORK = re.compile(
    r"\b(regim(ul)?|activitate[a]?|program(ul)?|forma|posibilitate[a]?|disponibilitate[a]?|"
    r"modalitate[a]?|sistem(ul)?|desfasurare[a]?)\s+(de\s+|pentru\s+|in\s+)?(lucru\s+)?(in\s+)?"
    r"(regim\s+(de\s+)?)?telemunc"
    r"|\bin\s+regim\s+(de\s+)?telemunc"
    r"|\b(munca|lucru(l)?|activitate)\s+(de\s+acasa|la\s+domiciliu|la\s+distanta)\b"
    r"|\btelemunca\s+(partiala|integrala|\d+\s+zi|posibila|permisa|acceptata)"
    r"|\b(permite|accepta|ofera|posibilitate\w*\s+de)\s+(si\s+)?(lucru\s+in\s+)?telemunc"
    r"|\b(lucru|munca|activitate)\s+(in\s+)?remote\b"
    r"|\bpentru\s+activitatea\s+de\s+telemunca")
_NOT_REMOTE_DISTANCE = re.compile(r"(comunicare|invatamant|invatare|educatie|formare|cursuri)\s+la\s+distanta")
_HYBRID = re.compile(
    r"\b(regim|program|mod|sistem)\w*\s+de\s+(lucru|munca)\s+(este\s+|va\s+fi\s+|in\s+(format|sistem|regim)\s+)?hibrid"
    r"|\b(munca|lucru(l)?)\s+(in\s+(regim|sistem)\s+)?hibrid|\bregim\s+hibrid|\bprogram(ul)?\s+hibrid"
    r"|\btelemunca\s+(si|cu)\s+(activitate\s+)?la\s+sediu|\bpartial\s+la\s+sediu")


def work_mode(text) -> str:
    """'hibrid', 'telemunca' or '' — only when the text says how the job is done."""
    t = _TELEWORK_LAW.sub(" ", fold(text))
    if _HYBRID.search(t):
        return "hibrid"
    for m in _TELEWORK.finditer(t):
        if not _NOT_REMOTE_DISTANCE.search(t[max(0, m.start() - 25):m.end()]):
            return "telemunca"
    return ""


# ---- Funding ----------------------------------------------------------------

# "transmis" contains "smis": the code needs a word boundary and a number.
_SMIS = re.compile(r"\b(my)?smis\b\s*(cod(ul)?\s*)?(nr\.?\s*)?:?\s*\d{5,6}|\bcod(ul)?\s+(my)?smis\s*:?\s*\d")
_PROGRAMME = (r"(planul\s+national\s+de\s+redresare|\bpnrr\b|mecanismul\s+de\s+redresare|fonduri(le|lor)?\s+europene"
              r"|fonduri(le|lor)?\s+externe\s+nerambursabile|fondul\s+social\s+european|\bfse\+?\b|\bfeder\b"
              r"|uniunea\s+europeana|\b(pocu|poim|pocidif|poids|peo|pts|ptj|pdd|por|interreg|erasmus\+?)\b"
              r"|programul\s+(operational\s+)?(educatie\s+si\s+ocupare|sanatate|incluziune|capital\s+uman"
              r"|tranzitie\s+justa|dezvoltare\s+durabila|crestere\s+inteligenta|regional))")
# The post itself is paid from the programme: "proiect finanțat prin PNRR",
# "în cadrul proiectului … cofinanțat din Fondul Social European". A programme
# merely named (a law title in the reading list, "experiență în proiecte cu
# fonduri europene" among the requirements) does not count.
_FUNDED = re.compile(r"\b(co)?finantat\w*\s+(integral\s+|partial\s+)?(prin|din|de|in\s+cadrul|de\s+catre)\s+(\w+\s+){0,4}?" + _PROGRAMME)
_PROJECT = re.compile(r"\bin\s+cadrul\s+proiectului|\bproiectul(ui)?\s+(cu\s+titlul|intitulat|\")|\bechipa\s+(de\s+implementare\s+a\s+)?proiectului")
_PROGRAMME_RE = re.compile(_PROGRAMME)
_PNRR = re.compile(r"\bpnrr\b|planul\s+national\s+de\s+redresare|mecanismul\s+de\s+redresare")


def eu_funding(text) -> str:
    """'pnrr', 'fonduri_europene' or '' when the text says the post is project-funded."""
    t = fold(text)
    hits = []
    for m in _SMIS.finditer(t):
        hits.append(t[max(0, m.start() - 400):m.end() + 100])
    for m in _FUNDED.finditer(t):
        hits.append(m.group(0))
    for m in _PROJECT.finditer(t):
        window = t[m.start():m.end() + 400]
        if _PROGRAMME_RE.search(window):
            hits.append(window)
    if not hits:
        return ""
    return "pnrr" if any(_PNRR.search(h) for h in hits) else "fonduri_europene"


# ---- Regime: funcție publică / contractuală / militară ----------------------

# Grade words of a public servant: "consilier, clasa I, grad profesional superior".
# Contractual posts have grades too ("asistent medical, grad profesional
# debutant", "referent IA", "treapta II"), so a bare "grad profesional" is not
# enough: the class (I–III) or a public-service title with its grade is.
_CLASS = re.compile(r"\bclasa\s+(i{1,3}|1|2|3)\b(?!.{0,20}?\btr[ae]+pt)")
_PUBLIC_TITLE = re.compile(
    r"\b(consilier|inspector|auditor|expert|referent)\b(?!\s+de\s+specialitate)[^,;()]{0,40}?"
    r"\b(asistent|principal|superior)\b(?!\s+(medical|social|de\s+cercetare|manager))"
    r"|\bfunct\w*\s+publi\w*|\bpolitist(ul)?\s+local|\bsecretar(ul)?\s+general\s+al\s+(comunei|orasului|municipiului|uat)"
    r"|\bsecretar\s+general\b(?=.*\b(primari|comun|oras|municipi))")
_PUBLIC_BODY = re.compile(
    r"\bfunct(ie|iei|ia|iilor|ii)\s+publice?\s+(de\s+)?(executie|conducere|vacant|temporar\s+vacant)"
    r"|\bocupar(ea|ii)\s+(unei\s+|a\s+)?(functiei|functiilor|unor\s+functii)\s+publice"
    r"|\bfunct(ia|iei|iilor)\s+publice?\s+(vacante?\s+)?de\s+(consilier|inspector|referent|auditor|expert|sef|director)"
    r"|\bfunctionar(ilor|i)?\s+publici?\s+debutant"
    r"|\bart\.?\s*465\b")
_CONTRACT = re.compile(
    r"\bpersonal(ului)?\s+contractual\b|\bfunct(ie|iei|ii|iilor|ia)\s+contractual"
    r"|\bpost(ului|uri|urilor)?\s+(vacante?\s+)?(de\s+natura\s+)?contractual"
    r"|\bhg\.?\s*(nr\.?\s*)?(1336\s*/\s*2022|286\s*/\s*2011)|\bhotararea\s+(guvernului\s+)?(nr\.?\s*)?(1336\s*/\s*2022|286\s*/\s*2011)"
    r"|\bcontract\s+individual\s+de\s+munca")
# Cadre militare only. Police officers are public servants with a special
# status, not military; civilians hired by a military unit are contractual.
_MILITARY = re.compile(
    r"\bfunct(ie|ii|ia|iei)\s+militar|\bcadr(e|u)\s+militar|\b(ofiter|subofiter|maistru\s+militar|"
    r"soldat|gradat)\w*\s+(profesionist|activ|in\s+activitate|cu\s+grad)|\bmedicina\s+militara")


def regime(title, body) -> str:
    """'publica', 'militara', 'contractuala' or '' — the job's legal regime.

    The title is the stronger witness (it carries the grade); the body is only
    believed for the regime it names in a hiring phrase, and when it names both
    (a contractual post whose bibliography cites the Codul administrativ) the
    contractual wording wins unless the title carries a public-service grade.
    """
    ti, b = fold(title), fold(body)
    if _MILITARY.search(ti):
        return "militara"
    if _CLASS.search(ti) or _PUBLIC_TITLE.search(ti):
        return "publica"
    contract = bool(_CONTRACT.search(b))
    if _PUBLIC_BODY.search(b) and not contract:
        return "publica"
    if contract:
        return "contractuala"
    if _MILITARY.search(b):
        return "militara"
    return ""


# ---- Employer kind -----------------------------------------------------------

#: (key, label, pattern on the folded employer name). First match wins, so the
#: specific comes before the general: a "Spitalul Militar" is a hospital, a
#: "Școala de Aplicație pentru Forțele Terestre" is military.
EMPLOYER_KINDS: list[tuple[str, str, re.Pattern]] = [
    ("militar", "Unități militare, poliție, penitenciare", re.compile(
        r"\bunitatea\s+militar|\bu\.?\s?m\.?\s+\d|\bministerul\s+apararii|\bstatul\s+major|\bbaza\s+\w+\s+aerian"
        r"|\bscoala\s+de\s+aplicatie|\bacademia\s+(nationala\s+de\s+informatii|fortelor|navala|tehnica\s+militara)"
        r"|\bjandarm|\binspectoratul\s+(general\s+)?(de\s+)?(politie|jandarmi|politia\s+de\s+frontiera|pentru\s+situatii\s+de\s+urgenta)"
        r"|\bisu\b|\bpolitia\b(?!\s+locala)|\bpolitie\b(?!\s+locala)|\bpenitenciar|\badministratia\s+nationala\s+a\s+penitenciarelor"
        r"|\bserviciul\s+(roman\s+de\s+informatii|de\s+protectie\s+si\s+paza|de\s+telecomunicatii\s+speciale)"
        r"|\bcentrul\s+de\s+detentie|\bcentrul\s+educativ|\bdetasament")),
    ("spital", "Spitale și unități sanitare", re.compile(
        r"\bspital|\bsanatoriu|\bpreventoriu|\bserviciul\s+de\s+ambulanta|\bambulanta\b|\bcentrul\s+medical"
        r"|\binstitutul\s+(oncologic|national\s+de\s+(boli|endocrinologie|neurologie|recuperare|gerontologie|sanatate))"
        r"|\binstitutul\s+(clinic|de\s+(boli|pneumoftiziologie|urgenta|psihiatrie|cardiologie|fonoaudiologie))"
        r"|\bpolicl|\bdispensar|\bcabinet|\bcentrul\s+de\s+(sanatate|diagnostic|transfuzie|recoltare)"
        r"|\b(unitatea|centrul)\s+de\s+asistenta\s+medico|\binstitutul\s+(inimii|de\s+fonoaudiologie|national\s+de\s+expertiza)"
        r"|\bifacf\b|\binstitutul\s+marius\s+nasta|\bcentrul\s+national\s+clinic")),
    ("dsp", "Direcții de sănătate publică, DSV, CAS", re.compile(
        r"\bdirectia\s+de\s+sanatate\s+publica|\bdsp\b|\bdirectia\s+sanitar[a]?\s+veterinar|\bdsvsa\b"
        r"|\bcasa\s+(judeteana|de\s+asigurari|nationala\s+de\s+asigurari)")),
    ("asistenta-sociala", "Asistență socială (DGASPC, DAS, cămine)", re.compile(
        r"\bdgaspc\b|\bdirectia\s+generala\s+de\s+asistenta\s+sociala|\basistenta\s+sociala|\bprotectia\s+copilului"
        r"|\bcamin(ul)?\b|\bcentrul\s+(de\s+(ingrijire|recuperare|zi|plasament|primire)|social|rezidential)"
        r"|\bcomplexul\s+de\s+servicii|\bdas\b|\bservicii(lor)?\s+sociale")),
    ("universitate", "Universități", re.compile(r"\buniversitat|\bacademia\s+de\s+studii|\bscoala\s+nationala\s+de\s+studii")),
    ("scoala", "Școli, licee, grădinițe", re.compile(
        r"\bscoal|\bliceu|\bcolegiu|\bgradinit|\bcresa\b|\bcrese\b|\bseminar(ul)?\s+teologic|\bpalatul\s+copiilor"
        r"|\bclubul\s+(copiilor|sportiv\s+scolar)|\bcentrul\s+(scolar|judetean\s+de\s+resurse)|\binspectoratul\s+scolar"
        r"|\bcasa\s+corpului\s+didactic|\bcentrul\s+de\s+excelenta")),
    ("cercetare", "Institute de cercetare", re.compile(
        r"\binstitutul\s+(national\s+de\s+)?(cercetare|de\s+cercetari)|\binstitutul\s+de\s+\w+\s+al\s+academiei"
        r"|\bacademia\s+romana|\bcentrul\s+de\s+cercetar|\bstatiunea\s+de\s+cercetar|\binstitutul\b.*\bacademiei")),
    ("cultura", "Cultură (muzee, teatre, biblioteci)", re.compile(
        r"\bmuzeu|\bteatr|\bbibliotec|\bfilarmonic|\bopera\b|\bansamblu|\bcentrul\s+(judetean\s+)?(de\s+)?cultur"
        r"|\bcasa\s+de\s+cultura|\bcentrul\s+cultural|\bcomplexul\s+muzeal|\barhivele|\bcirc\b|\borchestr")),
    ("minister", "Ministere și Guvern", re.compile(
        r"\bminister|\bguvern|\bsecretariatul\s+general|\bcancelaria|\badministratia\s+prezidentiala"
        r"|\bcamera\s+deputatilor|\bsenatul")),
    ("prefectura", "Prefecturi", re.compile(r"\bprefectur|\binstitutia\s+prefectului")),
    ("consiliu-judetean", "Consilii județene", re.compile(r"\bconsiliul\s+judetean|\bconsiliului\s+judetean")),
    ("servicii-publice", "Servicii publice locale (gospodărie, apă, transport)", re.compile(
        r"\bserviciul\s+public|\bdirectia\s+(de\s+)?(administrare|gospodarire|utilitati|drumuri|parcuri)"
        r"|\bregia\b|\badministratia\s+(domeniului|pietelor|parcurilor|bazinala|strazilor)"
        r"|\bsvsu\b|\bpolitia\s+locala|\btransport|\bapele\s+romane|\bsalubr|\bgospodari|\bpiet")),
    ("sport", "Cluburi și baze sportive", re.compile(
        r"\bclub(ul)?\s+sportiv|\bcomplexul\s+sportiv|\bsport\s+(si|și)\s+(tineret|agrement)|\bagentia\s+nationala\s+pentru\s+sport"
        r"|\baeroclub|\bbaza\s+sportiv|\bcontrol\s+doping")),
    ("companie", "Companii și societăți de stat", re.compile(
        r"\bsocietatea\b|\bcompania\b|\bs\.?\s?a\.?$|\bs\.?\s?r\.?\s?l\b|\bsrl\b|\bregia\s+autonoma|\bregia\b")),
    ("primarie", "Primării și consilii locale", re.compile(
        r"\bprimari|\bconsiliul\s+local|\bcomuna\b|\bunitatea\s+administrativ|\buat\b|^oras(ul)?\b|^municipiul\b"
        r"|\bsector(ul)?\s+[1-6]\b|\bu\.?\s?a\.?\s?t\.?\b|\binstitutia\s+publica\s+(orasul|comuna|municipiul)")),
    ("agentie", "Agenții, autorități, inspectorate", re.compile(
        r"\bagenti|\bautoritat|\binspectorat|\bcomisia\b|\bcurtea\s+de\s+conturi|\boficiul\b|\bregistrul\b"
        r"|\bgarda\s+(nationala\s+)?de\s+mediu|\bconsiliul\s+(national|concurentei|superior)|\badministratia\s+(nationala|fondului)"
        r"|\bcasa\s+nationala\s+de\s+pensii|\bcasa\s+(judeteana|teritoriala|sectoriala)\s+de\s+pensii")),
    ("justitie", "Instanțe și parchete", re.compile(r"\btribunal|\bjudecator|\bcurtea\s+de\s+apel|\bparchet|\binalta\s+curte")),
    ("directie", "Alte direcții publice", re.compile(r"\bdirecti")),
]
EMPLOYER_KIND_LABELS = {k: label for k, label, _ in EMPLOYER_KINDS}


def employer_kind(name) -> str:
    """The institution type for an employer name, or '' (most of the long tail is named)."""
    t = fold(name)
    for key, _label, pattern in EMPLOYER_KINDS:
        if pattern.search(t):
            return key
    return ""


# ---- Occupation group (COR / ISCO-08) ----------------------------------------

#: When the occupation dictionary left a posting without an ISCO code, the
#: export first borrows the code most postings with the same canonical title
#: carry, then falls back to these. A prefix is an honest answer: "Muncitor
#: calificat" is major group 7 and nothing finer. COR shares ISCO-08's codes
#: down to four digits. First match wins.
OCCUPATION_FALLBACK: list[tuple[re.Pattern, str]] = [(re.compile(p), code) for p, code in [
    (r"^muncitor\s+necalificat|^ajutor\s+de\s+bucatar|^spalator", "9"),
    (r"^muncitor", "7"),
    (r"^(sofer|conducator\s+auto)|buldoexcavator|^tractorist|^mecanic\s+utilaj", "83"),
    (r"^ingrijitor\s+(la\s+domiciliu|batrani|bolnavi|persoane|copii)", "532"),
    (r"^ingrijitor|^femeie\s+de\s+serviciu|^personal\s+de\s+curatenie", "91"),
    (r"^infirmier|^brancardier|^baies", "532"),
    (r"^medic\s+veterinar", "225"),
    (r"^medic\s+(stomatolog|dentist)", "2261"),
    (r"^medic|^director\s+medical|^sef\s+sectie", "221"),
    (r"^asistent\s+medical|^moasa|^sora\s+medicala", "222"),
    (r"^farmacist", "2262"),
    (r"^asistent\s+social|^pedagog\s+social", "2635"),
    (r"^psiholog|^consilier\s+psihologic", "2634"),
    (r"^kinetoterapeut|^fizioterapeut|^fiziokinetoterapeut", "2264"),
    (r"^logoped", "2266"),
    (r"^(sub)?inginer", "214"),
    (r"^informatician|^administrator\s+(de\s+)?(sistem|retea)|^analist\s+programator|^programator", "25"),
    (r"^contabil", "3313"),
    (r"^economist|^administrator\s+financiar|^responsabil\s+financiar|^auditor", "241"),
    (r"^cercetator|^asistent\s+de\s+cercetare", "2"),
    (r"^profesor", "23"),
    (r"^educator(?!\s+muzeal)|^educatoare|^invatator", "234"),
    (r"^instructor\s+de\s+educatie|^consilier\s+(scolar|educational)|^mediator\s+scolar", "235"),
    (r"^bucatar", "512"),
    (r"^electrician|^electromecanic", "741"),
    (r"^fochist", "818"),
    (r"^sudor|^lacatus", "721"),
    (r"^instalator", "712"),
    (r"^zidar|^dulgher|^tamplar|^zugrav", "71"),
    (r"^mecanic", "723"),
    (r"^(paznic|agent\s+de\s+paza|guard|politist\s+local)", "541"),
    (r"^portar", "9629"),
    (r"^secretar\s*-?\s*sef|^secretar\s+(institutie|unitate)", "334"),
    (r"^secretar", "412"),
    (r"^bibliotecar", "2622"),
    (r"^(muzeograf|restaurator|educator\s+muzeal|conservator)", "2621"),
    (r"^consilier\s+juridic|^jurist", "261"),
    (r"^(laborant|tehnician)", "3"),
    (r"^magaziner|^gestionar", "432"),
    (r"^casier", "421"),
    (r"^registrator\s+medical|^functionar|^arhivar", "4"),
    (r"^(director|sef\b|coordonator\s+(centru|serviciu)|manager)", "1"),
    (r"^(inspector|referent|consilier|expert)", "242"),
    (r"^administrator(\s+de)?\s+patrimoniu|^administrator$", "33"),
]]


def occupation_fallback(*names) -> str:
    """An ISCO prefix for the first name (canonical title, then the raw title) a rule knows."""
    for name in names:
        t = fold(name).strip(" -–—(")
        if not t:
            continue
        for pattern, code in OCCUPATION_FALLBACK:
            if pattern.search(t):
                return code
    return ""


# ---- Medical specialty ---------------------------------------------------------

#: The specialties as the titles write them ("Medic specialist ATI",
#: "medic primar specialitatea radiologie-imagistică medicală"). Label, pattern.
MEDICAL_SPECIALTIES: list[tuple[str, re.Pattern]] = [(label, re.compile(p)) for label, p in [
    ("Medicină de familie", r"medicin\w*\s+de\s+familie"),
    ("Medicină generală", r"medicin\w*\s+general"),
    ("Medicină internă", r"medicin\w*\s+interne?"),
    ("Medicină de urgență", r"medicin\w*\s+de\s+urgent"),
    ("Pediatrie", r"pediatri(e|c)(?!\w*\s+(chirurg|psihiatr|neurolog|cardiolog|nefrolog))|\bpediatru"),
    ("Neonatologie", r"neonatolog"),
    ("Obstetrică-ginecologie", r"obstetric|ginecolog"),
    ("ATI (anestezie și terapie intensivă)", r"\bati\b|anestezi"),
    ("Chirurgie generală", r"chirurgi\w*\s+general"),
    ("Chirurgie (alte specialități)", r"chirurgi\w*\s+(pediatric|plastic|cardio|toracic|vascular|orala|maxilo|oncologic)"),
    ("Ortopedie și traumatologie", r"ortoped"),
    ("Neurochirurgie", r"neurochirurg"),
    ("Urologie", r"\burolog"),
    ("Cardiologie", r"cardiolog"),
    ("Neurologie", r"neurolog"),
    ("Psihiatrie", r"psihiatr"),
    ("Radiologie și imagistică", r"radiolog|imagistic"),
    ("Radioterapie / Medicină nucleară", r"radioterap|medicin\w*\s+nuclear"),
    ("Medicină de laborator", r"medicin\w*\s+de\s+laborator|laborator\s+clinic"),
    ("Anatomie patologică", r"anatomi\w*\s+patolog"),
    ("Microbiologie", r"microbiolog"),
    ("Boli infecțioase", r"boli\w*\s+infect"),
    ("Dermatovenerologie", r"dermato"),
    ("Oftalmologie", r"oftalmolog"),
    ("ORL", r"\borl\b|otorinolaringolog"),
    ("Gastroenterologie", r"gastroenterolog"),
    ("Diabet, nutriție și boli metabolice", r"diabet|nutriti"),
    ("Endocrinologie", r"endocrinolog"),
    ("Nefrologie", r"nefrolog"),
    ("Pneumologie", r"pneumolog|pneumoftiziolog"),
    ("Reumatologie", r"reumatolog"),
    ("Hematologie", r"hematolog"),
    ("Oncologie", r"oncolog"),
    ("Recuperare / reabilitare medicală", r"reabilitar|recuperar|balneo|medicin\w*\s+fizic"),
    ("Geriatrie", r"geriatri"),
    ("Epidemiologie", r"epidemiolog"),
    ("Igienă / sănătate publică", r"\bigien|sanatate\s+publica"),
    ("Medicina muncii", r"medicin\w*\s+(a\s+)?muncii"),
    ("Medicină legală", r"medicin\w*\s+legal"),
    ("Genetică medicală", r"genetic"),
    ("Alergologie și imunologie", r"alergolog|imunolog"),
    ("Medicină sportivă", r"medicin\w*\s+sportiv"),
    ("Medicină dentară", r"stomatolog|dentar"),
]]


def medical_specialties(title) -> list[str]:
    """The specialties a doctor's posting names in its title, in list order; [] for others."""
    t = fold(title)
    if not re.search(r"\bmedic", t) or re.search(r"\bmedic\w*\s+veterinar", t):
        return []
    return [label for label, pattern in MEDICAL_SPECIALTIES if pattern.search(t)]


# ---- Language ------------------------------------------------------------------

_ANY_FOREIGN = re.compile(r"strain|circulatie|international|oricare|o\s+limba\b|limbi\b")


def language_code(code) -> str:
    """The extraction's language code, with every "any foreign language" phrasing as `straina`."""
    c = str(code or "").strip()
    folded = fold(c)
    if len(folded) > 3 and _ANY_FOREIGN.search(folded) and "semnelor" not in folded and "izvoare" not in folded:
        return "straina"
    return c


# ---- Skills ----------------------------------------------------------------------

#: One spelling per common tool: the extraction writes "Excel", "Microsoft Excel"
#: and "MS Excel" for the same thing, and the facet listed each.
_SKILL_ALIASES: list[tuple[re.Pattern, str]] = [(re.compile(p), label) for p, label in [
    (r"^(ms\s+|microsoft\s+)?excel$", "Microsoft Excel"),
    (r"^(ms\s+|microsoft\s+)?word$", "Microsoft Word"),
    (r"^(ms\s+|microsoft\s+)?power\s?point$", "Microsoft PowerPoint"),
    (r"^(ms\s+|microsoft\s+)?office$|^pachetul\s+(microsoft\s+)?office$", "Microsoft Office"),
    (r"^(operare|utilizare|utilizarea|lucru)\s+(pe\s+|la\s+|a\s+)?(pc|calculator\w*|computer\w*)$|^cunostinte\s+(de\s+)?(operare\s+)?(pc|calculator)$", "Operare PC"),
    (r"^ssm$|^securitate\w*\s+si\s+sanatate\w*\s+in\s+munca$", "Securitate și sănătate în muncă"),
    (r"^psi$|^prevenire\w*\s+si\s+stingere\w*\s+a?\s*incendiilor$", "PSI"),
]]


def skill_tags(skill_list) -> list[str]:
    """The skills worth filtering on: no soft skills, one spelling per tool.

    261016: half the extracted skills were `transversal` — "lucru în echipă",
    "comunicare", "corectitudine" — which every posting asks for and no reader
    filters on. They stay in the extraction (the detail page lists them); the
    facet columns get the knowledge and skill entries only.
    """
    out: list[str] = []
    for k in skill_list or []:
        if not isinstance(k, dict):
            continue
        if (k.get("type") or "") in ("transversal", "language"):
            continue
        label = str(k.get("label") or "").strip()
        if not label:
            continue
        f = fold(label).strip(" .")
        for pattern, canonical in _SKILL_ALIASES:
            if pattern.search(f):
                label = canonical
                break
        if label not in out:
            out.append(label)
    return out


# ---- Distinctive tags ---------------------------------------------------------------

#: 261016 (pax, 2026-10-18): a few short tags per posting saying what makes it
#: different. Candidates are facts the export already holds; `rank_tags()` keeps
#: the uncommon ones, so "Permanent" and "Funcții de execuție" (most postings)
#: never show and "Normă parțială", "PNRR" or "GIS" do.
TAG_MAX = 4
TAG_MAX_SHARE = 0.25     # a tag on more than a quarter of postings says nothing

_CRED_TAGS = {"permis_conducere": "Permis auto", "acces_informatii_clasificate": "Acces info. clasificate"}


def _fmt_day(iso) -> str:
    m = re.match(r"(\d{4})-(\d{2})-(\d{2})", str(iso or ""))
    return f"{m.group(3)}.{m.group(2)}.{m.group(1)}" if m else ""


def _json_list(v) -> list:
    if isinstance(v, list):
        return v
    try:
        import json
        out = json.loads(v or "[]")
        return out if isinstance(out, list) else []
    except (ValueError, TypeError):
        return []


def tag_candidates(p: dict) -> list[tuple[str, str]]:
    """(key, label) facts that might make a posting stand out; ranked later by rarity.

    `p` holds export column values (job_type, job_level, job_regime, work_mode,
    funding_kind, v3_schedule, v3_shift_work, v3_contract_duration, v5_*,
    inf_experience_years, inf_studies_required, v3_credentials, v3_languages,
    med_specialty, skills (classify.skill_tags output), nr_posturi). The key is
    what rarity is counted on; a value-specific key ("skill:GIS") is rare on its own.
    """
    out: list[tuple[str, str]] = []
    add = lambda key, label: out.append((key, label)) if label else None  # noqa: E731

    end = _fmt_day(p.get("v5_end_date"))
    if end:
        add("temporar", f"Până la {end}")
    elif (p.get("v3_contract_duration") in ("determinata", "proiect")) or p.get("job_type") == "Temporar":
        add("temporar", "Perioadă determinată")
    if "conducere" in fold(p.get("job_level")):
        add("conducere", "Post de conducere")
    add("regime:" + str(p.get("job_regime") or ""),
        {"publica": "Funcție publică", "militara": "Funcție militară"}.get(p.get("job_regime") or "", ""))
    fte = p.get("v5_fte")
    if isinstance(fte, (int, float)) and 0 < fte < 1:
        add("parttime", "Normă " + f"{fte:g}".replace(".", ","))
    elif p.get("v3_schedule") == "norma_partiala":
        add("parttime", "Normă parțială")
    if p.get("v3_schedule") == "tura_noapte":
        add("ture", "Tură de noapte")
    elif p.get("v3_shift_work") or p.get("v3_schedule") == "schimburi":
        add("ture", "Ture / gărzi")
    add("work:" + str(p.get("work_mode") or ""), {"telemunca": "Telemuncă", "hibrid": "Hibrid"}.get(p.get("work_mode") or "", ""))
    add("fund:" + str(p.get("funding_kind") or ""),
        {"fonduri_europene": "Proiect cu fonduri UE", "pnrr": "PNRR"}.get(p.get("funding_kind") or "", ""))
    exp = p.get("inf_experience_years")
    if exp is not None and float(exp) == 0:
        add("noexp", "Fără experiență")
    add("studies:" + str(p.get("inf_studies_required") or ""),
        {"generala": "Studii generale", "doctorat": "Doctorat"}.get(p.get("inf_studies_required") or "", ""))
    if p.get("v5_recruitment_kind") == "promovare":
        add("promovare", "Promovare (doar interni)")
    if p.get("v5_post_status") == "temporar_vacant":
        add("tempvacant", "Post temporar vacant")
    if set(_json_list(p.get("v5_channels"))) & {"email", "online", "posta"}:
        add("remote_apply", "Depunere la distanță")
    for kind in _json_list(p.get("v3_credentials")):
        if kind in _CRED_TAGS:
            add("cred:" + kind, _CRED_TAGS[kind])
    for spec in _json_list(p.get("med_specialty"))[:1]:
        add("spec:" + spec, spec)
    # Only tool-like skills ("GIS", "AutoCAD", "SICAP", "ECDL"): an acronym or a
    # product name. Free-text skills ("gramatică și ortografie") read as noise.
    for skill in (p.get("skills") or [])[:12]:
        if (len(skill) <= 25 and _TOOLISH.match(skill)
                and skill not in ("Microsoft Office", "Microsoft Excel", "Microsoft Word", "Microsoft PowerPoint",
                                  "Operare PC", "PSI", "SSM", "TIC", "IT", "PC")):
            add("skill:" + skill, skill)
    for tok in _json_list(p.get("v3_languages")):
        code, _, level = str(tok).partition(":")
        if code and code not in ("ro", "straina") and len(code) <= 3:
            add("lang:" + code, (_LANG_NAMES.get(code, code.upper()) + (f" {level}" if level else "")))
            break
    n = p.get("nr_posturi")
    if isinstance(n, int) and n >= 3:
        add("many", f"{n} posturi")
    return out


_TOOLISH = re.compile(r"\b[A-Z][A-Za-z]*[A-Z][A-Za-z0-9+#]*\b")

_LANG_NAMES = {"en": "Engleză", "fr": "Franceză", "de": "Germană", "it": "Italiană", "es": "Spaniolă",
               "hu": "Maghiară", "ru": "Rusă", "uk": "Ucraineană"}


def rank_tags(candidates_by_id: dict, max_tags: int = TAG_MAX, max_share: float = TAG_MAX_SHARE) -> dict:
    """id -> up to `max_tags` labels, rarest first, dropping keys on more than `max_share`.

    Skills are counted per skill, so a skill only shows when few postings ask
    for it; at most one skill tag per posting, so a long tool list cannot
    crowd out the contract facts.
    """
    from collections import Counter
    n = max(1, len(candidates_by_id))
    freq = Counter(k for cands in candidates_by_id.values() for k in {k for k, _ in cands})
    out = {}
    for pid, cands in candidates_by_id.items():
        seen, picked, skills = set(), [], 0
        for key, label in sorted(cands, key=lambda c: freq[c[0]]):
            if key in seen or freq[key] / n > max_share:
                continue
            if key.startswith("skill:"):
                if skills or freq[key] / n > 0.02:
                    continue
                skills += 1
            seen.add(key)
            picked.append(label)
            if len(picked) >= max_tags:
                break
        out[pid] = picked
    return out


# ---- Posting quality (internal, 2026-10-18) -------------------------------------------

#: How clear and complete an announcement is, for a reader deciding whether and
#: how to apply. pax's four groups; weights sum to 100. Scored only for postings
#: with an extraction — without one most checks would measure our pipeline, not
#: the announcement. Internal first (/statistici), not shown on postings yet.
QUALITY_CHECKS: list[tuple[str, str, str, int]] = [
    # key, group, label, weight
    ("studies",      "Completitudine", "Studiile cerute sunt precizate", 8),
    ("experience",   "Completitudine", "Experiența cerută este precizată", 7),
    ("documents",    "Completitudine", "Lista documentelor de depus", 8),
    ("calendar",     "Completitudine", "Calendar cu cel puțin două date", 10),
    ("contact",      "Completitudine", "Telefon sau e-mail de contact", 6),
    ("duties",       "Completitudine", "Atribuțiile postului", 3),
    ("bibliography", "Completitudine", "Bibliografie / tematică", 3),
    ("salary",       "Salariu și condiții", "Salariul este precizat", 10),
    ("duration",     "Salariu și condiții", "Durata contractului", 5),
    ("schedule",     "Salariu și condiții", "Programul de lucru", 5),
    ("confirmed",    "Termene", "Termen de depunere precizat (nu doar expirarea)", 8),
    ("window",       "Termene", "Cel puțin 10 zile pentru depunere", 12),
    ("text",         "Claritate", "Text propriu, nu doar formule standard", 6),
    ("specific",     "Claritate", "Cerințe concrete, nu doar calități generice", 4),
    ("attachment",   "Claritate", "Anunț complet în pagină sau atașat ca document", 5),
]


def _days_between(a, b):
    from datetime import date
    try:
        da, db = date.fromisoformat(str(a)[:10]), date.fromisoformat(str(b)[:10])
    except (ValueError, TypeError):
        return None
    return (db - da).days


def quality(p: dict) -> dict | None:
    """{'score': 0–100, 'checks': {key: 0 | 0.5 | 1}} for one posting, or None without an extraction.

    `p`: schema (dict), body, contact_phone, contact_email, published_at,
    apply_deadline, deadline_source, inf_salary_min, v3_contract_duration,
    v3_schedule, attachment_meta (list), calendar_events (int, scraped rows).
    """
    s = p.get("schema")
    if not isinstance(s, dict):
        return None
    import boilerplate

    def filled(v) -> bool:
        if isinstance(v, str):
            return v.strip() not in ("", "null", "-")
        if isinstance(v, (list, dict)):
            return bool(v)
        return v is not None

    edu, exp = s.get("education") or {}, s.get("experience") or {}
    cal = s.get("competition_calendar") or {}
    dated = [e for e in (cal.get("events") or []) if isinstance(e, dict) and e.get("date")] if isinstance(cal, dict) else []
    skills = [k for k in (s.get("skill_list") or []) if isinstance(k, dict)]
    soft = sum(1 for k in skills if k.get("type") == "transversal")
    body = str(p.get("body") or "")
    own = boilerplate.strip_hg_1336(body).replace(boilerplate._BOILERPLATE_MARKER, "")
    window = _days_between(p.get("published_at"), p.get("apply_deadline"))
    confirmed = p.get("deadline_source") in ("concurs", "anunt")

    c = {
        "studies": filled(edu.get("minimum_level") if isinstance(edu, dict) else None) or filled(s.get("educationRequirements")),
        "experience": (isinstance(exp, dict) and (exp.get("years_minimum") is not None or exp.get("none_required")))
                      or filled(s.get("experienceRequirements")),
        "documents": filled(s.get("application_docs")) or filled((s.get("documents") or {}).get("standard") if isinstance(s.get("documents"), dict) else None),
        "calendar": len(dated) >= 2 or (p.get("calendar_events") or 0) >= 2,
        "contact": bool(str(p.get("contact_phone") or "").strip() or str(p.get("contact_email") or "").strip()),
        "duties": filled(s.get("responsibilities")),
        "bibliography": filled(s.get("bibliography_topics")),
        "salary": (p.get("inf_salary_min") or 0) > 0 or filled(s.get("baseSalary")),
        "duration": filled(p.get("v3_contract_duration")),
        "schedule": filled(p.get("v3_schedule")),
        "confirmed": confirmed,
        "window": 0 if not confirmed or window is None else (1 if window >= 10 else 0.5 if window >= 7 else 0),
        "text": len(own.strip()) >= 300,
        "specific": not skills or soft <= len(skills) / 2,
        "attachment": len(body.strip()) >= 800 or bool(p.get("attachment_meta")),
    }
    checks = {k: (float(v) if not isinstance(v, bool) else (1.0 if v else 0.0)) for k, v in c.items()}
    score = round(sum(w * checks[k] for k, _g, _l, w in QUALITY_CHECKS))
    return {"score": int(score), "checks": checks}
