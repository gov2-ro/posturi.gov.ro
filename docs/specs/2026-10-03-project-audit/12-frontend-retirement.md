# FIX-12 — Retire duplicate public UI and correct architecture docs

Priority: P2. Dependency: FIX-07 PHP regression coverage before removing old UI.
Audit documentation assessment; legacy Django public templates have drifted.

## Problem and scope

PHP is the deployed frontend; Django still carries public routes/templates/feeds
and obsolete Fly deployment artifacts. Generated orientation maps prioritize
that frontend and describe stale route/model coverage as “25% coverage”. The
backlog repeatedly requests porting fixes to a UI users do not see.

## Required behavior

- Inventory actual users/imports of Django public views, comparison tools,
  templates, URL helpers and tests. Retain useful developer/admin comparison
  functionality explicitly; do not delete it because it is not the public site.
  Decide a documented local preview path using PHP + an exported fixture/database.
- Keep Django models, every migration, admin/auth, management commands,
  judet/attachment helpers and processing configuration. Preserve any model URL
  behavior required by admin or tooling; removing routes must not break admin
  links. PostgreSQL table names are a compatibility boundary for export scripts.
- Remove or clearly isolate retired public views/templates/feed code and old
  deployment scaffolding only after call-site evidence and test replacement.
  Do not port skins/mobile changes into another full frontend. Stop documenting
  Fly as current production hosting. No drop-table migration is part of this task.
- Correct README and tracked agent guidance: PHP/SQLite public serving,
  Django/Postgres processing/admin, real index early-stop behavior, new `.pg-card`
  wrappers and current prompt-version selection. Keep any user's uncommitted
  guidance edits intact when integrating changes.
- Regenerate local `.codesight` orientation if the tool supports the actual
  PHP surface, or document its blind spots explicitly. Do not equate generated
  “covered routes/models” with measured line/branch test coverage. Do not commit
  generated ignored files merely to make this package appear complete.
- Add canonical links to the audit/specs/backlog and move completed historical
  tracking to the activity log/archive. Update setup and testing instructions to
  match the actual retained runtime and CI.

## Acceptance and tests

Prove admin login/model screens, migrations, import, attachment extraction,
inference, variant comparison and SQLite export still function. Run Python and
PHP tests; update view tests only when a tested function is intentionally retired
and equivalent production behavior is covered elsewhere. Search references to
removed modules/templates/routes. A clean checkout follows documented commands
to preview the PHP app with a fixture without paid API calls.

## Rollout and completion

This is local/processing cleanup, not a production database migration. Remove
unused deployment assets after inventory, preserving historical docs where
helpful. Keep a reviewable list of removed vs retained components. Track changes
to generated maps separately from actual source architecture.
