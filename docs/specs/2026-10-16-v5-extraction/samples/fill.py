import json, psycopg, collections
conn=psycopg.connect('dbname=posturi_dev'); cur=conn.cursor()
def filled(v):
    if v is None: return False
    if isinstance(v,(list,str,dict)) and len(v)==0: return False
    if isinstance(v,dict): return any(filled(x) for x in v.values())
    return True
def rates(rows, label):
    c=collections.Counter(); n=0; sizes=collections.Counter()
    sub=collections.Counter()
    for (sj,) in rows:
        if not sj: continue
        n+=1
        for k,v in sj.items():
            if filled(v): c[k]+=1
            sizes[k]+=len(json.dumps(v,ensure_ascii=False))
            if isinstance(v,dict):
                for kk,vv in v.items():
                    if filled(vv): sub[k+'.'+kk]+=1
    print(f"== {label} n={n}")
    tot=sum(sizes.values())
    for k in sorted(sizes, key=lambda k:-sizes[k]):
        print(f"  {k:26} filled {100*c[k]/n:5.1f}%  share_of_json_chars {100*sizes[k]/tot:5.1f}%")
    for k in sorted(sub): print(f"    {k:40} {100*sub[k]/n:5.1f}%")
cur.execute("select schema_json from jobs_jobpostingschemavariant where prompt_version='v4'")
rates(cur.fetchall(),'v4 variants (deepseek)')
cur.execute("select schema_json from jobs_jobpostingschemavariant where prompt_version='v3' and provider='deepseek'")
rates(cur.fetchall(),'v3 variants (deepseek)')
