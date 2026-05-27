from django.db import migrations, models
import django.db.models.deletion


class Migration(migrations.Migration):

    dependencies = [
        ('jobs', '0006_jobposting_schema_json'),
    ]

    operations = [
        migrations.CreateModel(
            name='JobPostingSchemaVariant',
            fields=[
                ('id', models.BigAutoField(auto_created=True, primary_key=True, serialize=False, verbose_name='ID')),
                ('provider', models.CharField(max_length=50)),
                ('model', models.CharField(max_length=100)),
                ('prompt_version', models.CharField(default='v1', max_length=20)),
                ('schema_json', models.JSONField()),
                ('input_tokens', models.IntegerField(blank=True, null=True)),
                ('output_tokens', models.IntegerField(blank=True, null=True)),
                ('cost_usd', models.DecimalField(blank=True, decimal_places=8, max_digits=12, null=True)),
                ('latency_ms', models.IntegerField(blank=True, null=True)),
                ('created_at', models.DateTimeField(auto_now_add=True)),
                ('posting', models.ForeignKey(on_delete=django.db.models.deletion.CASCADE, related_name='schema_variants', to='jobs.jobposting')),
            ],
            options={
                'verbose_name': 'Variantă schemă LLM',
                'verbose_name_plural': 'Variante schemă LLM',
                'ordering': ['-created_at'],
            },
        ),
        migrations.AddConstraint(
            model_name='jobpostingschemavariant',
            constraint=models.UniqueConstraint(fields=('posting', 'provider', 'model', 'prompt_version'), name='unique_schema_variant'),
        ),
    ]
