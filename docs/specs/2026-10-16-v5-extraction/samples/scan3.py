import re, psycopg
conn=psycopg.connect('dbname=posturi_dev'); cur=conn.cursor()
cur.execute("select title, body_markdown, coalesce(attachment_text,'') from jobs_jobposting where published_at>='2026-08-15' and coalesce(body_markdown,'')<>''")
rows=cur.fetchall(); N=len(rows)
F={
 'promovare (title/head)': (r'promovar|examen (de )?promovare|grad(ul)? profesional imediat superior', 'head'),
 'temporar vacant': (r'temporar vacant', 'all'),
 'determinata pana la data': (r'determinat.{0,120}p[aâ]n[aă] la (data de )?\d', 'all'),
 'management contest': (r'proiect(ul)? de management|func[tț]i(a|ei) de manager', 'all'),
 'transfer / detasare': (r'\btransfer\b|deta[sș]are', 'head'),
 'functie publica': (r'func[tț]i(e|ei|a) public[aă]|func[tț]ionar public|consilier (achizi|juridic)?.{0,20}clasa', 'head'),
 'functie contractuala': (r'contractual', 'head'),
 'rezidentiat / medic rezident': (r'rezident', 'head'),
 'norma fractiune 0,x': (r'\b0[,.]\d{1,2}\b ?(norm|post)|1/2 ?norm|jum[aă]tate de norm', 'all'),
 'norma redusa ore/zi': (r'\b[2-7] ?(ore|h)/zi', 'all'),
 'week-end / sambata': (r's[aâ]mb[aă]t|duminic|weekend|week-end', 'all'),
 'noapte': (r'noapte', 'all'),
 'proba practica': (r'prob[aă] practic', 'all'),
 'exam online/video': (r'(online|videoconferin|zoom|teams|webex)', 'all'),
 'depunere email': (r'(pe|prin|la|transmis[aă]?) (adresa de )?e-?mail|po[sș]t[aă] electronic', 'all'),
 'depunere posta': (r'po[sș]t[aă].{0,30}(confirmare|recomandat)|curier', 'all'),
 'salariu cifra': (r'salari\w*.{0,80}\d[\d.]{2,} ?(lei|ron)', 'all'),
 'spor (cu procent)': (r'spor\w*.{0,60}\d+ ?%', 'all'),
 'clasa de salarizare': (r'clas[aă] de salarizare|\bclasa \d{2}\b', 'all'),
 'tichete/vouchere': (r'tichet|voucher|norm[aă] de hran[aă]', 'all'),
 'locuinta/cazare': (r'cazare|locuin[tț][aă] de serviciu|chiria', 'all'),
 'limita varsta': (r'v[aâ]rst[aă] (de )?(maxim|minim)|nu (a |au )?[iî]mplinit v[aâ]rsta|v[aâ]rsta (de|maxim[aă]) de \d', 'all'),
 'cerinta sex/gen': (r'\bsexul\b|\b(doar|numai) (b[aă]rba|femei)|\bfeminin|\bmasculin', 'all'),
 'permis cat': (r'permis.{0,30}categori(a|ile) [A-E]', 'all'),
 'clasificate/ORNISS': (r'informa[tț]ii clasificate|orniss|certificat de securitate|autoriza[tț]ie de acces', 'all'),
 'aviz psihologic/evaluare': (r'(aviz|evaluare|examen|test)\w* psiholog', 'all'),
 'proba fizica/sportiva': (r'prob[aă] (sportiv|fizic)|traseu|baremur', 'all'),
 'limba straina': (r'limb(a|ii) (str[aă]in|englez|francez|german)', 'all'),
 'curs/atestat calificare': (r'(curs|atestat|certificat) (de )?(calificare|specializare|absolvire)', 'all'),
 'link formular pe site': (r'formular(ul)? de [iî]nscriere', 'all'),
 'bibliografie per post': (r'bibliografi\w* (\S+ ){0,4}(pentru )?(postul|func[tț]ia|posturile)', 'all'),
 'adresa sediu explicit': (r'str(ada|\.) ?\w+.{0,40}nr\.? ?\d', 'all'),
 'proiect UE/PNRR': (r'pnrr|poeo|pocu|proiect(ul)? (cu titlul|„|")|cod (smis|proiect)', 'all'),
 'nr posturi >1 in title': (r'\b([2-9]|\d{2}) (de )?post', 'title'),
 'tabel markdown': (r'^\s*\|.*\|.*\|', 'all'),
 'calendar relativ (in termen de N zile)': (r'[iî]n termen de (\w+ )?(\d+|una|o|dou[aă]|trei|patru|cinci) zil', 'all'),
}
for k,(rx,scope) in F.items():
    r=re.compile(rx, re.I|re.M); n=0
    for t,b,a in rows:
        s = t if scope=='title' else (t+'\n'+b[:2500] if scope=='head' else t+'\n'+b+'\n'+a)
        if r.search(s): n+=1
    print(f"{k:42} {100*n/N:5.1f}%  ({n}/{N})")
