#!/usr/bin/env python3
"""Verify MySQLConnector accepts timeout kwargs (no live DB connection)."""

from __future__ import annotations

import os
import sys

import mysql.connector


def main() -> int:
    expect_fail = os.environ.get('EXPECT_UNSUPPORTED_TIMEOUTS') == '1'
    cnx = mysql.connector.connection.MySQLConnection()
    try:
        cnx.config(
            user='probe',
            password='probe',
            host='127.0.0.1',
            database='probe',
            port=3306,
            connect_timeout=3,
            read_timeout=5,
            write_timeout=5,
        )
    except AttributeError as exc:
        print(f'UNSUPPORTED_TYPE:{exc}', flush=True)
        return 0 if expect_fail else 2
    except mysql.connector.errors.Error as exc:
        print(f'CONFIG_ERROR:{exc}', flush=True)
        return 3
    print('CONFIG_TIMEOUT_ARGS_OK', flush=True)
    return 0 if not expect_fail else 4


if __name__ == '__main__':
    raise SystemExit(main())