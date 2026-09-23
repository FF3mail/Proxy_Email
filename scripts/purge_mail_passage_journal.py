#!/usr/bin/env python3
"""
Daily purge of mail_passage_journal rows older than 1 year (PROMPT-79.2 / ADR-001).

Timestamps in the table are application-UTC naive DATETIME values. Comparison
uses an application-computed UTC cutoff (not MySQL NOW()).

Usage:
  python3 scripts/purge_mail_passage_journal.py

Cron example (daily 03:15, after optional mysqldump at 02:00 — see docs/guide/09):
  15 3 * * * root /usr/bin/python3 /usr/local/bin/purge_mail_passage_journal.py \\
      >> /var/log/mail-proxy/journal-purge.log 2>&1

Reads DB credentials from /etc/mail-proxy/db.conf (same as the daemon).
"""

from __future__ import annotations

import argparse
import configparser
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

try:
    import mysql.connector
except ImportError:
    print('mysql-connector-python is required', file=sys.stderr)
    sys.exit(2)

DEFAULT_DB_CONF = '/etc/mail-proxy/db.conf'
RETENTION_DAYS = 365


def utc_now_naive() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None, microsecond=0)


def load_db_config(path: str) -> dict:
    parser = configparser.ConfigParser()
    if not parser.read(path):
        raise FileNotFoundError(f'Cannot read DB conf: {path}')
    section = 'database' if parser.has_section('database') else parser.sections()[0]
    cfg = parser[section]
    return {
        'host': cfg.get('host', 'localhost'),
        'port': int(cfg.get('port', '3306')),
        'user': cfg.get('user'),
        'password': cfg.get('password'),
        'database': cfg.get('database', 'mail_proxy'),
    }


def purge(*, db_conf: str, retention_days: int = RETENTION_DAYS, dry_run: bool = False) -> int:
    cutoff = utc_now_naive() - timedelta(days=retention_days)
    cutoff_s = cutoff.strftime('%Y-%m-%d %H:%M:%S')
    cfg = load_db_config(db_conf)
    conn = mysql.connector.connect(
        host=cfg['host'],
        port=cfg['port'],
        user=cfg['user'],
        password=cfg['password'],
        database=cfg['database'],
    )
    try:
        cursor = conn.cursor()
        if dry_run:
            cursor.execute(
                'SELECT COUNT(*) FROM mail_passage_journal WHERE event_ts < %s',
                (cutoff_s,),
            )
            count = int(cursor.fetchone()[0])
            print(f'dry-run: would delete {count} rows with event_ts < {cutoff_s} UTC')
            return count
        cursor.execute(
            'DELETE FROM mail_passage_journal WHERE event_ts < %s',
            (cutoff_s,),
        )
        conn.commit()
        deleted = int(cursor.rowcount)
        print(f'deleted {deleted} rows with event_ts < {cutoff_s} UTC')
        return deleted
    finally:
        conn.close()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='Purge mail_passage_journal older than 1 year')
    parser.add_argument('--db-conf', default=DEFAULT_DB_CONF)
    parser.add_argument('--retention-days', type=int, default=RETENTION_DAYS)
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args(argv)
    try:
        purge(db_conf=args.db_conf, retention_days=args.retention_days, dry_run=args.dry_run)
    except Exception as exc:
        print(f'purge failed: {exc}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
