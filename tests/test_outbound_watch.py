#!/usr/bin/env python3
"""
PROMPT-66 Stage 2c — outbound watch mode unit tests.
"""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from relationship_lookup import ClientRelationshipDTO
from relationship_routing import (
    OutboundRoutingMode,
    OutboundWatchMode,
    ReferentEffectiveModes,
    InboundRoutingMode,
    parse_outbound_watch_mode,
    plan_outbound_delivery,
    resolve_effective_outbound_watch_mode,
)


class ParseOutboundWatchModeTest(unittest.TestCase):
    def test_default_referent_only(self) -> None:
        self.assertEqual(
            parse_outbound_watch_mode({}),
            OutboundWatchMode.REFERENT_ONLY,
        )

    def test_dual(self) -> None:
        self.assertEqual(
            parse_outbound_watch_mode({'OUTBOUND_WATCH_MODE': 'dual'}),
            OutboundWatchMode.DUAL,
        )

    def test_relationship_only(self) -> None:
        self.assertEqual(
            parse_outbound_watch_mode(
                {'OUTBOUND_WATCH_MODE': 'relationship_only'}
            ),
            OutboundWatchMode.RELATIONSHIP_ONLY,
        )


class ResolveEffectiveWatchModeTest(unittest.TestCase):
    def test_relationship_only_with_shadow_fails_closed(self) -> None:
        resolved = resolve_effective_outbound_watch_mode(
            watch_mode=OutboundWatchMode.RELATIONSHIP_ONLY,
            routing_mode=OutboundRoutingMode.SHADOW,
        )
        self.assertEqual(resolved, OutboundWatchMode.REFERENT_ONLY)

    def test_relationship_only_with_legacy_fails_closed(self) -> None:
        resolved = resolve_effective_outbound_watch_mode(
            watch_mode=OutboundWatchMode.RELATIONSHIP_ONLY,
            routing_mode=OutboundRoutingMode.LEGACY,
        )
        self.assertEqual(resolved, OutboundWatchMode.REFERENT_ONLY)

    def test_relationship_only_with_live_allowed(self) -> None:
        resolved = resolve_effective_outbound_watch_mode(
            watch_mode=OutboundWatchMode.RELATIONSHIP_ONLY,
            routing_mode=OutboundRoutingMode.RELATIONSHIP_LIVE,
        )
        self.assertEqual(resolved, OutboundWatchMode.RELATIONSHIP_ONLY)

    def test_dual_unchanged_with_shadow(self) -> None:
        resolved = resolve_effective_outbound_watch_mode(
            watch_mode=OutboundWatchMode.DUAL,
            routing_mode=OutboundRoutingMode.SHADOW,
        )
        self.assertEqual(resolved, OutboundWatchMode.DUAL)


class RelationshipWatchRegistryTest(unittest.TestCase):
    """ProxyDaemon relationship watch registration and path dedup."""

    @classmethod
    def setUpClass(cls) -> None:
        import importlib.util
        import types

        watchdog_mod = types.ModuleType('watchdog')
        watchdog_events = types.ModuleType('watchdog.events')
        watchdog_events.FileSystemEventHandler = object
        watchdog_observers = types.ModuleType('watchdog.observers')
        watchdog_observers.Observer = MagicMock
        sys.modules.setdefault('watchdog', watchdog_mod)
        sys.modules.setdefault('watchdog.events', watchdog_events)
        sys.modules.setdefault('watchdog.observers', watchdog_observers)

        spec = importlib.util.spec_from_file_location(
            'mail_proxy_daemon_under_test',
            str(ROOT / 'mail-proxy-daemon.py'),
        )
        cls.mpd = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(cls.mpd)

    def _make_daemon_stub(self):
        class DaemonStub:
            pass

        daemon = DaemonStub()
        ProxyDaemon = self.mpd.ProxyDaemon
        daemon._validate_relationship_maildir_for_watch = (
            lambda maildir, relationship_id: (
                ProxyDaemon._validate_relationship_maildir_for_watch(
                    daemon, maildir, relationship_id
                )
            )
        )
        daemon._ensure_observer_started = (
            lambda: ProxyDaemon._ensure_observer_started(daemon)
        )
        daemon._observer = MagicMock()
        daemon._observer_started = False
        daemon._smtp_task_queue = MagicMock()
        daemon._db = MagicMock()
        daemon._mail_handler = MagicMock()
        daemon._mail_handler._relationship_lookup = MagicMock()
        daemon._watched_lock = __import__('threading').Lock()
        daemon._watched_relationship_ids = set()
        daemon._relationship_id_to_watch_path = {}
        daemon._watch_path_relationship_ids = {}
        daemon._relationship_observer_paths = set()
        daemon._referent_path_registry = {}
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
        daemon._referent_effective_modes = {}
        daemon._referent_handler_data = (
            lambda referent_id: ProxyDaemon._referent_handler_data(
                daemon, referent_id
            )
        )
        return daemon

    def test_validate_skips_missing_maildir(self) -> None:
        daemon = self._make_daemon_stub()
        ProxyDaemon = self.mpd.ProxyDaemon
        result = ProxyDaemon._validate_relationship_maildir_for_watch(
            daemon, '/nonexistent/path/Maildir', 99
        )
        self.assertIsNone(result)

    def test_validate_accepts_rw_maildir_new(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'Maildir'
            new_dir = maildir / 'new'
            new_dir.mkdir(parents=True)
            daemon = self._make_daemon_stub()
            ProxyDaemon = self.mpd.ProxyDaemon
            result = ProxyDaemon._validate_relationship_maildir_for_watch(
                daemon, str(maildir), 1
            )
            self.assertEqual(result, new_dir)

    def test_setup_dedupes_shared_path(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'shared' / 'Maildir'
            (maildir / 'new').mkdir(parents=True)
            shared_path = str(maildir / 'new')

            daemon = self._make_daemon_stub()
            ProxyDaemon = self.mpd.ProxyDaemon
            daemon._ensure_observer_started = (
                lambda: ProxyDaemon._ensure_observer_started(daemon)
            )

            target_a = {
                'local_client_maildir': str(maildir),
                'relationship': {
                    'relationship_id': 1,
                    'referent_id': 10,
                },
            }
            target_b = {
                'local_client_maildir': str(maildir),
                'relationship': {
                    'relationship_id': 2,
                    'referent_id': 10,
                },
            }

            with patch.object(self.mpd, 'OUTBOUND_WATCH_MODE', OutboundWatchMode.DUAL):
                ProxyDaemon._setup_watchdog_for_relationship(daemon, target_a)
                schedule_count_after_first = daemon._observer.schedule.call_count
                ProxyDaemon._setup_watchdog_for_relationship(daemon, target_b)

            self.assertEqual(daemon._observer.schedule.call_count, schedule_count_after_first)
            self.assertEqual(daemon._watched_relationship_ids, {1, 2})
            self.assertEqual(
                daemon._watch_path_relationship_ids[shared_path],
                {1, 2},
            )

    def test_unschedule_keeps_shared_path_while_other_relationship_uses_it(
        self,
    ) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'shared' / 'Maildir'
            (maildir / 'new').mkdir(parents=True)
            shared_path = str(maildir / 'new')

            daemon = self._make_daemon_stub()
            daemon._watched_relationship_ids = {1, 2}
            daemon._relationship_id_to_watch_path = {1: shared_path, 2: shared_path}
            daemon._watch_path_relationship_ids = {shared_path: {1, 2}}

            ProxyDaemon = self.mpd.ProxyDaemon
            ProxyDaemon._unschedule_watchdog_for_relationship(daemon, 1)

            self.assertNotIn(1, daemon._watched_relationship_ids)
            self.assertIn(2, daemon._watched_relationship_ids)
            self.assertEqual(daemon._watch_path_relationship_ids[shared_path], {2})
            daemon._observer.unschedule.assert_not_called()

    def test_unschedule_drops_path_when_last_relationship_removed(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            shared_path = str(Path(tmp) / 'Maildir' / 'new')
            daemon = self._make_daemon_stub()
            daemon._watched_relationship_ids = {1}
            daemon._relationship_id_to_watch_path = {1: shared_path}
            daemon._watch_path_relationship_ids = {shared_path: {1}}
            daemon._relationship_observer_paths = {shared_path}
            emitter = MagicMock()
            emitter.watch.path = shared_path
            daemon._observer.emitters = [emitter]

            ProxyDaemon = self.mpd.ProxyDaemon
            ProxyDaemon._unschedule_watchdog_for_relationship(daemon, 1)

            self.assertEqual(daemon._watched_relationship_ids, set())
            self.assertNotIn(shared_path, daemon._watch_path_relationship_ids)
            daemon._observer.unschedule.assert_called_once()

    def test_collision_skips_observer_when_path_equals_referent_outbox(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            shared_new = Path(tmp) / 'shared' / 'Maildir' / 'new'
            shared_new.mkdir(parents=True)
            shared_path = str(shared_new)

            daemon = self._make_daemon_stub()
            daemon._referent_path_registry = {1: shared_path}
            ProxyDaemon = self.mpd.ProxyDaemon

            target = {
                'local_client_maildir': str(shared_new.parent),
                'relationship': {
                    'relationship_id': 7,
                    'referent_id': 1,
                },
            }

            with patch.object(self.mpd, 'OUTBOUND_WATCH_MODE', OutboundWatchMode.DUAL):
                with patch.object(self.mpd.logger, 'warning') as log_warning:
                    ProxyDaemon._setup_watchdog_for_relationship(daemon, target)

            daemon._observer.schedule.assert_not_called()
            self.assertIn(7, daemon._watched_relationship_ids)
            self.assertEqual(daemon._relationship_id_to_watch_path[7], shared_path)
            self.assertNotIn(shared_path, daemon._relationship_observer_paths)
            warn_args = log_warning.call_args[0]
            self.assertIn('OUTBOUND_WATCH', warn_args[0])
            self.assertEqual(warn_args[1], 7)
            self.assertEqual(warn_args[2], 1)

    def test_collision_unschedule_preserves_referent_observer(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            shared_path = str(Path(tmp) / 'Maildir' / 'new')
            daemon = self._make_daemon_stub()
            daemon._watched_relationship_ids = {7}
            daemon._relationship_id_to_watch_path = {7: shared_path}
            daemon._watch_path_relationship_ids = {shared_path: {7}}
            emitter = MagicMock()
            emitter.watch.path = shared_path
            daemon._observer.emitters = [emitter]

            ProxyDaemon = self.mpd.ProxyDaemon
            ProxyDaemon._unschedule_watchdog_for_relationship(daemon, 7)

            daemon._observer.unschedule.assert_not_called()
            self.assertEqual(daemon._watched_relationship_ids, set())

    def test_collision_relationship_live_routing_via_referent_enqueue(self) -> None:
        """Referent-level pickup path still resolves relationship account in live mode."""
        dto = ClientRelationshipDTO(
            relationship_id=1,
            referent_id=1,
            external_client_email='clientint1@frona.ru',
            local_client_email='clientloc1@testvps.loc',
            local_referent_email='refloc1@testvps.loc',
            external_account_id=1,
            external_referent_email='refint1@frona.ru',
            local_client_maildir='/var/vmail/clientloc1/Maildir',
            account={'id': 1, 'email': 'refint1@frona.ru'},
            referent={'id': 1, 'local_inbox': 'refloc1@testvps.loc'},
        )
        plan = plan_outbound_delivery(
            mode=OutboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_outbound=lambda _e: dto,
            load_legacy_account=lambda _r: {'id': 99},
            from_address='clientloc1@testvps.loc',
            referent_id=1,
            shadow_enabled=False,
        )
        self.assertEqual(plan.external_account_id, 1)
        self.assertEqual(plan.account['id'], 1)
        self.assertNotEqual(plan.account['id'], 99)

    def test_dual_log_relationship_only_path(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            rel_new = Path(tmp) / 'client' / 'Maildir' / 'new'
            rel_new.mkdir(parents=True)
            ref_new = Path(tmp) / 'referent' / 'outbox' / 'new'
            ref_new.mkdir(parents=True)
            file_path = rel_new / 'msg.test'

            daemon = self._make_daemon_stub()
            daemon._referent_path_registry = {1: str(ref_new)}
            daemon._watch_path_relationship_ids = {str(rel_new): {1}}

            daemon._referent_effective_modes = {
                1: ReferentEffectiveModes(
                    referent_id=1,
                    inbound_routing=InboundRoutingMode.SHADOW,
                    outbound_routing=OutboundRoutingMode.SHADOW,
                    outbound_watch=OutboundWatchMode.DUAL,
                )
            }
            ProxyDaemon = self.mpd.ProxyDaemon
            with patch.object(self.mpd.logger, 'info') as log_info:
                ProxyDaemon._log_outbound_watch_dual(
                    daemon, file_path, 'relationship', 1
                )
                logged = ' '.join(str(c) for c in log_info.call_args[0])
                self.assertIn('[OUTBOUND_WATCH_DUAL]', logged)
                self.assertIn('not_visible_via_referent_outbox', logged)


if __name__ == '__main__':
    unittest.main(verbosity=2)
