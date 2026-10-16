import re, psycopg, statistics, sys
sys.path.insert(0,'/Users/pax/devbox/gov2/posturi.gov.ro')
from boilerplate import strip_hg_1336
conn=psycopg.connect('dbname=posturi_dev'); cur=conn.cursor()
cur.execute("select body_markdown, coalesce(attachment_text,'') from jobs_jobposting where published_at>='2026-08-15' and coalesce(body_markdown,'')<>''")
def sh(t,k=6):
    w=re.findall(r'\w+',t.lower()); return {' '.join(w[i:i+k]) for i in range(len(w)-k)}
ratios=[]; tot=0; dupchars=0; n_att=0; lens=[]
for b,a in cur:
    full=strip_hg_1336(b+'\n\n---\n\n'+a) if a.strip() else strip_hg_1336(b)
    lens.append(len(full))
    if not a.strip(): continue
    n_att+=1
    sb,sa=sh(b),sh(a)
    if not sb: continue
    r=len(sb&sa)/len(sb); ratios.append(r)
    if r>0.6: dupchars+=len(b)
    tot+=len(b)+len(a)
print('postings with attachment text', n_att, 'of', len(lens))
print('median share of body 6-grams also in attachments', statistics.median(ratios))
print('share with >60% of body duplicated', sum(r>0.6 for r in ratios)/len(ratios))
print('chars saved if body dropped when >60% dup', dupchars/tot)
lens.sort(); print('content chars median/p90/max', lens[len(lens)//2], lens[int(.9*len(lens))], lens[-1])
