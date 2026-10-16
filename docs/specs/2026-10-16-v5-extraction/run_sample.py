"""Standalone v4-vs-v5 trial on a few postings (BETA-V5 analysis, 2026-10-16).

Reads posting text from local files (title/employer header + body + attachment,
boilerplate already stripped), calls DeepSeek directly, writes JSON outputs and a
usage log next to this script. No database access, no production writes.

    .venv/bin/python docs/specs/2026-10-16-v5-extraction/run_sample.py POSTS_DIR N1 N2 ...
"""
import json, os, sys, time, glob
from pathlib import Path
from datetime import datetime, timezone
from dotenv import load_dotenv
import openai

HERE = Path(__file__).parent
ROOT = HERE.parents[2]
load_dotenv(ROOT / ".env")
V4 = json.load(open(ROOT / "models_config.json"))["prompts"]["v4"]
V5 = (HERE / "draft-v5-prompt.txt").read_text()
RATES = {"in": 0.15, "cached": 0.003, "out": 0.60}   # off-peak list, read 2026-10-16
BUDGET = 0.25

client = openai.OpenAI(api_key=os.getenv("DEEPSEEK_API_KEY"), base_url="https://api.deepseek.com")
posts_dir, nums = sys.argv[1], sys.argv[2:]
spent = 0.0
log = open(HERE / "samples" / "usage.jsonl", "a")
for n in nums:
    fn = glob.glob(f"{posts_dir}/{int(n):02d}-*.md")[0]
    text = Path(fn).read_text()
    for ver, prompt in (("v4", V4), ("v5", V5)):
        if spent > BUDGET:
            sys.exit(f"budget reached: ${spent:.4f}")
        t0 = time.time()
        resp = client.chat.completions.create(
            model="deepseek-v4-flash",
            messages=[{"role": "system", "content": prompt}, {"role": "user", "content": text}],
            max_tokens=16000, temperature=0.2, response_format={"type": "json_object"},
            extra_body={"thinking": {"type": "disabled"}})
        u = resp.usage
        cached = getattr(u, "prompt_cache_hit_tokens", 0) or 0
        cost = ((u.prompt_tokens - cached) * RATES["in"] + cached * RATES["cached"]
                + u.completion_tokens * RATES["out"]) / 1e6
        spent += cost
        out = resp.choices[0].message.content
        name = Path(fn).stem
        (HERE / "samples" / f"{name}.{ver}.json").write_text(
            json.dumps(json.loads(out), ensure_ascii=False, indent=1))
        rec = {"at": datetime.now(timezone.utc).isoformat(timespec="seconds"), "posting": name,
               "prompt": ver, "in": u.prompt_tokens, "cached": cached, "out": u.completion_tokens,
               "cost_usd": round(cost, 6), "latency_s": round(time.time() - t0, 1),
               "finish": resp.choices[0].finish_reason}
        log.write(json.dumps(rec) + "\n"); log.flush()
        print(rec)
print(f"total ${spent:.4f}")
