#!/usr/bin/env python3
"""
DEV-56 option C: MariaDB SET STATEMENT max_statement_time for supervisor SELECTs.

Used only from the main-thread supervisor paths in mail-proxy-daemon.py.
Worker/poller DB access must not pass max_statement_time (no SET STATEMENT wrapper).
"""

from __future__ import annotations

from typing import Any, Optional

import mysql.connector
from mysql.connector import Error as MySQLError

# Per-statement wall limit (seconds) for supervisor SELECTs on MariaDB 10.11.
SUPERVISOR_MAX_STATEMENT_TIME_SEC = 12.0
SUPERVISOR_MAX_STATEMENT_MIN_SEC = 1.0
# Reserve budget for joins / connector slop after an in-flight query returns.
SUPERVISOR_MAX_STATEMENT_BUDGET_MARGIN_SEC = 2.0

MARIADB_ER_STATEMENT_TIMEOUT = 1969


def format_set_statement_select(select_sql: str, max_seconds: float) -> str:
    """Wrap a SELECT in SET STATEMENT max_statement_time=N FOR … (MariaDB)."""
    sql = select_sql.strip()
    if not sql.upper().startswith('SELECT'):
        raise ValueError('SET STATEMENT wrapper requires a SELECT statement')
    if max_seconds <= 0:
        raise ValueError('max_statement_time must be positive')
    return f'SET STATEMENT max_statement_time={max_seconds:g} FOR {sql}'


def is_mariadb_statement_timeout(exc: BaseException) -> bool:
    """True when MariaDB aborted the statement (error 1969 / ER_STATEMENT_TIMEOUT)."""
    return isinstance(exc, MySQLError) and getattr(exc, 'errno', None) == MARIADB_ER_STATEMENT_TIMEOUT


def supervisor_max_statement_seconds(
    default_cap: float = SUPERVISOR_MAX_STATEMENT_TIME_SEC,
    shutdown_deadline_mono: Optional[float] = None,
    remaining_fn=None,
) -> float:
    """
    Cap for one supervisor query: default constant, tightened to remaining
    shutdown budget minus margin when a deadline is armed.
    """
    cap = float(default_cap)
    if shutdown_deadline_mono is None:
        return cap
    if remaining_fn is None:
        raise ValueError('remaining_fn required when deadline is set')
    remaining = remaining_fn(shutdown_deadline_mono)
    if remaining <= SUPERVISOR_MAX_STATEMENT_BUDGET_MARGIN_SEC:
        return SUPERVISOR_MAX_STATEMENT_MIN_SEC
    tightened = remaining - SUPERVISOR_MAX_STATEMENT_BUDGET_MARGIN_SEC
    return max(SUPERVISOR_MAX_STATEMENT_MIN_SEC, min(cap, tightened))


def rollback_connection_quietly(conn: Any) -> None:
    """Roll back implicit read transaction before returning a pooled connection."""
    if conn is None:
        return
    try:
        conn.rollback()
    except Exception:
        pass