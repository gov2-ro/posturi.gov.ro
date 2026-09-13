#!/usr/bin/env python3
"""Normalise job titles into occupations: canonical label, COR code, grid selector.

A dictionary pass, not a per-posting pass. 9,757 postings carry 5,979 distinct
titles — 3,723 once `ocupatii.parse_title()` has stripped the grade, the post
count and the department. Normalising the *titles* is a fraction of the work,
the result is cached in `jobs_occupation`, and a later run only touches titles
it has not seen. It is also independently re-runnable: when the salary grid
changes, this is what gets redone, not the 9,600-posting body extraction.

The model never invents a code. For each title it is handed a shortlist of real
COR occupations and real grid rows, retrieved by `ocupatii.Cor.candidates()` and
`salary_grid.Grid.candidates()`, and asked to pick one or answer `none`. Any
code that was not on its shortlist is rejected after the fact — which matters
here because the configured provider (DeepSeek) has no server-side schema
enforcement, so Pydantic and this guard are the only things between the prompt
and a plausible-looking wrong answer.

Usage:
    python normalize-titles.py --limit 50 --dry-run
    python normalize-titles.py --workers 8
    python normalize-titles.py --force --slug ingrijitor
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import sys
import time
from collections import Counter
from pathlib import Path

import psycopg
from psycopg.rows import dict_row
from pydantic import ValidationError

ROOT = Path(__file__).parent.resolve()
sys.path.insert(0, str(ROOT))

import ocupatii  # noqa: E402
import salary_grid  # noqa: E402
from llm_config import resolve_model, resolve_provider  # noqa: E402
from schema_models import OccupationMapping  # noqa: E402

try:
    from dotenv import load_dotenv
    load_dotenv(ROOT / ".env", override=False)
except ImportError:
    pass

DATABASE_URL = os.environ.get("DATABASE_URL", "postgres://localhost/posturi_dev")
MODELS_CONFIG = json.loads((ROOT / "models_config.json").read_text(encoding="utf-8"))
PROMPT_KEY = "occupation_v1"


def _llm_schema_module():
    """Reuse the retry/concurrency/cost layer from `llm-schema.py`.

    The filename has a hyphen so it cannot be imported normally; this is the
    same `spec_from_file_location` pattern `webapp/tests/test_llm_runner.py`
    already uses. Those helpers are provider-agnostic, and duplicating a retry
    policy is how two retry policies start to disagree.
    """
    spec = importlib.util.spec_from_file_location("llm_schema", ROOT / "llm-schema.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules["llm_schema"] = module
    spec.loader.exec_module(module)
    return module


# --- posting context --------------------------------------------------------
# What the posting's employer tells us about which part of the grid applies.
# These are hints for retrieval and for the prompt, not decisions: the model
# still has to pick a row, and `estimate-salaries.py` resolves the rest.

_EMPLOYER_TO_LEVEL = {
    "Primării": "local",
    "Instituții locale": "local",
    "Consilii județene": "local",
    "Instituții județene": "teritorial",
    "Instituții regionale": "teritorial",
    "Prefecturi": "teritorial",
    "Guvern și ministere": "central",
    "Instituții naționale": "central",
}

_FAMILY_TO_ANNEX = {
    "sănătate": "II",
    "educație": "I",
    "cultură": "III",
    "juridic": "V",
    "ordine publică": "VI",
}


def context_for(employer_category: str, family: str) -> dict:
    level = _EMPLOYER_TO_LEVEL.get(employer_category or "", "")
    if employer_category == "Unități militare":
        anexa = "VI"
    else:
        anexa = _FAMILY_TO_ANNEX.get(family or "", "VIII")
    regim = ""
    if employer_category == "Funcție publică":
        regim = "funcționar public"
    elif employer_category == "Funcție contractuală":
        regim = "personal contractual"
    return {"anexa": anexa, "nivel_administrativ": level, "regim": regim,
            "employer_category": employer_category or "", "family": family or ""}


# --- the prompt payload -----------------------------------------------------

def build_user_message(title: str, parsed, ctx: dict, cor_cands, grid_cands) -> str:
    lines = [f"Ocupație de normalizat: `{parsed.base}`"]
    if parsed.base.strip().lower() != (title or "").strip().lower():
        lines.append(f"(dintr-un anunț intitulat `{title}`)")
    bits = [b for b in (
        f"angajator `{ctx['employer_category']}`" if ctx["employer_category"] else "",
        f"domeniu `{ctx['family']}`" if ctx["family"] else "",
        f"nivel administrativ `{ctx['nivel_administrativ']}`" if ctx["nivel_administrativ"] else "",
    ) if b]
    if bits:
        lines.append("Context: " + ", ".join(bits))
    if parsed.grade_token:
        # Stated so the model does not re-encode it in the grid row: the grade
        # varies per posting and is applied when the salary is computed.
        lines.append(
            f"Gradul `{parsed.grade_token}` a fost deja extras din titlu și se aplică separat"
            " — alege rândul de grilă pentru OCUPAȚIE, nu pentru grad."
        )
    if parsed.study_hint:
        lines.append(f"Nivel de studii detectat automat în titlu: `{parsed.study_hint}`")

    lines.append("\nCandidați COR:")
    if cor_cands:
        for _, e in cor_cands:
            lines.append(f"- `{e.cod_cor}` {e.denumire}")
    else:
        lines.append("- (niciunul)")

    lines.append("\nCandidați grilă:")
    if grid_cands:
        for _, r, matched in grid_cands:
            attrs = ", ".join(x for x in (
                r.regim, r.tip_post, f"studii {r.studii}" if r.studii else "",
                f"grad {r.grad_treapta}" if r.grad_treapta else "",
                r.nivel_administrativ,
            ) if x)
            # Show the synonym that matched plus a count. Anexa II pays two dozen
            # health professions off one line; pasting all of them would be most
            # of the prompt.
            others = len(r.sinonime) - 1
            label = matched if others <= 0 else f"{matched} (+{others} sinonime pe același rând)"
            lines.append(f"- [{r.ref}] {label} (cod {r.cod or '—'}, anexa {r.anexa}; {attrs})")
    else:
        lines.append("- (niciunul)")
    return "\n".join(lines)


# --- provider call ----------------------------------------------------------

def make_generator(provider: str, model: str, system_prompt: str):
    """Return `generate(user_message) -> (mapping_dict, in_tok, out_tok, cached)`."""
    if provider in ("deepseek", "openai"):
        from openai import OpenAI
        if provider == "deepseek":
            client = OpenAI(api_key=os.environ["DEEPSEEK_API_KEY"],
                            base_url="https://api.deepseek.com")
            extra = {"extra_body": {"thinking": {"type": "disabled"}}}
            fmt = {"type": "json_object"}
        else:
            client = OpenAI()
            extra = {}
            schema = OccupationMapping.model_json_schema()
            fmt = {"type": "json_schema",
                   "json_schema": {"name": "OccupationMapping", "schema": schema}}

        def generate(content):
            r = client.chat.completions.create(
                model=model, response_format=fmt, temperature=0.1, max_tokens=700,
                messages=[{"role": "system", "content": system_prompt},
                          {"role": "user", "content": content}], **extra)
            u = r.usage
            cached = getattr(getattr(u, "prompt_cache_hit_tokens", None), "real", None) \
                or getattr(u, "prompt_cache_hit_tokens", 0) or 0
            return (r.choices[0].message.content, u.prompt_tokens, u.completion_tokens, cached)

    elif provider == "gemini":
        from google import genai
        from google.genai import types
        client = genai.Client(api_key=os.environ["GEMINI_API_KEY"])

        def generate(content):
            r = client.models.generate_content(
                model=model, contents=content,
                config=types.GenerateContentConfig(
                    system_instruction=system_prompt, temperature=0.1,
                    response_mime_type="application/json",
                    response_schema=OccupationMapping))
            u = r.usage_metadata
            return (r.text, u.prompt_token_count, u.candidates_token_count,
                    u.cached_content_token_count or 0)

    elif provider == "anthropic":
        import anthropic
        client = anthropic.Anthropic()
        tool = {"name": "normalize_occupation",
                "description": "Return the normalised occupation.",
                "input_schema": OccupationMapping.model_json_schema()}

        def generate(content):
            r = client.messages.create(
                model=model, max_tokens=900,
                system=[{"type": "text", "text": system_prompt,
                         "cache_control": {"type": "ephemeral"}}],
                tools=[tool], tool_choice={"type": "tool", "name": tool["name"]},
                messages=[{"role": "user", "content": content}])
            block = next(b for b in r.content if b.type == "tool_use")
            u = r.usage
            cache_read = getattr(u, "cache_read_input_tokens", 0) or 0
            return (block.input, u.input_tokens + cache_read, u.output_tokens, cache_read)
    else:
        raise SystemExit(f"unknown provider {provider!r}")

    return generate


def validate(raw, cor_codes: set[str], grid_codes: set[str], parse_json) -> OccupationMapping:
    """Validate, then reject any code the model was not actually offered.

    The shortlist guard is the point. Pydantic proves `cor_code` is six digits;
    only this proves it is six digits the model was shown. Without it a provider
    with no schema enforcement can return a well-formed code for an occupation
    that is not this one, and a wrong salary is worse than no salary.
    """
    if isinstance(raw, str):
        raw = parse_json(raw)
    if not isinstance(raw, dict):
        raise ValueError(f"expected an object, got {type(raw).__name__}")
    mapping = OccupationMapping.model_validate(raw)

    if mapping.cor_code and mapping.cor_code not in cor_codes:
        raise ValueError(
            f"cor_code {mapping.cor_code} was not among the candidates offered; "
            "choose one of the listed codes or null"
        )
    sel = mapping.grid_selector
    if sel and sel.cod and sel.cod not in grid_codes:
        raise ValueError(
            f"grid_selector.cod {sel.cod} was not among the candidates offered; "
            "copy a `cod` from the list or use null"
        )
    return mapping


# --- database ---------------------------------------------------------------

TITLE_QUERY = """
    SELECT p.title,
           COALESCE(p.employer_category, '')      AS employer_category,
           COALESCE(p.inferred->>'profession_family', '') AS family
      FROM jobs_jobposting p
     WHERE COALESCE(p.title, '') <> ''
"""


def load_titles(conn, slug: str | None) -> list[dict]:
    """Group raw titles by their parsed base form — the dictionary key."""
    sql = TITLE_QUERY + (" AND p.title ILIKE %s" if slug else "")
    params = (f"%{slug}%",) if slug else ()
    groups: dict[str, dict] = {}
    with conn.cursor(row_factory=dict_row) as cur:
        cur.execute(sql, params)
        for row in cur:
            parsed = ocupatii.parse_title(row["title"])
            if not parsed.base_norm:
                continue
            g = groups.setdefault(parsed.base_norm, {
                "title_norm": parsed.base_norm, "parsed": parsed,
                "sample": row["title"], "n": 0,
                "employer": Counter(), "family": Counter(),
            })
            g["n"] += 1
            g["employer"][row["employer_category"]] += 1
            g["family"][row["family"]] += 1
    # Commonest titles first: they carry the most postings per call.
    return sorted(groups.values(), key=lambda g: -g["n"])


UPSERT = """
    INSERT INTO jobs_occupation
        (title_norm, title_sample, canonical, cor_code, cor_label, isco_group,
         grid_selector, study_level, match_confidence, provider, model,
         grid_version, created_at, updated_at)
    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s, NOW(), NOW())
    ON CONFLICT (title_norm) DO UPDATE SET
        title_sample = EXCLUDED.title_sample,
        canonical    = EXCLUDED.canonical,
        cor_code     = EXCLUDED.cor_code,
        cor_label    = EXCLUDED.cor_label,
        isco_group   = EXCLUDED.isco_group,
        grid_selector= EXCLUDED.grid_selector,
        study_level  = EXCLUDED.study_level,
        match_confidence = EXCLUDED.match_confidence,
        provider     = EXCLUDED.provider,
        model        = EXCLUDED.model,
        grid_version = EXCLUDED.grid_version,
        updated_at   = NOW()
"""


def write_occupation(conn, group, mapping: OccupationMapping, cor, provider, model, grid_version):
    entry = cor.by_code.get(mapping.cor_code or "")
    selector = mapping.grid_selector.model_dump() if mapping.grid_selector else {}
    with conn.cursor() as cur:
        cur.execute(UPSERT, (
            group["title_norm"], group["sample"][:300],
            (mapping.occupation_canonical or ocupatii.canonical_label(group["parsed"].base))[:300],
            mapping.cor_code or "", (entry.denumire if entry else "")[:300],
            mapping.isco_group or "", json.dumps(selector, ensure_ascii=False),
            mapping.study_level or "", mapping.match_confidence,
            provider, model, grid_version,
        ))
    conn.commit()


def link_postings(conn) -> int:
    """Point every posting at its occupation row, keyed on the parsed title.

    Done in Python rather than SQL because the key is `parse_title()`, which is
    Romanian morphology, not something to reimplement in plpgsql.
    """
    with conn.cursor(row_factory=dict_row) as cur:
        cur.execute("SELECT id, title_norm FROM jobs_occupation")
        by_norm = {r["title_norm"]: r["id"] for r in cur}
        cur.execute("SELECT id, title FROM jobs_jobposting WHERE COALESCE(title,'') <> ''")
        pairs = [(by_norm[k], r["id"]) for r in cur
                 if (k := ocupatii.parse_title(r["title"]).base_norm) in by_norm]
    with conn.cursor() as cur:
        cur.executemany("UPDATE jobs_jobposting SET occupation_id = %s WHERE id = %s", pairs)
    conn.commit()
    return len(pairs)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--provider", default=None)
    ap.add_argument("--model", default=None)
    ap.add_argument("--limit", type=int, default=None, help="process at most N distinct titles")
    ap.add_argument("--slug", default=None, help="only titles containing this string")
    ap.add_argument("--force", action="store_true", help="re-map titles already in the dictionary")
    ap.add_argument("--workers", type=int, default=4)
    ap.add_argument("--max-attempts", type=int, default=4)
    ap.add_argument("--grid-version", default=salary_grid.DEFAULT_VERSION)
    ap.add_argument("--dry-run", action="store_true",
                    help="print what would be sent and written; call no provider, write nothing")
    ap.add_argument("--link-only", action="store_true",
                    help="skip the LLM; just re-point postings at existing occupation rows")
    args = ap.parse_args()

    provider = resolve_provider(args.provider)
    model = resolve_model(provider, args.model)
    system_prompt = MODELS_CONFIG["prompts"].get(PROMPT_KEY)
    if not system_prompt:
        sys.exit(f"models_config.json has no prompts.{PROMPT_KEY}")

    cor = ocupatii.load_cor()
    grid = salary_grid.load_grid(args.grid_version)
    runner = _llm_schema_module()

    with psycopg.connect(DATABASE_URL) as conn:
        if args.link_only:
            print(f"linked {link_postings(conn)} postings to occupations")
            return 0

        groups = load_titles(conn, args.slug)
        if not args.force:
            with conn.cursor() as cur:
                cur.execute("SELECT title_norm FROM jobs_occupation")
                done = {r[0] for r in cur}
            groups = [g for g in groups if g["title_norm"] not in done]
        if args.limit:
            groups = groups[:args.limit]
        if not groups:
            print("nothing to do — every title is already in the dictionary")
            return 0

        covered = sum(g["n"] for g in groups)
        print(f"{len(groups)} distinct titles to map, covering {covered} postings "
              f"| provider={provider} model={model} grid={args.grid_version}")

        def prepare(group):
            parsed = group["parsed"]
            ctx = context_for(group["employer"].most_common(1)[0][0],
                              group["family"].most_common(1)[0][0])
            cor_cands = cor.candidates(parsed.base, k=10)
            grid_cands = grid.candidates(
                parsed.base, anexa=ctx["anexa"], regim=ctx["regim"],
                nivel_administrativ=ctx["nivel_administrativ"], k=12)
            return ctx, cor_cands, grid_cands

        if args.dry_run:
            for group in groups:
                ctx, cor_cands, grid_cands = prepare(group)
                print("\n" + "=" * 78)
                print(f"[{group['n']} postări] {group['sample']!r}")
                print(build_user_message(group["sample"], group["parsed"], ctx, cor_cands, grid_cands))
            print(f"\n(dry run — {len(groups)} titles, no provider called, nothing written)")
            return 0

        generate = make_generator(provider, model, system_prompt)
        stats = Counter()
        cost = 0.0
        started = time.time()

        def work(group):
            """Runs in the worker pool. Returns everything the writer needs."""
            ctx, cor_cands, grid_cands = prepare(group)
            content = build_user_message(group["sample"], group["parsed"], ctx, cor_cands, grid_cands)
            cor_codes = {e.cod_cor for _, e in cor_cands}
            grid_codes = {r.cod for _, r, _n in grid_cands if r.cod}

            def call(text):
                raw, in_tok, out_tok, cached = generate(text)
                mapping = validate(raw, cor_codes, grid_codes, runner.parse_json_response)
                return mapping, in_tok, out_tok, cached

            return runner.generate_with_retry(call, content, max_attempts=args.max_attempts)

        # `imap_unordered` yields (item, future); the DB write stays on this
        # thread because one psycopg connection is not safe to share.
        for group, future in runner.imap_unordered(work, groups, args.workers):
            try:
                mapping, in_tok, out_tok, cached = future.result()
            except Exception as exc:  # noqa: BLE001 — one bad title must not stop the run
                stats["failed"] += 1
                print(f"  ✗ {group['sample'][:44]:46s} {type(exc).__name__}: {str(exc)[:110]}")
                continue
            write_occupation(conn, group, mapping, cor, provider, model, args.grid_version)
            cost += runner.compute_cost(provider, model, in_tok, out_tok, cached)
            stats[mapping.match_confidence] += 1
            stats["ok"] += 1
            if mapping.cor_code:
                stats["with_cor"] += 1
            if mapping.grid_selector and mapping.grid_selector.cod:
                stats["with_grid"] += 1
            print(f"  ✓ {group['sample'][:44]:46s} → {mapping.occupation_canonical[:34]:36s} "
                  f"COR={mapping.cor_code or '—':7s} {mapping.match_confidence}")

        elapsed = time.time() - started
        print(f"\nmapped {stats['ok']}/{len(groups)} in {elapsed:.0f}s  (${cost:.4f})")
        print(f"  with a COR code: {stats['with_cor']} | with a grid row: {stats['with_grid']}"
              f" | failed: {stats['failed']}")
        print("  confidence: " + ", ".join(
            f"{k}={stats[k]}" for k in ("exact", "probabil", "incert", "none") if stats[k]))
        print(f"linked {link_postings(conn)} postings to occupations")
    return 0


if __name__ == "__main__":
    sys.exit(main())
