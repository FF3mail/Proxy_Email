#!/usr/bin/env python3
"""
PROMPT-73 — per-referent mode override unit tests.
"""

from __future__ import annotations

import importlib.util
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from relationship_routing import (
    InboundRoutingMode,
    OutboundRoutingMode,
    OutboundWatchMode,
    ReferentEffectiveModes,
    resolve_referent_effective_modes,
)


class ReferentModeResolutionTest(unittest.TestCase):
    def test_null_override_inherits_global(self) -> None:
        modes = resolve_referent_effective_modes(
            referent_id=1,
            inbound_override=None,
            outbound_override=None,
            watch_override=None,
            global_inbound=InboundRoutingMode.SHADOW,
            global_outbound=OutboundRoutingMode.SHADOW,
            global_watch=OutboundWatchMode.DUAL,
        )
        self.assertEqual(modes.inbound_routing, InboundRoutingMode.SHADOW)
        self.assertEqual(modes.outbound_routing, OutboundRoutingMode.SHADOW)
        self.assertEqual(modes.outbound_watch, OutboundWatchMode.DUAL)

    def test_non_null_override_wins(self) -> None:
        modes = resolve_referent_effective_modes(
            referent_id=2,
            inbound_override=InboundRoutingMode.RELATIONSHIP_LIVE,
            outbound_override=InboundRoutingMode.LEGACY,
            watch_override=OutboundWatchMode.REFERENT_ONLY,
            global_inbound=InboundRoutingMode.SHADOW,
            global_outbound=OutboundRoutingMode.SHADOW,
            global_watch=OutboundWatchMode.DUAL,
        )
        self.assertEqual(modes.inbound_routing, InboundRoutingMode.RELATIONSHIP_LIVE)
        self.assertEqual(modes.outbound_routing, OutboundRoutingMode.LEGACY)
        self.assertEqual(modes.outbound_watch, OutboundWatchMode.REFERENT_ONLY)

    def test_relationship_only_watch_fails_closed_per_referent(self) -> None:
        modes = resolve_referent_effective_modes(
            referent_id=3,
            inbound_override=None,
            outbound_override=None,
            watch_override=OutboundWatchMode.RELATIONSHIP_ONLY,
            global_inbound=InboundRoutingMode.SHADOW,
            global_outbound=OutboundRoutingMode.SHADOW,
            global_watch=OutboundWatchMode.DUAL,
        )
        self.assertEqual(modes.outbound_watch, OutboundWatchMode.REFERENT_ONLY)

    def test_relationship_only_watch_allowed_with_live_routing_override(self) -> None:
        modes = resolve_referent_effective_modes(
            referent_id=4,
            inbound_override=None,
            outbound_override=OutboundRoutingMode.RELATIONSHIP_LIVE,
            watch_override=OutboundWatchMode.RELATIONSHIP_ONLY,
            global_inbound=InboundRoutingMode.SHADOW,
            global_outbound=OutboundRoutingMode.SHADOW,
            global_watch=OutboundWatchMode.REFERENT_ONLY,
        )
        self.assertEqual(modes.outbound_watch, OutboundWatchMode.RELATIONSHIP_ONLY)


class ProxyDaemonWatchModeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        watchdog_mod = types.ModuleType('watchdog')
        watchdog_events = types.ModuleType('watchdog.events')
        watchdog_events.FileSystemEventHandler = object
        watchdog_observers = types.ModuleType('watchdog.observers')
        watchdog_observers.Observer = MagicMock
        sys.modules.setdefault('watchdog', watchdog_mod)
        sys.modules.setdefault('watchdog.events', watchdog_events)
        sys.modules.setdefault('watchdog.observers', watchdog_observers)

        spec = importlib.util.spec_from_file_location(
            'mail_proxy_daemon_p73',
            str(ROOT / 'mail-proxy-daemon.py'),
        )
        cls.mpd = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(cls.mpd)

    def _make_daemon(self):
        daemon = MagicMock()
        ProxyDaemon = self.mpd.ProxyDaemon
        daemon._watched_referent_ids = set()
        daemon._watched_relationship_ids = set()
        daemon._watched_lock = __import__('threading').Lock()
        daemon._referent_effective_modes = {}
        daemon._mail_handler = MagicMock()
        daemon._mail_handler._relationship_lookup = MagicMock()
        daemon._mail_handler._relationship_lookup.list_watch_targets_for_referent.return_value = []
        daemon._setup_watchdog_for_referent = MagicMock()
        daemon._scan_existing_outgoing = MagicMock()
        daemon._setup_watchdog_for_relationship = MagicMock()
        daemon._scan_existing_outgoing_for_relationship = MagicMock()
        daemon._ensure_referent_modes_cached = (
            lambda ref: ProxyDaemon._ensure_referent_modes_cached(daemon, ref)
        )
        daemon._enrich_referent_row = (
            lambda ref: ProxyDaemon._enrich_referent_row(daemon, ref)
        )
        daemon._register_watches_for_referent = (
            lambda ref: ProxyDaemon._register_watches_for_referent(daemon, ref)
        )
        return daemon

    def test_referent_only_effective_skips_relationship_watches(self) -> None:
        daemon = self._make_daemon()
        ref = {
            'id': 10,
            'username': 'R10',
            'local_inbox': 'r10@test.loc',
            'local_outbox': '/var/vmail/r10/Maildir',
            'inbound_routing_mode': None,
            'outbound_routing_mode': None,
            'outbound_watch_mode': 'referent_only',
        }
        with patch.object(self.mpd, 'OUTBOUND_WATCH_MODE', OutboundWatchMode.DUAL):
            self.mpd.ProxyDaemon._register_watches_for_referent(daemon, ref)
        daemon._setup_watchdog_for_referent.assert_called_once()
        daemon._setup_watchdog_for_relationship.assert_not_called()

    def test_relationship_only_effective_skips_referent_watch(self) -> None:
        daemon = self._make_daemon()
        ref = {
            'id': 11,
            'username': 'R11',
            'local_inbox': 'r11@test.loc',
            'local_outbox': '/var/vmail/r11/Maildir',
            'inbound_routing_mode': None,
            'outbound_routing_mode': 'relationship_live',
            'outbound_watch_mode': 'relationship_only',
        }
        target = {
            'local_client_maildir': '/var/vmail/c11/Maildir',
            'relationship': {'relationship_id': 101, 'referent_id': 11},
        }
        daemon._mail_handler._relationship_lookup.list_watch_targets_for_referent.return_value = [
            target
        ]
        with patch.object(self.mpd, 'OUTBOUND_WATCH_MODE', OutboundWatchMode.DUAL):
            self.mpd.ProxyDaemon._register_watches_for_referent(daemon, ref)
        daemon._setup_watchdog_for_referent.assert_not_called()
        daemon._setup_watchdog_for_relationship.assert_called_once_with(target)

    def test_override_change_does_not_alter_cached_modes(self) -> None:
        daemon = self._make_daemon()
        ref_v1 = {
            'id': 12,
            'username': 'R12',
            'local_inbox': 'r12@test.loc',
            'local_outbox': '/var/vmail/r12/Maildir',
            'inbound_routing_mode': None,
            'outbound_routing_mode': None,
            'outbound_watch_mode': 'referent_only',
        }
        with patch.object(self.mpd, 'OUTBOUND_WATCH_MODE', OutboundWatchMode.DUAL):
            modes_v1 = self.mpd.ProxyDaemon._ensure_referent_modes_cached(
                daemon, ref_v1
            )
        ref_v2 = dict(ref_v1)
        ref_v2['outbound_watch_mode'] = 'relationship_only'
        ref_v2['outbound_routing_mode'] = 'relationship_live'
        with patch.object(self.mpd, 'OUTBOUND_WATCH_MODE', OutboundWatchMode.DUAL):
            modes_v2 = self.mpd.ProxyDaemon._ensure_referent_modes_cached(
                daemon, ref_v2
            )
        self.assertEqual(modes_v1, modes_v2)
        self.assertEqual(modes_v2.outbound_watch, OutboundWatchMode.REFERENT_ONLY)

    def test_collision_guard_still_applies_under_custom_watch_mode(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            shared_new = Path(tmp) / 'shared' / 'Maildir' / 'new'
            shared_new.mkdir(parents=True)
            shared_path = str(shared_new)

            daemon = MagicMock()
            ProxyDaemon = self.mpd.ProxyDaemon
            daemon._validate_relationship_maildir_for_watch = (
                lambda maildir, relationship_id: (
                    ProxyDaemon._validate_relationship_maildir_for_watch(
                        daemon, maildir, relationship_id
                    )
                )
            )
            daemon._ensure_observer_started = MagicMock()
            daemon._observer = MagicMock()
            daemon._smtp_task_queue = MagicMock()
            daemon._db = MagicMock()
            daemon._mail_handler = MagicMock()
            daemon._mail_handler._relationship_lookup = MagicMock()
            daemon._watched_lock = __import__('threading').Lock()
            daemon._watched_relationship_ids = set()
            daemon._relationship_id_to_watch_path = {}
            daemon._watch_path_relationship_ids = {}
            daemon._relationship_observer_paths = set()
            daemon._referent_path_registry = {5: shared_path}
            daemon._referent_effective_modes = {
                5: ReferentEffectiveModes(
                    referent_id=5,
                    inbound_routing=InboundRoutingMode.SHADOW,
                    outbound_routing=OutboundRoutingMode.SHADOW,
                    outbound_watch=OutboundWatchMode.RELATIONSHIP_ONLY,
                )
            }
            daemon._register_relationship_watch_coverage = (
                lambda relationship_id, path_str, observer_scheduled=False: (
                    ProxyDaemon._register_relationship_watch_coverage(
                        daemon,
                        relationship_id,
                        path_str,
                        observer_scheduled=observer_scheduled,
                    )
                )
            )
            daemon._referent_id_for_watch_path = (
                lambda path_str: ProxyDaemon._referent_id_for_watch_path(
                    daemon, path_str
                )
            )
            daemon._referent_handler_data = (
                lambda referent_id: ProxyDaemon._referent_handler_data(
                    daemon, referent_id
                )
            )

            target = {
                'local_client_maildir': str(shared_new.parent),
                'relationship': {
                    'relationship_id': 77,
                    'referent_id': 5,
                },
            }
            with patch.object(self.mpd.logger, 'warning') as log_warning:
                ProxyDaemon._setup_watchdog_for_relationship(daemon, target)

            daemon._observer.schedule.assert_not_called()
            self.assertIn(77, daemon._watched_relationship_ids)
            warn_args = log_warning.call_args[0]
            self.assertIn('OUTBOUND_WATCH', warn_args[0])


if __name__ == '__main__':
    unittest.main()
