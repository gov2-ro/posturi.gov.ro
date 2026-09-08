# Project Context

This is a python project using django with django.

The API has 13 routes. See .codesight/routes.md for the full route map with methods, paths, and tags.
The database has 7 models. See .codesight/schema.md for the full schema with fields, types, and relations.

High-impact files (most imported, changes here affect many other files):
- webapp/apps/jobs/models.py (imported by 12 files)
- webapp/apps/jobs/views.py (imported by 3 files)
- boilerplate.py (imported by 2 files)
- schema_models.py (imported by 1 files)
- webapp/apps/jobs/management/commands/infer_postings.py (imported by 1 files)

Required environment variables (no defaults):
- ANTHROPIC_API_KEY (webapp/.env.example)
- GOOGLE_API_KEY (webapp/.env.example)
- OPENAI_API_KEY (webapp/.env.example)

See .codesight/skills.md for additional skills context.

Read .codesight/wiki/index.md for orientation (WHERE things live). Then read actual source files before implementing. Wiki articles are navigation aids, not implementation guides.
Read .codesight/CODESIGHT.md for the complete AI context map including all routes, schema, components, libraries, config, middleware, and dependency graph.
