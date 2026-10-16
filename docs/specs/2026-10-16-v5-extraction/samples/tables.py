import re, psycopg, collections
conn=psycopg.connect('dbname=posturi_dev'); cur=conn.cursor()
cur.execute("select id, body_markdown from jobs_jobposting where published_at>='2026-08-15' and coalesce(body_markdown,'')<>''")
kinds=collections.Counter(); posts=collections.Counter(); ex={}
def classify(tbl):
    t=tbl.lower()
    if re.search(r'\d{1,2}\.\d{1,2}\.\d{2,4}',t) and re.search(r'dosar|prob|interviu|rezultat|contesta|selec',t): return 'calendar'
    if re.search(r'copi[ae]|cazier|curriculum|cerere|adeverin',t): return 'dosar_docs'
    if re.search(r'bibliografi|legea|ordin|hg ',t): return 'bibliografie'
    if re.search(r'nr\.? ?post|denumire.{0,20}post|func[tț]i|compartiment|sec[tț]i',t): return 'posts_list'
    if re.search(r'studii|vechime|condi[tț]ii',t): return 'conditions'
    if re.search(r'salari|clas|coeficient',t): return 'salary'
    if re.search(r'barem|punct',t): return 'scoring'
    return 'other'
for pid,b in cur:
    tables=re.findall(r'(?:^\s*\|.*\|\s*\n?)+', b, re.M)
    seen=set()
    for tb in tables:
        if tb.count('\n')<2: continue
        k=classify(tb); kinds[k]+=1; seen.add(k)
        ex.setdefault(k, (pid, tb[:600]))
    for k in seen: posts[k]+=1
print('tables by kind:', kinds.most_common())
print('postings having kind:', posts.most_common())
for k,(pid,t) in ex.items():
    print('=====',k,pid); print(t)
