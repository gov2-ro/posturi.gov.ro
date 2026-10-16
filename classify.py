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
