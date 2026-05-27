from django.urls import path

from . import views

urlpatterns = [
    path("", views.job_list, name="job_list"),
    path("job/<int:pk>/", views.job_detail, name="job_detail"),
    path("despre/", views.about, name="about"),
    path("statistici/", views.stats_dashboard, name="stats_dashboard"),
    path("statistici.json", views.stats_json, name="stats_json"),
    path("posturi.json", views.job_json, name="job_json"),
    path("posturi.atom", views.JobPostingFeed(), name="job_feed"),
    path("posturi.ics", views.job_ical, name="job_ical"),
]
