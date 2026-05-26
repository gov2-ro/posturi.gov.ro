import django
import pytest


@pytest.fixture(scope="session")
def django_db_setup():
    pass


def pytest_configure(config):
    import os
    os.environ.setdefault("DJANGO_SETTINGS_MODULE", "posturi.settings")
