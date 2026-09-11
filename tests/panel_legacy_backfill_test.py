#!/usr/bin/env python3
"""
Mirror of tests/panel_legacy_backfill_test.php for hosts without php on PATH.
Run: python tests/panel_legacy_backfill_test.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def assert_true(cond: bool, msg: str) -> None:
    if cond:
        print(f"PASS: {msg}")
        return
    print(f"FAIL: {msg}")
    sys.exit(1)


def assert_contains(haystack: str, needle: str, msg: str) -> None:
    assert_true(needle in haystack, msg)


def assert_not_contains(haystack: str, needle: str, msg: str) -> None:
    assert_true(needle not in haystack, msg)


def normalize_email(email: str) -> str:
    return email.strip().lower()


def relationship_has_four_address_data(row: dict) -> bool:
    for field in (
        "external_client_email",
        "local_client_email",
        "local_referent_email",
        "local_client_maildir",
    ):
        if str(row.get(field) or "").strip() != "":
            return True
    if row.get("external_account_id"):
        return True
    return False


def relationship_is_legacy_only(row: dict) -> bool:
    return (not relationship_has_four_address_data(row)) and str(row.get("email") or "").strip() != ""


def relationship_external_client_form_value(row: dict) -> str:
    ext = str(row.get("external_client_email") or "").strip()
    if ext:
        return normalize_email(ext)
    if relationship_is_legacy_only(row):
        return normalize_email(str(row.get("email") or ""))
    return ""


def main() -> None:
    index = (ROOT / "web" / "index.php").read_text(encoding="utf-8")
    helper = (ROOT / "web" / "includes" / "relationship_editor.php").read_text(encoding="utf-8")
    ru = (ROOT / "web" / "lang" / "ru.php").read_text(encoding="utf-8")
    en = (ROOT / "web" / "lang" / "en.php").read_text(encoding="utf-8")
    daemon = (ROOT / "mail-proxy-daemon.py").read_text(encoding="utf-8")

    assert_contains(index, "case 'relationship_backfill':", "relationship_backfill route")
    assert_contains(index, "renderLegacyRelationshipBackfill()", "backfill render wired")
    assert_contains(index, "action=relationship_backfill", "nav/link to backfill")
    assert_contains(helper, "function fetchLegacyRelationshipBacklog", "fetchLegacyRelationshipBacklog")
    assert_contains(helper, "function renderLegacyRelationshipBackfill", "renderLegacyRelationshipBackfill")
    assert_contains(helper, "relationshipIsLegacyOnly($row)", "backlog reuses relationshipIsLegacyOnly")
    assert_contains(helper, "function relationshipIsLegacyOnly", "relationshipIsLegacyOnly still defined")
    assert_contains(helper, "function relationshipMissingFields", "relationshipMissingFields still defined")

    assert_contains(helper, "function relationshipExternalClientFormValue", "prefill helper")
    assert_contains(index, "relationshipExternalClientFormValue($row)", "form uses prefill helper")
    assert_contains(helper, "from=backfill", "Migrate link uses from=backfill")
    assert_contains(index, "($_GET['from'] ?? '') === 'backfill'", "form reads from=backfill GET flag")
    assert_contains(index, "data-prefill-source=", "GET prefill marker on form")
    assert_contains(index, 'name="return_to"', "optional return_to after migrate save")
    assert_contains(index, "function handleRelationshipSave():", "single save handler retained")
    assert_not_contains(index, "function handleRelationshipMigrate", "no separate migrate save handler")
    assert_not_contains(helper, "$_SESSION['relationship_draft']", "no session draft state")
    assert_not_contains(index, "$_SESSION['relationship_draft']", "no session draft in index")

    combined = index + helper
    assert_not_contains(combined, "migrate_all", "no migrate_all endpoint")
    assert_not_contains(combined, "Migrate all", "no Migrate all UI")
    assert_not_contains(helper, "guessLocal", "no local-address guess helper")
    assert_not_contains(helper, "inferLocal", "no local-address infer helper")

    assert_contains(ru, "'nav.backfill'", "RU nav.backfill")
    assert_contains(en, "'nav.backfill'", "EN nav.backfill")
    assert_contains(ru, "'backfill.count'", "RU backfill.count")
    assert_contains(en, "'backfill.count'", "EN backfill.count")
    assert_contains(en, "still on the legacy model", "EN progress sentence")

    assert_not_contains(daemon, "relationship_lookup", "daemon still ignores relationship_lookup")
    assert_not_contains(daemon, "relationship_backfill", "daemon has no backfill wiring")

    # Confirm PHP helper source still contains the PROMPT-56 predicates (not re-derived elsewhere)
    assert_true(
        re.search(r"function relationshipIsLegacyOnly\(array \$row\): bool", helper) is not None,
        "PHP relationshipIsLegacyOnly signature present",
    )
    assert_true(
        "return !relationshipHasFourAddressData($row)" in helper,
        "legacy-only still uses relationshipHasFourAddressData",
    )

    legacy_row = {
        "id": 101,
        "email": "legacy.client@partner.com",
        "external_client_email": None,
        "local_client_email": None,
        "local_referent_email": None,
        "external_account_id": None,
        "local_client_maildir": None,
        "active": 1,
    }
    other_legacy = {
        "id": 102,
        "email": "other@partner.com",
        "external_client_email": "",
        "local_client_email": "",
        "local_referent_email": "",
        "external_account_id": None,
        "local_client_maildir": "",
        "active": 1,
    }
    complete_row = {
        "id": 200,
        "email": "done@partner.com",
        "external_client_email": "done@partner.com",
        "local_client_email": "done@local.loc",
        "local_referent_email": "ref@local.loc",
        "external_account_id": 7,
        "local_client_maildir": "/var/vmail/local.loc/done/",
        "active": 1,
    }

    assert_true(relationship_is_legacy_only(legacy_row), "legacy row is legacy-only")
    assert_true(relationship_is_legacy_only(other_legacy), "second legacy row is legacy-only")
    assert_true(not relationship_is_legacy_only(complete_row), "complete row is not legacy-only")

    prefill = relationship_external_client_form_value(legacy_row)
    assert_true(prefill == "legacy.client@partner.com", "prefill carries legacy email")

    before = [legacy_row, other_legacy, complete_row]
    before_legacy = [r for r in before if relationship_is_legacy_only(r)]
    assert_true(len(before_legacy) == 2, "before: 2 of 3 still legacy")

    migrated = dict(legacy_row)
    migrated.update(
        {
            "external_client_email": "legacy.client@partner.com",
            "local_client_email": "legacy.client@local.loc",
            "local_referent_email": "ref1@local.loc",
            "external_account_id": 3,
            "local_client_maildir": "/var/vmail/local.loc/legacy.client/",
            "email": "legacy.client@partner.com",
        }
    )
    assert_true(not relationship_is_legacy_only(migrated), "after save: migrated row leaves backlog predicate")

    after = [migrated, other_legacy, complete_row]
    after_legacy = [r for r in after if relationship_is_legacy_only(r)]
    assert_true(len(after_legacy) == 1, "after: 1 of 3 still legacy (N-1)")
    assert_true(int(after_legacy[0]["id"]) == 102, "after: remaining legacy is the unsaved row")

    print("OK: panel legacy backfill checks passed (python mirror)")


if __name__ == "__main__":
    main()
