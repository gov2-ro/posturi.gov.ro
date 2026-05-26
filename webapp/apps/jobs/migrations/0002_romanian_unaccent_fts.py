"""Add romanian_unaccent text search configuration.

Creates a custom FTS config based on 'simple' with the unaccent filter applied,
so that searches for 'conditii' match 'condiții' and vice versa.
"""
from django.db import migrations


class Migration(migrations.Migration):

    dependencies = [
        ("jobs", "0001_initial"),
    ]

    operations = [
        migrations.RunSQL(
            sql="""
                CREATE EXTENSION IF NOT EXISTS unaccent;

                CREATE TEXT SEARCH CONFIGURATION romanian_unaccent (COPY = simple);
                ALTER TEXT SEARCH CONFIGURATION romanian_unaccent
                    ALTER MAPPING FOR hword, hword_part, word
                    WITH unaccent, simple;
            """,
            reverse_sql="""
                DROP TEXT SEARCH CONFIGURATION IF EXISTS romanian_unaccent;
            """,
        ),
    ]
