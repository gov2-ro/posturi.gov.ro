"""Tests for the rewritten variant_comparison view and _section_status helper."""
import pytest
from django.test import Client

from apps.jobs.models import Employer, JobPosting, JobPostingSchemaVariant
from apps.jobs.views import _section_status


# ---------------------------------------------------------------------------
# Unit tests for _section_status
# ---------------------------------------------------------------------------

class TestSectionStatus:

    def test_all_none_is_all_null(self):
        assert _section_status([None, None, None], "responsibilities") == "all_null"

    def test_empty_string_treated_as_null(self):
        assert _section_status(["", None, ""], "responsibilities") == "all_null"

    def test_identical_strings_is_agree(self):
        assert _section_status(["Studii superioare", "Studii superioare"], "educationRequirements") == "agree"

    def test_case_whitespace_normalization_agree(self):
        # Differ only in case and extra whitespace
        assert _section_status(["Studii  SUPERIOARE", "studii superioare"], "educationRequirements") == "agree"

    def test_bullet_stripping_agree(self):
        # One variant has bullet list, other is prose — normalize to same
        assert _section_status(["- item one\n- item two", "item one item two"], "responsibilities") == "agree"

    def test_partial_some_null(self):
        assert _section_status(["Studii superioare", None, "Studii superioare"], "educationRequirements") == "partial"

    def test_diverge_two_distinct_non_null(self):
        assert _section_status(["Studii superioare", "Studii medii"], "educationRequirements") == "diverge"

    def test_diverge_with_some_null(self):
        # ≥2 distinct non-null → diverge regardless of nulls
        assert _section_status(["Studii superioare", None, "Studii medii"], "educationRequirements") == "diverge"

    def test_dict_equality_agree(self):
        d1 = {"minValue": 4500, "maxValue": 6000, "currency": "RON", "unitText": "MONTH"}
        d2 = {"minValue": 4500, "maxValue": 6000, "currency": "RON", "unitText": "MONTH"}
        assert _section_status([d1, d2], "baseSalary") == "agree"

    def test_dict_key_order_independent(self):
        d1 = {"minValue": 4500, "currency": "RON", "maxValue": 6000, "unitText": "MONTH"}
        d2 = {"unitText": "MONTH", "maxValue": 6000, "minValue": 4500, "currency": "RON"}
        assert _section_status([d1, d2], "baseSalary") == "agree"

    def test_dict_diverge(self):
        d1 = {"minValue": 4500, "currency": "RON"}
        d2 = {"minValue": 5000, "currency": "RON"}
        assert _section_status([d1, d2], "baseSalary") == "diverge"

    def test_single_value_agree(self):
        assert _section_status(["some value"], "responsibilities") == "agree"

    def test_single_value_with_null_partial(self):
        assert _section_status(["some value", None], "responsibilities") == "partial"
