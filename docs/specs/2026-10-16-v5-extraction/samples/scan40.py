import re, glob, json
F={
 'promovare / internal': r'promovar|grad(ul)? profesional imediat superior',
 'management contest': r'proiect(ul)? de management|func[tț]i(a|ei) de manager',
 'temporar vacant (own)': r'(?<!sau )temporar vacant',
 'durata determinata': r'perioad[aă] determinat|durat[aă] determinat',
 'end date / months stated': r'determinat.{0,150}(p[aâ]n[aă] la (data de )?\d|\d+ ?luni)',
 'fractional norm / reduced hours': r'\b0[,.]\d{1,2}\b ?(norm|post)|1/2 ?norm|jum[aă]tate de norm|\b[2-7] ?(ore|h) ?/ ?zi|\d+ ?ore ?/ ?lun',
 'weekend / night / ture': r's[aâ]mb[aă]t|duminic|weekend|noapte|\bture\b|g[aă]rz|program (de lucru )?inegal',
 'channel email': r'(pe|prin|la|transmis[aă]?) (adresa de )?e-?mail|po[sș]t[aă] electronic',
 'channel posta/curier': r'po[sș]t[aă].{0,40}(confirmare|recomandat|rom[aâ]n)|curier',
 'channel online platform': r'platform[aă] (online|electronic)|formular online|aplica[tț]i(a|e) online',
 'age limit': r'v[aâ]rst[aă] (de )?(maxim|minim)|nu (a |au )?[iî]mplinit v[aâ]rsta|v[aâ]rsta (de|maxim[aă]) de \d',
 'permis categoria': r'permis.{0,30}categori(a|ile) [A-E]',
 'aviz psihologic (specific)': r'aviz\w* psihologic',
 'certificat membru / aviz practica': r'oamgmamr|colegiul (medicilor|farmac|psiholog)|aviz de (liber[aă] )?practic|certificat de membru',
 'curs/atestat calificare': r'(curs|atestat|certificat)\w* (de )?(calificare|specializare|absolvire)',
 'multi-role (>=2 roles)': None,
 'work locality named (punct de lucru etc.)': r'punct(ul)? de lucru|loc(ul)? de munc[aă] (este|:|va fi)|[iî]n localitatea',
 'subordinated (stated)': r'[iî]n subordinea|din subordinea|subordonat|sub autoritatea',
 'project / EU funding': r'pnrr|poeo|pocu|smis|proiect(ul)? (cu titlul|„|")',
 'relative calendar rows': r'[iî]n termen de (\w+ )?(\d+|una|o|dou[aă]|trei|patru|cinci) zil',
 'calendar as table': r'^\s*\|.*\d{1,2}[./]\d{1,2}[./]\d{2,4}.*\|',
 'salary figure stated': r'salari\w*.{0,80}\d[\d.]{2,} ?(lei|ron)|lei ?/ ?or[aă]',
 'tax/fee stated': r'tax[aă] de (concurs|participare|[iî]nscriere)',
 'exam online': r'(interviu|prob[aă]).{0,60}(online|videoconferin|zoom|teams|webex)',
 'bibliography per role': r'bibliografi\w*.{0,40}(pentru )?(postul|posturile|func[tț]ia) ',
}
files=sorted(glob.glob('/private/tmp/claude-501/-Users-pax-devbox-gov2-posturi-gov-ro/cb17bbd7-2e81-450a-b9d4-5eb665a6fb23/scratchpad/v5/posts/*.md'))
idx=json.load(open('/private/tmp/claude-501/-Users-pax-devbox-gov2-posturi-gov-ro/cb17bbd7-2e81-450a-b9d4-5eb665a6fb23/scratchpad/v5/posts/index.json'))
res={}
for k,rx in F.items():
    if rx is None:
        res[k]=sum(1 for x in idx if len(re.findall(r'post', x['title'].lower()))>=2 or x['stratum']=='multirol' or re.search(r',|;| și ', x['title']))
        continue
    r=re.compile(rx,re.I|re.M)
    res[k]=sum(1 for f in files if r.search(open(f).read().split('\n',2)[2]))
for k,v in res.items(): print(f"{k:42} {v:2d}/40")
