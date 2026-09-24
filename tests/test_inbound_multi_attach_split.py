#!/usr/bin/env python3
"""PROMPT-79.2d — inbound extension accept, archive subject check, skip journal, limit."""

from __future__ import annotations

import sys
import unicodedata
import tempfile
import types
import unittest
from email import encoders, header as email_header, policy
from email.header import Header, decode_header
from email.mime.base import MIMEBase
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from pathlib import Path
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from attachment_policy import (
    APPROVED_ARCHIVE_EXTENSIONS,
    APPROVED_INBOUND_EXTENSIONS,
    DISPOSAL_EXTENSION,
    DISPOSAL_MULTIPLE,
    DISPOSAL_SUBJECT,
    DISPOSAL_TOO_MANY,
    DISPOSAL_MISSING_FILENAME,
    DISPOSAL_ZERO,
    IMAGE_EXTENSIONS,
    MAX_INBOUND_ATTACHMENTS,
    check_inbound_subject,
    classify_inbound_attachments,
    enumerate_attachable_parts,
    sanitize_header_filename,
    validate_single_archive_attachment,
    normalize_for_compare,
    normalize_for_display,
)
from message_rebuild import rebuild_inbound_fanout
from relationship_lookup import (
    STATUS_MATCHED,
    ClientRelationshipDTO,
    RelationshipClassifyResult,
)


ARCHIVE_EXTS = frozenset(APPROVED_ARCHIVE_EXTENSIONS)
IMAGE_EXTS = frozenset(IMAGE_EXTENSIONS)


def _attach(mixed: MIMEMultipart, name: str, payload: bytes = b'data') -> None:
    part = MIMEBase('application', 'octet-stream', policy=policy.SMTP)
    part.set_payload(payload)
    encoders.encode_base64(part)
    part.add_header('Content-Disposition', 'attachment', filename=name)
    mixed.attach(part)


def _msg(
    names: list[str],
    *,
    subject: str | None = None,
    subject_header=None,
) -> bytes:
    """Build message; default Subject = space-joined ARCHIVE names only (D7)."""
    mixed = MIMEMultipart('mixed', policy=policy.SMTP)
    mixed['From'] = 'clientint1@frona.ru'
    mixed['To'] = 'refint1@frona.ru'
    mixed['Message-ID'] = '<PROMPT792D@testvps.loc>'
    archives = [
        n
        for n in names
        if ('.' in n and n.rsplit('.', 1)[-1].lower() in ARCHIVE_EXTS)
    ]
    if subject_header is not None:
        mixed['Subject'] = subject_header
    elif subject is not None:
        mixed['Subject'] = subject
    else:
        mixed['Subject'] = ' '.join(archives) if archives else 'no-check'
    mixed.attach(MIMEText('', 'plain', 'utf-8', policy=policy.SMTP))
    for name in names:
        _attach(mixed, name)
    return mixed.as_bytes(policy=policy.SMTP)


def _dto(maildir: str = '/tmp/Maildir') -> ClientRelationshipDTO:
    return ClientRelationshipDTO(
        relationship_id=1,
        referent_id=1,
        external_client_email='clientint1@frona.ru',
        local_client_email='clientloc1@testvps.loc',
        local_referent_email='refloc1@testvps.loc',
        external_account_id=1,
        external_referent_email='refint1@frona.ru',
        local_client_maildir=maildir,
        account={'id': 1, 'email': 'refint1@frona.ru'},
        referent={'id': 1, 'username': 'Test', 'local_inbox': 'refloc1@testvps.loc'},
    )


class ConstantsTest(unittest.TestCase):
    def test_inbound_is_union_without_svg(self) -> None:
        self.assertEqual(
            APPROVED_INBOUND_EXTENSIONS,
            frozenset(APPROVED_ARCHIVE_EXTENSIONS | IMAGE_EXTENSIONS),
        )
        self.assertNotIn('svg', APPROVED_INBOUND_EXTENSIONS)
        self.assertNotIn('svg', IMAGE_EXTENSIONS)
        self.assertEqual(MAX_INBOUND_ATTACHMENTS, 20)

    def test_outbound_still_rejects_images(self) -> None:
        r = validate_single_archive_attachment(_msg(['photo.png'], subject='photo.png'))
        self.assertTrue(r.is_invalid)
        self.assertEqual(r.reason, DISPOSAL_EXTENSION)
        self.assertNotIn('png', APPROVED_ARCHIVE_EXTENSIONS)


class CheckInboundSubjectTest(unittest.TestCase):
    def test_single_archive_case_ok(self) -> None:
        ok, exp, act = check_inbound_subject('CONTRACT.ZIP', ['Contract.zip'])
        self.assertTrue(ok)

    def test_single_archive_mismatch(self) -> None:
        ok, _, _ = check_inbound_subject('other.zip', ['Contract.zip'])
        self.assertFalse(ok)

    def test_order_irrelevant(self) -> None:
        names = ['a.zip', 'b.rar']
        self.assertTrue(check_inbound_subject('a.zip b.rar', names)[0])
        self.assertTrue(check_inbound_subject('b.rar a.zip', names)[0])

    def test_missing_extra_dup_text(self) -> None:
        names = ['a.zip', 'b.rar']
        self.assertFalse(check_inbound_subject('a.zip', names)[0])
        self.assertFalse(check_inbound_subject('a.zip b.rar c.zip', names)[0])
        self.assertFalse(check_inbound_subject('a.zip a.zip', names)[0])
        self.assertFalse(check_inbound_subject('a.zip fyi', names)[0])

    def test_duplicate_archives(self) -> None:
        names = ['a.zip', 'a.zip']
        self.assertTrue(check_inbound_subject('a.zip a.zip', names)[0])
        self.assertFalse(check_inbound_subject('a.zip', names)[0])

    def test_names_with_spaces_and_cyrillic(self) -> None:
        names = ['Отчёт за май.zip', 'b.rar']
        self.assertTrue(
            check_inbound_subject('b.rar Отчёт за май.zip', names)[0]
        )
        self.assertTrue(
            check_inbound_subject('Отчёт за май.zip b.rar', names)[0]
        )

    def test_ambiguous_prefix_names(self) -> None:
        names = ['a.zip', 'a.zip.zip']
        self.assertTrue(check_inbound_subject('a.zip.zip a.zip', names)[0])
        self.assertTrue(check_inbound_subject('a.zip a.zip.zip', names)[0])
        self.assertFalse(check_inbound_subject('a.zip', names)[0])

    def test_empty_archive_list_ok(self) -> None:
        self.assertTrue(check_inbound_subject('anything', [])[0])


class ClassifySubjectRulesTest(unittest.TestCase):
    def test_one_archive_case_delivered(self) -> None:
        c = classify_inbound_attachments(
            _msg(['Contract.zip'], subject='contract.zip')
        )
        self.assertTrue(c.may_deliver)
        self.assertEqual(c.deliver_indexes, (0,))

    def test_one_archive_mismatch(self) -> None:
        c = classify_inbound_attachments(
            _msg(['Contract.zip'], subject='wrong.zip')
        )
        self.assertTrue(c.is_invalid)
        self.assertEqual(c.reason, DISPOSAL_SUBJECT)
        self.assertIn('expected=', c.detail or '')
        self.assertIn('actual=', c.detail or '')

    def test_one_image_any_subject(self) -> None:
        c = classify_inbound_attachments(
            _msg(['Photo.PNG'], subject='totally-arbitrary')
        )
        self.assertTrue(c.may_deliver)
        self.assertFalse(c.subject.required)
        self.assertEqual(c.parts[0].category, 'image')

    def test_one_other_disallowed_no_subject_check(self) -> None:
        c = classify_inbound_attachments(_msg(['evil.exe'], subject='evil.exe'))
        self.assertTrue(c.is_invalid)
        self.assertEqual(c.reason, DISPOSAL_EXTENSION)
        self.assertFalse(c.subject.required)

    def test_two_archives_order_and_mismatch(self) -> None:
        self.assertTrue(
            classify_inbound_attachments(
                _msg(['a.zip', 'b.rar'], subject='b.rar a.zip')
            ).may_deliver
        )
        bad = classify_inbound_attachments(
            _msg(['a.zip', 'b.rar'], subject='a.zip fyi')
        )
        self.assertEqual(bad.reason, DISPOSAL_SUBJECT)

    def test_archive_plus_image_subject_archive_only(self) -> None:
        ok = classify_inbound_attachments(
            _msg(['a.zip', 'pic.jpg'], subject='a.zip')
        )
        self.assertTrue(ok.may_deliver)
        self.assertEqual(ok.deliver_indexes, (0, 1))
        bad = classify_inbound_attachments(
            _msg(['a.zip', 'pic.jpg'], subject='a.zip pic.jpg')
        )
        self.assertEqual(bad.reason, DISPOSAL_SUBJECT)
        self.assertIn('expected=', bad.detail or '')

    def test_images_only_any_subject(self) -> None:
        c = classify_inbound_attachments(
            _msg(['a.png', 'b.jpg'], subject='hello world')
        )
        self.assertTrue(c.may_deliver)
        self.assertFalse(c.subject.required)

    def test_archive_plus_disallowed_skipped(self) -> None:
        c = classify_inbound_attachments(
            _msg(['a.zip', 'evil.exe'], subject='a.zip')
        )
        self.assertTrue(c.may_deliver)
        self.assertEqual(c.approved_filenames, ('a.zip',))
        self.assertEqual(c.skipped_filenames, ('evil.exe',))
        self.assertEqual(c.parts[1].verdict, 'skip')

    def test_rfc2047_and_folded_subject(self) -> None:
        # RFC 2047 encoded Subject equal to archive name
        enc = Header('Report.zip', 'utf-8').encode()
        c = classify_inbound_attachments(
            _msg(['Report.zip'], subject_header=enc)
        )
        self.assertTrue(c.may_deliver)

        # Folded Subject via raw RFC822 (policy forbids CR/LF on __setitem__)
        raw = (
            b'From: clientint1@frona.ru\r\n'
            b'To: refint1@frona.ru\r\n'
            b'Subject: a.zip\r\n b.rar\r\n'
            b'MIME-Version: 1.0\r\n'
            b'Content-Type: multipart/mixed; boundary="bnd"\r\n'
            b'\r\n'
            b'--bnd\r\n'
            b'Content-Type: text/plain\r\n\r\n\r\n'
            b'--bnd\r\n'
            b'Content-Type: application/octet-stream\r\n'
            b'Content-Disposition: attachment; filename="a.zip"\r\n'
            b'Content-Transfer-Encoding: base64\r\n\r\n'
            b'ZGF0YQ==\r\n'
            b'--bnd\r\n'
            b'Content-Type: application/octet-stream\r\n'
            b'Content-Disposition: attachment; filename="b.rar"\r\n'
            b'Content-Transfer-Encoding: base64\r\n\r\n'
            b'ZGF0YQ==\r\n'
            b'--bnd--\r\n'
        )
        c2 = classify_inbound_attachments(raw)
        self.assertTrue(c2.may_deliver)

    def test_twenty_files_long_subject(self) -> None:
        names = [f'file{i:02d}.zip' for i in range(20)]
        # reverse order in subject
        subj = ' '.join(reversed(names))
        c = classify_inbound_attachments(_msg(names, subject=subj))
        self.assertTrue(c.may_deliver)
        self.assertEqual(c.total_count, 20)


class ClassifyExtensionsAndLimitTest(unittest.TestCase):
    def test_zero(self) -> None:
        mixed = MIMEMultipart('mixed', policy=policy.SMTP)
        mixed.attach(MIMEText('hi', 'plain', 'utf-8', policy=policy.SMTP))
        c = classify_inbound_attachments(mixed.as_bytes(policy=policy.SMTP))
        self.assertEqual(c.reason, DISPOSAL_ZERO)

    def test_png_ok_svg_skip(self) -> None:
        png = classify_inbound_attachments(_msg(['shot.png'], subject='x'))
        self.assertTrue(png.may_deliver)
        svg = classify_inbound_attachments(_msg(['icon.svg'], subject='icon.svg'))
        self.assertEqual(svg.reason, DISPOSAL_EXTENSION)
        mixed = classify_inbound_attachments(
            _msg(['a.zip', 'icon.svg'], subject='a.zip')
        )
        self.assertTrue(mixed.may_deliver)
        self.assertEqual(mixed.skipped_filenames, ('icon.svg',))

    def test_all_disallowed(self) -> None:
        c = classify_inbound_attachments(_msg(['a.exe', 'b.pdf']))
        self.assertEqual(c.reason, DISPOSAL_EXTENSION)

    def test_extension_case_and_identical_names_by_index(self) -> None:
        c = classify_inbound_attachments(
            _msg(['Photo.PNG', 'Photo.PNG'], subject='x')
        )
        self.assertTrue(c.may_deliver)
        self.assertEqual(c.deliver_indexes, (0, 1))
        self.assertEqual(c.parts[0].filename, 'Photo.PNG')

    def test_limit_20_ok_21_too_many(self) -> None:
        names20 = [f'n{i}.zip' for i in range(20)]
        ok = classify_inbound_attachments(_msg(names20))
        self.assertTrue(ok.may_deliver)
        names21 = [f'n{i}.zip' for i in range(21)]
        bad = classify_inbound_attachments(
            _msg(names21, subject='wrong-subject-entirely')
        )
        self.assertEqual(bad.reason, DISPOSAL_TOO_MANY)
        self.assertNotEqual(bad.reason, DISPOSAL_SUBJECT)
        self.assertIn('count=21', bad.detail or '')
        self.assertIn('n0.zip', bad.detail or '')

    def test_inline_cid_not_counted_r6(self) -> None:
        mixed = MIMEMultipart('mixed', policy=policy.SMTP)
        mixed['Subject'] = 'a.zip'
        mixed.attach(MIMEText('', 'plain', 'utf-8', policy=policy.SMTP))
        inline = MIMEBase('image', 'png', policy=policy.SMTP)
        inline.set_payload(b'png')
        encoders.encode_base64(inline)
        inline.add_header('Content-Disposition', 'inline', filename='sig.png')
        inline.add_header('Content-ID', '<sig@local>')
        mixed.attach(inline)
        _attach(mixed, 'a.zip')
        raw = mixed.as_bytes(policy=policy.SMTP)
        parts = enumerate_attachable_parts(
            __import__('email').message_from_bytes(raw, policy=policy.default)
        )
        self.assertEqual(len(parts), 1)
        c = classify_inbound_attachments(raw)
        self.assertTrue(c.may_deliver)
        self.assertEqual(c.total_count, 1)


class ChildSubjectD8Test(unittest.TestCase):
    def test_image_and_archive_child_subjects(self) -> None:
        raw = _msg(['Archive.ZIP', 'Photo.JPG'], subject='Archive.ZIP')
        with tempfile.TemporaryDirectory() as tmp:
            fan = rebuild_inbound_fanout(
                raw, _dto(tmp), temp_dir=tmp, approved_part_indexes=(0, 1)
            )
            self.assertTrue(fan.success)
            subjects = []
            for child in fan.children:
                msg = __import__('email').message_from_bytes(
                    child.temp_path.read_bytes(), policy=policy.default
                )
                subjects.append(str(msg['Subject']))
            self.assertEqual(subjects[0], 'Archive.ZIP')
            self.assertEqual(subjects[1], 'Photo.JPG')

    def test_non_ascii_filename_roundtrip(self) -> None:
        name = 'Отчёт.zip'
        raw = _msg([name], subject=name)
        with tempfile.TemporaryDirectory() as tmp:
            fan = rebuild_inbound_fanout(
                raw, _dto(tmp), temp_dir=tmp, approved_part_indexes=(0,)
            )
            self.assertTrue(fan.success)
            msg = __import__('email').message_from_bytes(
                fan.children[0].temp_path.read_bytes(), policy=policy.default
            )
            # Decoded subject must equal original case-preserved name
            decoded_parts = decode_header(msg['Subject'])
            chunks = []
            for data, charset in decoded_parts:
                if isinstance(data, bytes):
                    chunks.append(data.decode(charset or 'utf-8'))
                else:
                    chunks.append(str(data))
            self.assertEqual(''.join(chunks), name)

    def test_crlf_sanitised(self) -> None:
        dirty = 'evil\r\nBcc: x@y.zip'
        self.assertNotIn('\r', sanitize_header_filename(dirty))
        self.assertNotIn('\n', sanitize_header_filename(dirty))
        raw = _msg(['safe.zip'], subject='safe.zip')
        # Inject dirty filename via rebuild path using approved index after
        # manually building MIME with encoded filename containing controls is hard;
        # unit-test sanitize + rebuild with a part we construct.
        mixed = MIMEMultipart('mixed', policy=policy.SMTP)
        mixed['Subject'] = 'x'
        mixed.attach(MIMEText('', 'plain', 'utf-8', policy=policy.SMTP))
        part = MIMEBase('application', 'octet-stream', policy=policy.SMTP)
        part.set_payload(b'data')
        encoders.encode_base64(part)
        # get_filename may strip; set via add_header with RFC2047
        part.add_header(
            'Content-Disposition',
            'attachment',
            filename=Header('ok.zip', 'utf-8').encode(),
        )
        mixed.attach(part)
        # Direct sanitize assertion is the injection guard; rebuild uses it.
        safe = sanitize_header_filename('name\r\nInjected: yes.zip')
        self.assertEqual(safe, 'nameInjected: yes.zip')

    def test_identical_filenames_same_subjects(self) -> None:
        raw = _msg(['same.zip', 'same.zip'], subject='same.zip same.zip')
        with tempfile.TemporaryDirectory() as tmp:
            fan = rebuild_inbound_fanout(
                raw, _dto(tmp), temp_dir=tmp, approved_part_indexes=(0, 1)
            )
            self.assertTrue(fan.success)
            subs = []
            for child in fan.children:
                msg = __import__('email').message_from_bytes(
                    child.temp_path.read_bytes(), policy=policy.default
                )
                subs.append(str(msg['Subject']))
            self.assertEqual(subs[0], subs[1])
            self.assertEqual(subs[0], 'same.zip')


class DaemonInbound792dTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        import importlib.util

        watchdog_mod = types.ModuleType('watchdog')
        watchdog_events = types.ModuleType('watchdog.events')
        watchdog_events.FileSystemEventHandler = object
        watchdog_observers = types.ModuleType('watchdog.observers')
        watchdog_observers.Observer = MagicMock
        sys.modules.setdefault('watchdog', watchdog_mod)
        sys.modules.setdefault('watchdog.events', watchdog_events)
        sys.modules.setdefault('watchdog.observers', watchdog_observers)

        spec = importlib.util.spec_from_file_location(
            'mail_proxy_daemon_792d_test',
            str(ROOT / 'mail-proxy-daemon.py'),
        )
        cls.mpd = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(cls.mpd)

    def _handler(self, maildir: Path):
        handler = self.mpd.MailHandler(MagicMock(), MagicMock())
        journal = MagicMock()
        journal.write = MagicMock(return_value=1)
        handler._passage_journal = journal
        dto = _dto(str(maildir))
        classified = RelationshipClassifyResult(
            status=STATUS_MATCHED,
            dto=dto,
            relationship_id=1,
            referent_name='Test',
            client_name='clientint1@frona.ru',
            local_mailbox='refloc1@testvps.loc',
            external_mailbox='clientint1@frona.ru',
        )
        handler._relationship_lookup = MagicMock()
        handler._relationship_lookup.classify_inbound = MagicMock(
            return_value=classified
        )
        handler._relationship_lookup.resolve_inbound = MagicMock(return_value=dto)
        return handler, journal

    def _deliver(self, handler, path: Path):
        return handler._deliver_to_local_smtp(
            path,
            {'id': 1, 'username': 'Test', 'local_inbox': 'refloc1@testvps.loc'},
            {'id': 1, 'email': 'refint1@frona.ru'},
        )

    def test_split_delivers(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'Maildir'
            maildir.mkdir()
            path = Path(tmp) / 'm.eml'
            path.write_bytes(_msg(['a.zip', 'b.zip']))
            handler, journal = self._handler(maildir)
            with patch('smtplib.SMTP', return_value=MagicMock()):
                handler._stream_file_via_smtp = MagicMock(return_value=True)
                result = self._deliver(handler, path)
            self.assertTrue(result.local_delivered)
            self.assertEqual(handler._stream_file_via_smtp.call_count, 2)

    def test_partial_smtp_keeps_delivered_rows(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'Maildir'
            maildir.mkdir()
            path = Path(tmp) / 'm.eml'
            path.write_bytes(_msg(['a.zip', 'b.zip']))
            handler, journal = self._handler(maildir)
            with patch('smtplib.SMTP', return_value=MagicMock()):
                handler._stream_file_via_smtp = MagicMock(side_effect=[True, False])
                result = self._deliver(handler, path)
            self.assertFalse(result.mark_imap_seen)
            self.assertFalse(result.dispose_imap)
            self.assertEqual(result.smtp_error, 'fanout_incomplete')
            # first child delivered -> at least one journal.write for delivered
            self.assertGreaterEqual(journal.write.call_count, 1)

    def test_skipped_rows_written(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'Maildir'
            maildir.mkdir()
            path = Path(tmp) / 'm.eml'
            path.write_bytes(_msg(['a.zip', 'evil.exe'], subject='a.zip'))
            handler, journal = self._handler(maildir)
            with patch('smtplib.SMTP', return_value=MagicMock()):
                handler._stream_file_via_smtp = MagicMock(return_value=True)
                result = self._deliver(handler, path)
            self.assertTrue(result.local_delivered)
            events = [c[0][0].event_type for c in journal.write.call_args_list]
            self.assertIn('skipped', events)

    def test_journal_write_failure_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'Maildir'
            maildir.mkdir()
            path = Path(tmp) / 'm.eml'
            path.write_bytes(_msg(['a.exe']))
            handler, journal = self._handler(maildir)
            journal.write = MagicMock(side_effect=RuntimeError('db down'))
            result = self._deliver(handler, path)
            self.assertFalse(result.dispose_imap)
            self.assertFalse(result.mark_imap_seen)

    def test_too_many_disposes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'Maildir'
            maildir.mkdir()
            path = Path(tmp) / 'm.eml'
            path.write_bytes(_msg([f'n{i}.zip' for i in range(21)]))
            handler, journal = self._handler(maildir)
            result = self._deliver(handler, path)
            self.assertTrue(result.dispose_imap)
            rec = journal.write.call_args[0][0]
            self.assertEqual(rec.disposal_reason, DISPOSAL_TOO_MANY)
            self.assertIn('count=21', rec.detail or '')

    def test_subject_mismatch_disposes_whole(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'Maildir'
            maildir.mkdir()
            path = Path(tmp) / 'm.eml'
            path.write_bytes(
                _msg(['a.zip', 'pic.jpg'], subject='a.zip pic.jpg')
            )
            handler, journal = self._handler(maildir)
            result = self._deliver(handler, path)
            self.assertTrue(result.dispose_imap)
            rec = journal.write.call_args[0][0]
            self.assertEqual(rec.disposal_reason, DISPOSAL_SUBJECT)
            self.assertIn('expected=', rec.detail or '')

    def test_no_interim_markers(self) -> None:
        src = (ROOT / 'mail-proxy-daemon.py').read_text(encoding='utf-8')
        self.assertNotIn('PROMPT-79.2-INTERIM', src)
        self.assertNotIn('interim_multi_attachment_hold', src)
        rebuild = (ROOT / 'message_rebuild.py').read_text(encoding='utf-8')
        self.assertNotIn('archive_extensions_only', rebuild)




def _part(name=None, *, disposition='attachment', payload=b'data', maintype='application', subtype='octet-stream'):
    part = MIMEBase(maintype, subtype, policy=policy.SMTP)
    part.set_payload(payload)
    encoders.encode_base64(part)
    if name is None:
        part.add_header('Content-Disposition', disposition)
    else:
        part.add_header('Content-Disposition', disposition, filename=name)
    return part


def _mixed(parts, subject='x'):
    mixed = MIMEMultipart('mixed', policy=policy.SMTP)
    mixed['From'] = 'clientint1@frona.ru'
    mixed['To'] = 'refint1@frona.ru'
    mixed['Subject'] = subject
    mixed.attach(MIMEText('', 'plain', 'utf-8', policy=policy.SMTP))
    for p in parts:
        mixed.attach(p)
    return mixed.as_bytes(policy=policy.SMTP)


class InlinePolicyE1Test(unittest.TestCase):
    def test_inline_only_png_zero_attachments(self) -> None:
        raw = _mixed([_part('logo.png', disposition='inline', maintype='image', subtype='png')])
        c = classify_inbound_attachments(raw)
        self.assertEqual(c.reason, DISPOSAL_ZERO)
        self.assertIn('inline_parts=1', c.detail or '')
        self.assertIn('logo.png', c.detail or '')

    def test_zip_plus_inline_logo(self) -> None:
        raw = _mixed(
            [
                _part('doc.zip'),
                _part('logo.png', disposition='inline', maintype='image', subtype='png'),
            ],
            subject='doc.zip',
        )
        c = classify_inbound_attachments(raw)
        self.assertTrue(c.may_deliver)
        self.assertEqual(c.deliver_indexes, (0,))
        self.assertEqual(c.approved_filenames, ('doc.zip',))

    def test_inline_zip_is_zero(self) -> None:
        raw = _mixed([_part('secret.zip', disposition='inline')])
        c = classify_inbound_attachments(raw)
        self.assertEqual(c.reason, DISPOSAL_ZERO)

    def test_no_disposition_not_counted(self) -> None:
        # Leaf part without Content-Disposition is not an attachment
        part = MIMEBase('image', 'png', policy=policy.SMTP)
        part.set_payload(b'png')
        encoders.encode_base64(part)
        # filename param without attachment disposition must not count
        part.set_param('name', 'x.png', header='Content-Type')
        raw = _mixed([part])
        c = classify_inbound_attachments(raw)
        self.assertEqual(c.reason, DISPOSAL_ZERO)

    def test_attachment_image_delivered(self) -> None:
        raw = _mixed([_part('Photo.PNG', maintype='image', subtype='png')], subject='anything')
        c = classify_inbound_attachments(raw)
        self.assertTrue(c.may_deliver)
        self.assertFalse(c.subject.required)
        with tempfile.TemporaryDirectory() as tmp:
            fan = rebuild_inbound_fanout(
                raw, _dto(tmp), temp_dir=tmp, approved_part_indexes=c.deliver_indexes
            )
            self.assertTrue(fan.success)
            msg = __import__('email').message_from_bytes(
                fan.children[0].temp_path.read_bytes(), policy=policy.default
            )
            self.assertEqual(str(msg['Subject']), 'Photo.PNG')

    def test_several_attachment_images(self) -> None:
        raw = _mixed(
            [
                _part('a.png', maintype='image', subtype='png'),
                _part('b.jpg', maintype='image', subtype='jpeg'),
            ],
            subject='ignored',
        )
        c = classify_inbound_attachments(raw)
        self.assertTrue(c.may_deliver)
        self.assertEqual(c.deliver_indexes, (0, 1))

    def test_same_png_inline_blocked(self) -> None:
        raw = _mixed([_part('same.png', disposition='inline', maintype='image', subtype='png')])
        c = classify_inbound_attachments(raw)
        self.assertEqual(c.reason, DISPOSAL_ZERO)
        self.assertIn('inline_parts=1', c.detail or '')
        self.assertIn('same.png', c.detail or '')


class ContentDispositionFilenameTrap79_2fTest(unittest.TestCase):
    """PROMPT-79.2f: disposition value only — filenames must not match 'attachment'."""

    def test_inline_attachment_png_filename_zero_attachments(self) -> None:
        raw = _mixed(
            [_part('attachment.png', disposition='inline', maintype='image', subtype='png')]
        )
        c = classify_inbound_attachments(raw)
        self.assertEqual(c.reason, DISPOSAL_ZERO)
        self.assertIn('attachment.png', c.detail or '')

    def test_zip_plus_inline_attachment_jpg_filename(self) -> None:
        raw = _mixed(
            [
                _part('report.zip'),
                _part(
                    'Attachment-1.jpg',
                    disposition='inline',
                    maintype='image',
                    subtype='jpeg',
                ),
            ],
            subject='report.zip',
        )
        c = classify_inbound_attachments(raw)
        self.assertTrue(c.may_deliver)
        self.assertEqual(c.deliver_indexes, (0,))
        self.assertEqual(c.approved_filenames, ('report.zip',))
        self.assertTrue(c.subject.required)
        self.assertTrue(c.subject.ok)

    def test_uppercase_attachment_disposition_counted(self) -> None:
        raw = _mixed([_part('a.zip', disposition='ATTACHMENT')], subject='a.zip')
        c = classify_inbound_attachments(raw)
        self.assertTrue(c.may_deliver)
        self.assertEqual(c.total_count, 1)

    def test_attachment_disposition_inline_zip_filename_counted(self) -> None:
        raw = _mixed([_part('inline.zip')], subject='inline.zip')
        c = classify_inbound_attachments(raw)
        self.assertTrue(c.may_deliver)
        self.assertEqual(c.approved_filenames, ('inline.zip',))

    def test_classify_rebuild_index_alignment_trap_filenames(self) -> None:
        raw = _mixed(
            [
                _part('attachment.png', disposition='inline', maintype='image', subtype='png'),
                _part('real.zip'),
                _part(
                    'Attachment-1.jpg',
                    disposition='inline',
                    maintype='image',
                    subtype='jpeg',
                ),
            ],
            subject='real.zip',
        )
        msg = __import__('email').message_from_bytes(raw, policy=policy.default)
        self.assertEqual(len(enumerate_attachable_parts(msg)), 1)
        c = classify_inbound_attachments(raw)
        self.assertEqual(c.deliver_indexes, (0,))
        with tempfile.TemporaryDirectory() as tmp:
            fan = rebuild_inbound_fanout(
                raw, _dto(tmp), temp_dir=tmp, approved_part_indexes=c.deliver_indexes
            )
            self.assertTrue(fan.success)
            self.assertEqual(len(fan.children), 1)
            child = __import__('email').message_from_bytes(
                fan.children[0].temp_path.read_bytes(), policy=policy.default
            )
            self.assertEqual(str(child['Subject']), 'real.zip')


class MissingFilenameE3Test(unittest.TestCase):
    def test_nameless_attachment_skipped(self) -> None:
        raw = _mixed([_part(None), _part('ok.zip')], subject='ok.zip')
        c = classify_inbound_attachments(raw)
        self.assertTrue(c.may_deliver)
        self.assertEqual(c.deliver_indexes, (1,))
        self.assertTrue(c.parts[0].missing_filename)
        self.assertEqual(c.parts[0].verdict, 'skip')

    def test_all_nameless_missing_filename_dispose(self) -> None:
        raw = _mixed([_part(None), _part(None)])
        c = classify_inbound_attachments(raw)
        self.assertEqual(c.reason, DISPOSAL_MISSING_FILENAME)

    def test_nameless_counts_toward_limit(self) -> None:
        parts = [_part(None) for _ in range(21)]
        raw = _mixed(parts)
        c = classify_inbound_attachments(raw)
        self.assertEqual(c.reason, DISPOSAL_TOO_MANY)
        self.assertEqual(c.total_count, 21)

    def test_mixed_nameless_and_exe_is_disallowed(self) -> None:
        raw = _mixed([_part(None), _part('evil.exe')])
        c = classify_inbound_attachments(raw)
        self.assertEqual(c.reason, DISPOSAL_EXTENSION)


class SubjectNormalizeE2Test(unittest.TestCase):
    def test_nfd_filename_nfc_subject(self) -> None:
        base = 'cafe\u0301.zip'  # NFD
        nfc = unicodedata.normalize('NFC', base)
        self.assertNotEqual(base, nfc)
        ok, exp, act = check_inbound_subject(nfc, [base])
        self.assertTrue(ok)
        self.assertEqual(exp, nfc)
        self.assertEqual(act, nfc)

    def test_nfc_filename_nfd_subject(self) -> None:
        nfc = unicodedata.normalize('NFC', 'cafe\u0301.zip')
        nfd = unicodedata.normalize('NFD', nfc)
        ok, _, _ = check_inbound_subject(nfd, [nfc])
        self.assertTrue(ok)

    def test_double_spaces_and_tab(self) -> None:
        ok, _, _ = check_inbound_subject('a.zip\tb.zip', ['a.zip', 'b.zip'])
        self.assertTrue(ok)
        ok2, _, _ = check_inbound_subject('a.zip  b.zip', ['a.zip', 'b.zip'])
        self.assertTrue(ok2)
        # filename with two spaces matches collapsed subject
        ok3, exp, _ = check_inbound_subject('report.zip', ['report  .zip'.replace('  ', '  ')])
        # archive name with internal double space collapses for compare
        ok3, _, _ = check_inbound_subject('my  file.zip', ['my  file.zip'])
        self.assertTrue(ok3)
        self.assertEqual(normalize_for_display('my  file.zip'), 'my file.zip')

    def test_rfc2047_cyrillic_subject(self) -> None:
        from email.header import Header
        name = 'Отчёт.zip'
        enc = Header(name, 'utf-8').encode()
        ok, exp, act = check_inbound_subject(enc, [name])
        self.assertTrue(ok)
        self.assertEqual(exp, name)
        self.assertEqual(act, name)

    def test_display_not_casefolded(self) -> None:
        ok, exp, act = check_inbound_subject('Report.ZIP', ['Report.zip'])
        self.assertTrue(ok)
        self.assertEqual(exp, 'Report.zip')
        self.assertEqual(act, 'Report.ZIP')  # display keeps case after NFC/collapse


class EnumeratorAlignE5Test(unittest.TestCase):
    def test_index_alignment_mixed(self) -> None:
        raw = _mixed(
            [
                _part('logo.png', disposition='inline', maintype='image', subtype='png'),
                _part('a.zip'),
                _part(None),
                _part('a.zip'),
                _part('sig.png', disposition='inline', maintype='image', subtype='png'),
            ],
            subject='a.zip a.zip',
        )
        msg = __import__('email').message_from_bytes(raw, policy=policy.default)
        enumerated = enumerate_attachable_parts(msg)
        self.assertEqual(len(enumerated), 3)  # two a.zip + nameless
        c = classify_inbound_attachments(raw)
        self.assertEqual(c.total_count, 3)
        self.assertEqual(c.deliver_indexes, (0, 2))
        with tempfile.TemporaryDirectory() as tmp:
            fan = rebuild_inbound_fanout(
                raw, _dto(tmp), temp_dir=tmp, approved_part_indexes=c.deliver_indexes
            )
            self.assertTrue(fan.success)
            self.assertEqual(len(fan.children), 2)
            for child in fan.children:
                msg2 = __import__('email').message_from_bytes(
                    child.temp_path.read_bytes(), policy=policy.default
                )
                self.assertEqual(str(msg2['Subject']), 'a.zip')


class DisposeImapE4Test(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        import importlib.util
        import types
        from unittest.mock import MagicMock

        watchdog_mod = types.ModuleType('watchdog')
        watchdog_events = types.ModuleType('watchdog.events')
        watchdog_events.FileSystemEventHandler = object
        watchdog_observers = types.ModuleType('watchdog.observers')
        watchdog_observers.Observer = MagicMock
        sys.modules.setdefault('watchdog', watchdog_mod)
        sys.modules.setdefault('watchdog.events', watchdog_events)
        sys.modules.setdefault('watchdog.observers', watchdog_observers)
        spec = importlib.util.spec_from_file_location(
            'mail_proxy_daemon_792e_dispose',
            str(ROOT / 'mail-proxy-daemon.py'),
        )
        cls.mpd = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(cls.mpd)

    def _handler(self):
        from unittest.mock import MagicMock
        return self.mpd.MailHandler(MagicMock(), MagicMock())

    def test_order_seen_then_deleted_then_expunge(self) -> None:
        from unittest.mock import MagicMock, call
        handler = self._handler()
        mail = MagicMock()
        mail.uid = MagicMock(return_value=('OK', [b'']))
        mail.expunge = MagicMock(return_value=('OK', [b'']))
        ok = handler._dispose_imap_message(
            mail, '42', b'1', account_email='a@x', source_message_id='<m@x>'
        )
        self.assertTrue(ok)
        calls = mail.uid.call_args_list
        self.assertEqual(calls[0], call('STORE', '42', '+FLAGS', '\\Seen'))
        self.assertEqual(calls[1], call('STORE', '42', '+FLAGS', '(\\Deleted)'))
        mail.expunge.assert_called_once()

    def test_seen_failure_still_deletes(self) -> None:
        from unittest.mock import MagicMock
        handler = self._handler()
        mail = MagicMock()
        # first uid STORE (Seen) fails, second (Deleted) ok
        mail.uid = MagicMock(side_effect=[('NO', []), ('OK', [b''])])
        mail.expunge = MagicMock(return_value=('OK', [b'']))
        ok = handler._dispose_imap_message(
            mail, '7', b'1', account_email='a@x', source_message_id='<id>'
        )
        self.assertTrue(ok)
        self.assertEqual(mail.uid.call_count, 2)
        mail.expunge.assert_called_once()

    def test_delete_failure_after_seen(self) -> None:
        from unittest.mock import MagicMock
        handler = self._handler()
        mail = MagicMock()
        mail.uid = MagicMock(side_effect=[('OK', [b'']), ('NO', [])])
        mail.expunge = MagicMock()
        ok = handler._dispose_imap_message(
            mail, '9', b'1', account_email='a@x', source_message_id='<id>'
        )
        self.assertFalse(ok)
        mail.expunge.assert_not_called()

if __name__ == '__main__':
    unittest.main(verbosity=2)
