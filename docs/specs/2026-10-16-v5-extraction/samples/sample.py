import json, random, re, sys, os
sys.path.insert(0, '/Users/pax/devbox/gov2/posturi.gov.ro')
import psycopg as psycopg2
from boilerplate import strip_hg_1336
OUT = sys.argv[1]
conn = psycopg2.connect("dbname=posturi_dev")
cur = conn.cursor()
cur.execute("""select j.id, j.url, j.title, e.name, j.body_markdown, j.attachment_text, j.schema_json, j.published_at, coalesce(jd.name,'')
 from jobs_jobposting j left join jobs_employer e on e.id=j.employer_id left join jobs_judet jd on jd.id=j.judet_id
 where j.published_at >= '2026-08-15' and coalesce(j.body_markdown,'')<>'' order by j.id""")
rows = cur.fetchall()
strata = [
 ('spital', r'spital|institutul .*(urgen|boli)|sanatoriu|ambulan', 8),
 ('primarie', r'prim[aă]ri|comuna|consiliul local|ora[sș]ul|municipiul', 6),
 ('educatie', r'[sș]coal|liceu|colegiu|gr[aă]dini|universit|palatul copiilor|inspectoratul [sș]colar', 5),
 ('central', r'minister|agen[tț]i|autoritat|ofici|casa |garda|administra[tț]ia na|direc[tț]ia (silvic|sanitar|de s[aă]n)|institutul na|regia', 5),
 ('social', r'asisten[tț][aă] social|dgaspc|protec[tț]ia copil|c[aă]min', 4),
 ('ordine', r'militar|poli[tț]i|jandarm|inspectoratul (pentru situa|general)|penitenciar|isu|ap[aă]r[aă]ri', 4),
 ('cultura', r'muzeu|teatr|bibliotec|filarmonic|centrul (jude[tț]ean )?(de )?cultur|ansamblu', 3),
]
random.seed(16)
used=set(); picks=[]
def norm(s): return (s or '').lower()
for name, rx, n in strata:
    cand=[r for r in rows if re.search(rx, norm(r[3])) and r[0] not in used]
    random.shuffle(cand)
    for r in cand[:n]: picks.append((name,r)); used.add(r[0])
# management contests
cand=[r for r in rows if re.search(r'\bmanager|director (general|executiv)|concurs.*manag', norm(r[2])) and r[0] not in used]
random.shuffle(cand)
for r in cand[:2]: picks.append(('management',r)); used.add(r[0])
# multi-role: title mentions >=2 'post'
cand=[r for r in rows if len(re.findall(r'post', norm(r[2])))>=2 and r[0] not in used]
random.shuffle(cand)
for r in cand[:3]: picks.append(('multirol',r)); used.add(r[0])
print(len(picks))
idx=[]
for i,(st,r) in enumerate(picks,1):
    pid,url,title,emp,body,att,sj,pub,jud = r
    content=(body or '').strip()
    if att and att.strip(): content += "\n\n---\n\n"+att.strip()
    content=strip_hg_1336(content)
    fn=f"{i:02d}-{st}-{pid}.md"
    open(os.path.join(OUT,fn),'w').write(f"# {title}\n# employer: {emp} | judet: {jud} | pub: {pub} | url: {url}\n\n{content}")
    if sj: json.dump(sj, open(os.path.join(OUT,fn.replace('.md','.v3.json')),'w'), ensure_ascii=False, indent=1)
    idx.append({'n':i,'stratum':st,'id':pid,'url':url,'title':title,'employer':emp,'judet':jud,'chars':len(content),'has_schema':bool(sj)})
json.dump(idx, open(os.path.join(OUT,'index.json'),'w'), ensure_ascii=False, indent=1)
for x in idx: print(x['n'],x['stratum'],x['chars'],x['has_schema'],x['judet'],'|',x['employer'][:45],'|',x['title'][:60])
