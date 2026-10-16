from django.urls import path

from . import views

# Developer/admin tools only. The public site (browse, detail, stats, feeds) is
# the PHP app in webapp-php/; its Django twin was retired (FIX-12).
urlpatterns = [
    path("job/<int:pk>/variants/", views.variant_comparison, name="variant_comparison"),
    path("llm-variante/", views.llm_variants_dashboard, name="llm_variants_dashboard"),
]
