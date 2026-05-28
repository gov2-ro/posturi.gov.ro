from datetime import date

from django import template

register = template.Library()


@register.filter
def days_until(value):
    """Return number of days from today until value (a date). Negative = already past."""
    if not value:
        return None
    today = date.today()
    target = value.date() if hasattr(value, "date") else value
    return (target - today).days


_WORK_TYPE_LABELS = {
    "norma_intreaga": "Normă întreagă",
    "norma_partiala": "Normă parțială",
    "schimburi": "Schimburi",
}


@register.filter
def work_type_label(value):
    return _WORK_TYPE_LABELS.get(value, value)


@register.filter
def dict_get(d, key):
    """Look up a dict value by variable key: {{ mydict|dict_get:key }}"""
    if not isinstance(d, dict):
        return None
    return d.get(key)


@register.filter
def as_list(value):
    """Wrap a scalar in a list so templates can use `in` membership tests."""
    if value is None or value == "":
        return []
    if isinstance(value, list):
        return value
    return [value]


@register.simple_tag(takes_context=True)
def querystring(context, **kwargs):
    """Return current query string with specified keys replaced/removed (None removes)."""
    params = context["request"].GET.copy()
    for key, value in kwargs.items():
        if value is None:
            params.pop(key, None)
        else:
            params[key] = str(value)
    return params.urlencode()


@register.simple_tag(takes_context=True)
def feed_url(context, filename):
    """Return /filename?<current querystring> for feed export links."""
    qs = context["request"].GET.urlencode()
    return f"/{filename}?{qs}" if qs else f"/{filename}"
