from django import template

register = template.Library()


@register.filter
def dict_get(d, key):
    """Look up a dict value by variable key: {{ mydict|dict_get:key }}"""
    if not isinstance(d, dict):
        return None
    return d.get(key)
