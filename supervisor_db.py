#!/usr/bin/env python3
"""
DEV-56 option C: MariaDB SET STATEMENT max_statement_time for capped SELECTs.

Supervisor (main thread) and ImapPoller referent/account loads use SET STATEMENT.
Other worker DB paths must not wrap statements (no SET STATEMENT).
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


def poller_db_permitted(
    stop_event: Any,
    shutdown_signaled_mono: Optional[float] = None,
) -> bool:
    """Whether ImapPoller may start a new MariaDB statement."""
    if stop_event is not None and stop_event.is_set():
        return False
    if shutdown_signaled_mono is not None:
        return False
    return True


def poller_statement_cap(
    shutdown_deadline_mono: Optional[float],
    shutdown_signaled_mono: Optional[float],
    stop_event: Any,
    remaining_fn,
    default_cap: float = SUPERVISOR_MAX_STATEMENT_TIME_SEC,
) -> float:
    """
    Per-query cap for ImapPoller: default until shutdown is signalled, then
    tightened to remaining shutdown budget (same rules as supervisor).
    """
    stopping = shutdown_signaled_mono is not None or (
        stop_event is not None and stop_event.is_set()
    )
    if not stopping:
        return float(default_cap)
    if shutdown_deadline_mono is None:
        return float(default_cap)
    return supervisor_max_statement_seconds(
        default_cap, shutdown_deadline_mono, remaining_fn
    )


def release_mysql_connection(conn: Any, *, discard: bool = False) -> None:
    """
    Return a pooled connection to the pool, or discard it during shutdown.

    PooledMySQLConnection.close() always calls reset_session() (server round
    trip) before add_connection(); that can block when MariaDB is stalled.
    When discard=True, shut down the underlying socket via the public
    MySQLConnection.shutdown() API and drop the wrapper without ping/reset.
    """
    if conn is None:
        return
    if not discard:
        try:
            conn.close()
        except Exception:
            pass
        return
    rollback_connection_quietly(conn)
    inner = getattr(conn, '_cnx', None)
    if inner is not None:
        try:
            inner.shutdown()
        except Exception:
            pass
        try:
            setattr(conn, '_cnx', None)
        except Exception:
            pass
        return
    try:
        conn.close()
    except Exception:
        pass