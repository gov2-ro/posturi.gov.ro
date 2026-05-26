from django.urls import path

from . import views

urlpatterns = [
    path("", views.job_list, name="job_list"),
    path("job/<int:pk>/", views.job_detail, name="job_detail"),
    path("posturi.json", views.job_json, name="job_json"),
    path("posturi.atom", views.JobPostingFeed(), name="job_feed"),
]
