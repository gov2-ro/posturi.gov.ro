"""Trial of the FINAL v5 prompt (models_config.json prompts.v5) on a few local postings.

Reads the posting from the local Postgres (read-only), builds the input exactly as
llm-schema.py does for v5 (boilerplate strip + body/attachment dedup), calls DeepSeek
directly, validates with JobPostingExtractionV5 and checks every quote with
evidence.locate. Writes samples/<id>.v5final.json and samples/usage-final.jsonl.
No database writes, no production rows.

    webapp/.venv/bin/python docs/specs/2026-10-16-v5-extraction/trial_v5.py 13542 12753 ...
"""
import importlib.util
import json
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import openai
import psycopg
from dotenv import load_dotenv

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
sys.path.insert(0, str(ROOT))
load_dotenv(ROOT / ".env")

import evidence  # noqa: E402
import schema_models as sm  # noqa: E402
from boilerplate import strip_hg_1336  # noqa: E402

spec = importlib.util.spec_from_file_location("llmschema_trial", ROOT / "llm-schema.py")
llm = importlib.util.module_from_spec(spec)
spec.loader.exec_module(llm)

PROMPT = json.loads((ROOT / "models_config.json").read_text(encoding="utf-8"))["prompts"]["v5"]
RATES = {"in": 0.15, "cached": 0.003, "out": 0.60}   # DeepSeek off-peak list, read 2026-10-16
BUDGET = 0.10

client = openai.OpenAI(api_key=os.getenv("DEEPSEEK_API_KEY"), base_url="https://api.deepseek.com")
cur = psycopg.connect("postgres://localhost/posturi_dev").cursor()
spent = 0.0
log = open(HERE / "samples" / "usage-final.jsonl", "a")
for pid in [int(a) for a in sys.argv[1:]]:
    if spent > BUDGET:
        sys.exit(f"budget reached: ${spent:.4f}")
    cur.execute("SELECT url, title, body_markdown, attachment_text FROM jobs_jobposting WHERE id = %s", (pid,))
    url, title, body, att = cur.fetchone()
    content = (body or "").strip()
    raw_len = len(content) + len((att or "").strip())
    if att and att.strip():
        content = llm.dedup_body(content, att)
        content += "\n\n---\n\n" + att.strip()
    content = strip_hg_1336(content)
    t0 = time.time()
    resp = client.chat.completions.create(
        model="deepseek-v4-flash",
        messages=[{"role": "system", "content": PROMPT}, {"role": "user", "content": content}],
        max_tokens=16000, temperature=0.2, response_format={"type": "json_object"},
        extra_body={"thinking": {"type": "disabled"}})
    u = resp.usage
    cached = getattr(u, "prompt_cache_hit_tokens", 0) or 0
    cost = ((u.prompt_tokens - cached) * RATES["in"] + cached * RATES["cached"] + u.completion_tokens * RATES["out"]) / 1e6
    spent += cost
    out = json.loads(resp.choices[0].message.content)
    try:
        data = sm.JobPostingExtractionV5.model_validate(out).model_dump()
        valid, error = True, None
    except Exception as e:   # noqa: BLE001 — a trial records every failure
        data, valid, error = out, False, str(e)[:300]
    source = evidence.Source.build([("anunt", body or ""), ("anexe", att or "")])
    quotes = []
    for c in data.get("credentials") or []:
        quotes.append(("credential", c.get("evidence")))
    for d in ((data.get("documents") or {}).get("specific") or []):
        quotes.append(("document", d.get("evidence")))
    for s in data.get("skill_list") or []:
        quotes.append(("skill", s.get("evidence")))
    checks = [(k, q, evidence.locate(q, source).status) for k, q in quotes if q]
    (HERE / "samples" / f"{pid}.v5final.json").write_text(json.dumps(data, ensure_ascii=False, indent=1))
    rec = {"at": datetime.now(timezone.utc).isoformat(timespec="seconds"), "posting": pid,
           "slug": url.rstrip("/").split("/")[-1], "input_chars_raw": raw_len, "input_chars_sent": len(content),
           "in": u.prompt_tokens, "cached": cached, "out": u.completion_tokens, "cost_usd": round(cost, 6),
           "latency_s": round(time.time() - t0, 1), "finish": resp.choices[0].finish_reason,
           "valid": valid, "error": error,
           "quotes": len(checks), "verified": sum(1 for c in checks if c[2] == "verified"),
           "unverified": [(k, q) for k, q, s in checks if s != "verified"],
           "facts": {k: data.get(k) for k in ("recruitment", "vacancies_total", "application", "age_limit", "work_localities")}
           | {"contract": {k: (data.get("contract") or {}).get(k) for k in ("duration", "fte", "end_date", "post_status")}}}
    log.write(json.dumps(rec, ensure_ascii=False) + "\n")
    log.flush()
    print(json.dumps(rec, ensure_ascii=False))
print(f"total ${spent:.4f}")
