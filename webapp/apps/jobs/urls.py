from django.urls import path

from . import views

urlpatterns = [
    path("", views.job_list, name="job_list"),
    path("job/<int:pk>/", views.job_detail, name="job_detail"),
]
