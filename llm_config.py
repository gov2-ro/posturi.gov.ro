"""Single source of truth for LLM provider/model/prompt selection.

`models_config.json` holds the catalogue (prompts, providers, per-model
pricing). This module adds the *selection* layer on top of it, with one
precedence rule used by every entry point:

    explicit CLI flag  >  environment variable  >  models_config.json defaults

Environment variables (see `.env.example`):
    LLM_PROVIDER    gemini | openai | anthropic | deepseek
    LLM_MODEL       model id, overriding the provider's default model
    LLM_PROMPT_VERSION  v1 | v2 | v3

An env var set to the empty string (`LLM_PROVIDER=` in .env, which is how the
example file ships) counts as unset — otherwise a blank line in .env would
silently override the config default with "".
"""

from __future__ import annotations

import json
import os
from pathlib import Path

from dotenv import load_dotenv

load_dotenv()

CONFIG_PATH = Path(__file__).resolve().parent / "models_config.json"

with open(CONFIG_PATH, encoding="utf-8") as f:
    MODELS_CONFIG = json.load(f)

PROVIDERS = tuple(MODELS_CONFIG.get("providers", {}))


def _env(name: str) -> str | None:
    """Read an env var, treating blank/whitespace-only as unset."""
    value = (os.getenv(name) or "").strip()
    return value or None


def _defaults() -> dict:
    return MODELS_CONFIG.get("defaults", {})


def resolve_provider(explicit: str | None = None) -> str:
    """CLI flag > $LLM_PROVIDER > models_config.json defaults.provider."""
    provider = explicit or _env("LLM_PROVIDER") or _defaults().get("provider", "gemini")
    if provider not in PROVIDERS:
        raise ValueError(
            f"Unknown provider {provider!r}; known providers: {', '.join(PROVIDERS)}"
        )
    return provider


def resolve_model(provider: str, explicit: str | None = None) -> str:
    """CLI flag > $LLM_MODEL > models_config.json defaults.model[provider].

    $LLM_MODEL is only honoured when it names a model the provider actually
    has — so `LLM_PROVIDER=anthropic LLM_MODEL=gpt-4o-mini` does not silently
    send an OpenAI model id to Anthropic, and switching provider on the command
    line does not require unsetting LLM_MODEL.
    """
    known = MODELS_CONFIG.get("providers", {}).get(provider, {}).get("models", {})
    if explicit:
        return explicit
    env_model = _env("LLM_MODEL")
    if env_model and env_model in known:
        return env_model
    default_model = _defaults().get("model", {}).get(provider)
    if not default_model:
        raise ValueError(f"No default model configured for provider {provider!r}")
    return default_model


def resolve_prompt_version(explicit: str | None = None) -> str:
    """CLI flag > $LLM_PROMPT_VERSION > models_config.json defaults.prompt_version."""
    version = (
        explicit
        or _env("LLM_PROMPT_VERSION")
        or _defaults().get("prompt_version", "v2")
    )
    if version not in MODELS_CONFIG.get("prompts", {}):
        raise ValueError(f"Unknown prompt version {version!r}")
    return version


def resolve(provider: str | None = None, model: str | None = None,
            prompt_version: str | None = None) -> tuple[str, str, str]:
    """Resolve all three at once. Returns (provider, model, prompt_version)."""
    resolved_provider = resolve_provider(provider)
    return (
        resolved_provider,
        resolve_model(resolved_provider, model),
        resolve_prompt_version(prompt_version),
    )
