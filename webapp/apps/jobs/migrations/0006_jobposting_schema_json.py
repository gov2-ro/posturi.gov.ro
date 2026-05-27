from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ('jobs', '0005_jobpostingupdate'),
    ]

    operations = [
        migrations.AddField(
            model_name='jobposting',
            name='schema_json',
            field=models.JSONField(blank=True, help_text='LLM-extracted structured sections for display (responsibilities, qualifications, skills, etc.)', null=True),
        ),
    ]
