#!/usr/bin/env python3
# -*- coding: utf-8 --*-
import os
import re
import sys
import time
import base64
import queue
import threading
import hashlib
import configparser
import logging
import logging.handlers
import smtplib
import imaplib
import email
import signal
import socket
import ssl
import ipaddress
import tempfile
import mysql.connector
from mysql.connector import pooling
from pathlib import Path
from datetime import datetime, timedelta
from typing import Dict, List, Any, Optional
from urllib.parse import urlparse
# Сторонние зависимости, устанавливаемые через mail-proxy-setup.sh
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
from watchdog.observers import Observer
from watchdog.events import FileSystemEventHandler
# Константы конфигурации
CRYPTO_KEY_FILE = '/etc/mail-proxy/crypto.key'
DB_CONF_FILE = '/etc/mail-proxy/db.conf'
LOG_FILE = '/var/log/mail-proxy/mail-proxy-daemon.log'
MAILDIR_BASE = '/var/vmail/vmail1'
LOCAL_SMTP_HOST = '127.0.0.1'
LOCAL_SMTP_PORT = 25
IMAP_TIMEOUT = 60
SMTP_TIMEOUT = 300
IMAP_POLL_INTERVAL = 60
TOKEN_REFRESH_MARGIN = 300
RETRY_DELAY = 30
OUTGOING_QUEUE_MAXSIZE = 100
CHUNK_SIZE = 65536
INOTIFY_EVENT_TIMEOUT = 1.0
REQUIRE_TLS = True
# Допустимые значения настроек аккаунта (FIX P7), синхронизированы с ENUM в schema.sql:
#   external_accounts.auth_type           ENUM('plain','oauth2')
#   external_accounts.imap_encryption     ENUM('none','ssl','tls')
#   external_accounts.smtp_encryption     ENUM('none','ssl','tls')
VALID_AUTH_TYPES = ('plain', 'oauth2')
VALID_ENCRYPTION_MODES = ('none', 'ssl', 'tls')
# Размеры пулов воркеров
IMAP_WORKER_COUNT = 20
SMTP_WORKER_COUNT = 20
# Максимальный размер централизованных очередей задач
IMAP_QUEUE_MAXSIZE = 5000
SMTP_QUEUE_MAXSIZE = 1000
DB_POOL_SIZE = 12
# Директория для временных файлов демона
TEMP_DIR = '/var/spool/mail-proxy/tmp'
# Default maximum inbound message size in bytes.
# Aligned with configure_limits.sh (~200 MiB on the wire).
# Runtime override: MAX_INBOUND_MESSAGE_BYTES=104857600
_DEFAULT_MAX_INBOUND = 200 * 1024 * 1024
MAX_INBOUND_MESSAGE_BYTES = int(
    os.environ.get('MAX_INBOUND_MESSAGE_BYTES', _DEFAULT_MAX_INBOUND)
)
# After this many consecutive size skips for the same message, mark it Seen.
MAX_SIZE_SKIP_RETRIES = 3
# Hard cap on the process-local skip tracker.
MAX_SIZE_SKIP_TRACKER_ENTRIES = 10000
GCM_PREFIX = 'gcm:'
GCM_NONCE_LENGTH = 12
GCM_TAG_LENGTH = 16
EXPECTED_KEY_HEX_LENGTH = 64
# Настройка логирования
def setup_logging() -> logging.Logger:
    log_dir = os.path.dirname(LOG_FILE)
    os.makedirs(log_dir, mode=0o750, exist_ok=True)
    fmt = logging.Formatter(
        '%(asctime)s [%(levelname)s] (%(threadName)s) %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S'
    )
    handler = logging.handlers.RotatingFileHandler(
        LOG_FILE, maxBytes=10485760, backupCount=5, encoding='utf-8'
    )
    handler.setFormatter(fmt)
    root_logger = logging.getLogger()
    root_logger.setLevel(logging.INFO)
    root_logger.addHandler(handler)
   
    # Также дублируем в stdout для удобства отладки через journalctl
    stdout_handler = logging.StreamHandler(sys.stdout)
    stdout_handler.setFormatter(fmt)
    root_logger.addHandler(stdout_handler)
    return logging.getLogger('mail-proxy')
logger = setup_logging()

def validate_oauth_endpoint(url: str, field_name: str = 'endpoint') -> None:
    """Проверка OAuth endpoint на SSRF: только публичные HTTPS-URL."""
    parsed = urlparse(url)
    if parsed.scheme != 'https':
        raise ValueError(f"{field_name} must use HTTPS")
    host = (parsed.hostname or '').lower()
    if not host:
        raise ValueError(f"{field_name} has no hostname")
    blocked_hosts = {
        'localhost',
        'metadata.google.internal',
        'metadata.google',
        '169.254.169.254',
    }
    if host in blocked_hosts or 'metadata' in host:
        raise ValueError(f"blocked hostname in {field_name}: {host}")
    try:
        for family, _, _, _, sockaddr in socket.getaddrinfo(host, None):
            ip = ipaddress.ip_address(sockaddr[0])
            if (
                ip.is_loopback
                or ip.is_private
                or ip.is_link_local
                or ip.is_reserved
                or str(ip) == '169.254.169.254'
            ):
                raise ValueError(
                    f"blocked IP for {field_name} ({host} -> {ip})"
                )
    except socket.gaierror as exc:
        raise ValueError(f"cannot resolve hostname for {field_name}: {host}") from exc

class Cryptor:
    """Шифрование/дешифрование, совместимое с PHP-классом Cryptor."""
    IV_LENGTH = 16

    def __init__(self, key_file: str = CRYPTO_KEY_FILE):
        try:
            raw_key = Path(key_file).read_text(encoding='utf-8').strip()
            if not raw_key:
                raise ValueError("Crypto key file is empty")
            if len(raw_key) != EXPECTED_KEY_HEX_LENGTH or not all(
                c in '0123456789abcdefABCDEF' for c in raw_key
            ):
                raise ValueError(
                    f"crypto.key must be {EXPECTED_KEY_HEX_LENGTH} hex characters, "
                    f"got {len(raw_key)}"
                )
            self.key = hashlib.sha256(raw_key.encode('utf-8')).digest()
        except Exception as e:
            logger.critical(f"Failed to initialize Cryptor with file {key_file}: {e}")
            raise

    def encrypt(self, plain_text: str) -> str:
        from cryptography.hazmat.primitives.ciphers.aead import AESGCM
        nonce = os.urandom(GCM_NONCE_LENGTH)
        aesgcm = AESGCM(self.key)
        cipher_text = aesgcm.encrypt(nonce, plain_text.encode('utf-8'), None)
        tag = cipher_text[-GCM_TAG_LENGTH:]
        body = cipher_text[:-GCM_TAG_LENGTH]
        payload = nonce + tag + body
        return GCM_PREFIX + base64.b64encode(payload).decode('utf-8')

    def decrypt(self, encrypted_base64: str) -> str:
        if not encrypted_base64:
            return ""
        if encrypted_base64.startswith(GCM_PREFIX):
            return self._decrypt_gcm(encrypted_base64[len(GCM_PREFIX):])
        return self._decrypt_legacy_cbc(encrypted_base64)

    def _decrypt_gcm(self, payload_base64: str) -> str:
        from cryptography.hazmat.primitives.ciphers.aead import AESGCM
        try:
            data = base64.b64decode(payload_base64.encode('utf-8'), validate=True)
            min_len = GCM_NONCE_LENGTH + GCM_TAG_LENGTH + 1
            if len(data) < min_len:
                raise ValueError("GCM payload too short")
            nonce = data[:GCM_NONCE_LENGTH]
            tag = data[GCM_NONCE_LENGTH:GCM_NONCE_LENGTH + GCM_TAG_LENGTH]
            body = data[GCM_NONCE_LENGTH + GCM_TAG_LENGTH:]
            aesgcm = AESGCM(self.key)
            plain = aesgcm.decrypt(nonce, body + tag, None)
            return plain.decode('utf-8')
        except Exception as exc:
            logger.error("GCM decryption failed")
            raise ValueError("GCM decryption failed") from exc

    def _decrypt_legacy_cbc(self, encrypted_base64: str) -> str:
        try:
            data = base64.b64decode(encrypted_base64.encode('utf-8'), validate=True)
            if len(data) < self.IV_LENGTH:
                raise ValueError("CBC payload shorter than IV")
            encrypted = data[self.IV_LENGTH:]
            if not encrypted or len(encrypted) % 16 != 0:
                raise ValueError("CBC ciphertext length invalid")
            iv = data[:self.IV_LENGTH]
            cipher = Cipher(
                algorithms.AES(self.key), modes.CBC(iv), backend=default_backend()
            )
            decryptor = cipher.decryptor()
            decrypted_padded = decryptor.update(encrypted) + decryptor.finalize()
            padding_len = decrypted_padded[-1]
            if padding_len < 1 or padding_len > 16:
                raise ValueError("Invalid PKCS#7 padding")
            return decrypted_padded[:-padding_len].decode('utf-8')
        except Exception as exc:
            logger.error("CBC decryption failed")
            raise ValueError("CBC decryption failed") from exc

class Database:
    """Пул соединений MariaDB."""
    def __init__(self, conf_file: str = DB_CONF_FILE):
        self.conf_file = conf_file
        self._config = self._load_config()
        self._pool = pooling.MySQLConnectionPool(
            pool_name='mail_proxy_pool',
            pool_size=DB_POOL_SIZE,
            pool_reset_session=True,
            host=self._config['host'],
            user=self._config['user'],
            password=self._config['password'],
            database=self._config['database'],
            port=int(self._config['port']),
            charset='utf8mb4',
            collation='utf8mb4_unicode_ci',
            autocommit=False,
        )

    def _load_config(self) -> Dict[str, str]:
        if not os.path.exists(self.conf_file):
            raise FileNotFoundError(f"Database config file not found: {self.conf_file}")
        cfg = configparser.ConfigParser()
        cfg.read(self.conf_file)
        if 'db' not in cfg:
            raise KeyError(f"Missing [db] section in config file: {self.conf_file}")
        config = {
            'host': cfg.get('db', 'db_host'),
            'user': cfg.get('db', 'db_user'),
            'password': cfg.get('db', 'db_pass'),
            'database': cfg.get('db', 'db_name'),
            'port': cfg.get('db', 'db_port', fallback='3306'),
        }

        # Защита от запуска с незаполненным конфигом
        FORBIDDEN_VALUES = {'CHANGE_ME', '', 'your_password_here', 'changeme'}

        for key, value in config.items():
            if value.strip() in FORBIDDEN_VALUES:
                raise ValueError(
                    f"[db.conf] Параметр '{key}' содержит значение-заглушку '{value}'. "
                    f"Укажите реальные учётные данные в {self.conf_file}"
                )

        return config

    def get_connection(self):
        return self._pool.get_connection()
# =============================================================================
# Описание задачи для централизованных очередей
# =============================================================================
class ImapTask:
    """Задача опроса входящей почты для одного аккаунта одного референта."""
    def __init__(self, referent_data: Dict[str, Any], account: Dict[str, Any]):
        # Данные референта — нужны для доставки на локальный SMTP
        self.referent_data = referent_data
        # Данные внешнего аккаунта — IMAP-сервер, учётные данные
        self.account = account
class SmtpTask:
    """Задача отправки исходящего письма через внешний SMTP."""
    def __init__(self, referent_data: Dict[str, Any], account: Dict[str, Any],
                 recipients: List[str], file_path: Path):
        self.referent_data = referent_data
        self.account = account
        self.recipients = recipients
        self.file_path = file_path
# =============================================================================
# Общая логика работы с почтой — вынесена из воркер-потока в отдельный класс
# =============================================================================
class MailHandler:
    """
    Содержит бизнес-логику обработки входящей и исходящей почты.
    Не является потоком — используется воркер-пулами как разделяемый объект.
    Методы потокобезопасны; исключение — process-local трекер P4 size-skip.
    """
    def __init__(self, db: Database, cryptor: Cryptor):
        self._db = db
        self._cryptor = cryptor
        self._imap_size_skip_tracker: Dict[tuple, int] = {}
        self._imap_size_skip_lock = threading.Lock()

    def _plain_auth_login(self, acc: Dict[str, Any]) -> str:
        """Возвращает логин для plain-аутентификации (username или email)."""
        username = (acc.get('username') or '').strip()
        return username if username else acc['email']

    def _plain_auth_password(self, acc: Dict[str, Any]) -> str:
        """Расшифровывает password_enc из схемы external_accounts."""
        password_enc = acc.get('password_enc')
        if not password_enc:
            raise ValueError(f"password_enc is empty for account {acc.get('email', '?')}")
        return self._cryptor.decrypt(password_enc)

    def _validate_account_settings(
        self, acc: Dict[str, Any], encryption_field: str, protocol_label: str
    ) -> bool:
        """
        FIX P7: whitelist-проверка auth_type и encryption_field (imap_encryption
        или smtp_encryption) перед использованием значений из БД. При недопустимом
        или отсутствующем значении пишет ошибку в лог и возвращает False —
        вызывающий код обязан безопасно прервать обработку текущего аккаунта
        (return / продолжить со следующим аккаунтом), без подстановки умолчаний.
        """
        auth_type = acc.get('auth_type')
        if auth_type not in VALID_AUTH_TYPES:
            logger.error(
                f"Invalid auth_type ({auth_type!r}) for {protocol_label} "
                f"account {acc.get('email', '?')}; expected one of {VALID_AUTH_TYPES}"
            )
            return False
        encryption_value = acc.get(encryption_field)
        if encryption_value not in VALID_ENCRYPTION_MODES:
            logger.error(
                f"Invalid {encryption_field} ({encryption_value!r}) for {protocol_label} "
                f"account {acc.get('email', '?')}; expected one of {VALID_ENCRYPTION_MODES}"
            )
            return False
        return True
    # -------------------------------------------------------------------------
    # Получение OAuth2-токена (перенесено из ReferentWorkerThread без изменений)
    # -------------------------------------------------------------------------
    def get_oauth2_token(self, account_id: int) -> Optional[str]:
        """Возвращает действующий access token, при необходимости обновляет его."""
        conn = None
        cursor = None
        try:
            conn = self._db.get_connection()
            cursor = conn.cursor(dictionary=True)
            query = """
                SELECT access_token_enc, refresh_token_enc, expires_at
                FROM oauth_tokens
                WHERE account_id = %s
            """
            cursor.execute(query, (account_id,))
            token_data = cursor.fetchone()
            if not token_data:
                return None
            # Проверяем срок действия токена с защитным интервалом
            if token_data['expires_at'] and (
                datetime.now() + timedelta(seconds=TOKEN_REFRESH_MARGIN)
            ) < token_data['expires_at']:
                return self._cryptor.decrypt(token_data['access_token_enc'])
            # Токен истёк — пробуем обновить через refresh token
            if token_data['refresh_token_enc']:
                logger.info(
                    f"OAuth2 Access Token expired for account ID {account_id}, refreshing..."
                )
                return self._refresh_oauth2_token(account_id, token_data['refresh_token_enc'])
        except Exception as e:
            logger.error(f"Failed to fetch or refresh OAuth2 token: {e}")
        finally:
            if cursor: cursor.close()
            if conn: conn.close()
        return None
    def _refresh_oauth2_token(self, account_id: int, refresh_token_enc: str) -> Optional[str]:
        """Обновляет access token через refresh token и сохраняет результат в БД."""
        import requests
        conn = None
        cursor = None
        try:
            decrypted_refresh = self._cryptor.decrypt(refresh_token_enc)
            conn = self._db.get_connection()
            cursor = conn.cursor(dictionary=True)
            # Загружаем параметры провайдера для запроса обновления токена
            query = """
                SELECT ea.client_id, ea.client_secret_enc, op.token_endpoint
                FROM external_accounts ea
                JOIN oauth_providers op ON ea.provider = op.code
                WHERE ea.id = %s
            """
            cursor.execute(query, (account_id,))
            cfg = cursor.fetchone()
            if not cfg:
                return None
            decrypted_secret = self._cryptor.decrypt(cfg['client_secret_enc'])
            payload = {
                'client_id': cfg['client_id'],
                'client_secret': decrypted_secret,
                'refresh_token': decrypted_refresh,
                'grant_type': 'refresh_token'
            }
            validate_oauth_endpoint(cfg['token_endpoint'], 'token_endpoint')
            res = requests.post(cfg['token_endpoint'], data=payload, timeout=30)
            if res.status_code != 200:
                logger.error(
                    f"Token refresh HTTP error {res.status_code} for account ID {account_id}"
                )
                return None
            data = res.json()
            new_access_token = data.get('access_token')
            expires_in = data.get('expires_in', 3600)
            if not new_access_token:
                return None
            new_access_enc = self._cryptor.encrypt(new_access_token)
            new_expires_at = datetime.now() + timedelta(seconds=expires_in)
            # Обновляем токен в базе данных
            update_query = """
                UPDATE oauth_tokens
                SET access_token_enc = %s, expires_at = %s, updated_at = NOW()
                WHERE account_id = %s
            """
            cursor.execute(update_query, (new_access_enc, new_expires_at, account_id))
            conn.commit()
            logger.info(f"OAuth2 token successfully refreshed for account ID {account_id}")
            return new_access_token
        except Exception as e:
            logger.error(f"Error refreshing OAuth2 token: {e}")
        finally:
            if cursor: cursor.close()
            if conn: conn.close()
        return None

    def _decode_imap_fetch_meta(self, data: Any) -> str:
        """Извлекает метаданные из ответа imaplib.fetch()."""
        if not data or not data[0]:
            return ''
        part = data[0]
        if isinstance(part, tuple) and part[0]:
            meta = part[0]
            if isinstance(meta, bytes):
                return meta.decode('ascii', errors='replace')
            return str(meta)
        return ''

    def _parse_uid_and_rfc822_size(self, meta: str) -> tuple[Optional[str], Optional[int]]:
        uid_match = re.search(r'UID\s+(\d+)', meta, re.IGNORECASE)
        size_match = re.search(r'RFC822\.SIZE\s+(\d+)', meta, re.IGNORECASE)
        uid = uid_match.group(1) if uid_match else None
        size = int(size_match.group(1)) if size_match else None
        return uid, size

    def _parse_bodystructure_size(self, text: str) -> Optional[int]:
        """
        Defensive BODYSTRUCTURE size for single-part messages only.
        Example: BODYSTRUCTURE (("TEXT" "PLAIN" ("CHARSET" "US-ASCII") NIL NIL "7BIT" 1152 23))
        Octet count 1152 precedes line count 23 — unambiguous for non-multipart.
        Multipart structures have no reliable total size here → unknown.
        """
        if 'multipart' in text.lower():
            return None
        match = re.search(
            r'"(?:7BIT|8BIT|BINARY|BASE64|QUOTED-PRINTABLE)"\s+(\d+)\s+\d+\)',
            text,
            re.IGNORECASE,
        )
        return int(match.group(1)) if match else None

    def _imap_size_skip_key(
        self, account_id: int, uid: Optional[str], seq: str
    ) -> tuple:
        if uid:
            return (account_id, f'uid:{uid}')
        return (account_id, f'seq:{seq}')

    def _prune_imap_size_skip_tracker(self) -> None:
        if len(self._imap_size_skip_tracker) <= MAX_SIZE_SKIP_TRACKER_ENTRIES:
            return
        excess = len(self._imap_size_skip_tracker) - MAX_SIZE_SKIP_TRACKER_ENTRIES
        for key in list(self._imap_size_skip_tracker.keys())[:excess]:
            del self._imap_size_skip_tracker[key]

    def _clear_imap_size_skip_entry(
        self, account_id: int, uid: Optional[str], seq: str
    ) -> None:
        key = self._imap_size_skip_key(account_id, uid, seq)
        with self._imap_size_skip_lock:
            self._imap_size_skip_tracker.pop(key, None)

    def _mark_imap_message_seen(
        self, mail: imaplib.IMAP4, uid: Optional[str], num: bytes
    ) -> bool:
        if uid:
            try:
                status, _ = mail.uid('STORE', uid, '+FLAGS', '\\Seen')
                return status == 'OK'
            except Exception as e:
                logger.error(f"Failed UID STORE Seen for uid={uid}: {e}")
                return False
        try:
            status, _ = mail.store(num, '+FLAGS', '\\Seen')
            return status == 'OK'
        except Exception as e:
            logger.error(f"Failed STORE Seen for seq={num!r}: {e}")
            return False

    def _probe_imap_message_size(
        self, mail: imaplib.IMAP4, num: bytes
    ) -> tuple[Optional[str], Optional[int], Optional[str]]:
        """
        Returns (uid, size_bytes, probe_error).
        size_bytes is None when unknown (fail-closed). probe_error set on exception.
        """
        uid: Optional[str] = None
        try:
            status, data = mail.fetch(num, '(UID RFC822.SIZE)')
            if status != 'OK':
                return uid, None, f'UID RFC822.SIZE fetch status={status!r}'
            meta = self._decode_imap_fetch_meta(data)
            uid, size = self._parse_uid_and_rfc822_size(meta)
            if size is not None:
                return uid, size, None

            status, data = mail.fetch(num, '(BODYSTRUCTURE)')
            if status != 'OK':
                return uid, None, f'BODYSTRUCTURE fetch status={status!r}'
            bodystruct_meta = self._decode_imap_fetch_meta(data)
            bodystruct_text = bodystruct_meta
            if (
                data
                and data[0]
                and isinstance(data[0], tuple)
                and len(data[0]) > 1
                and data[0][1]
            ):
                payload = data[0][1]
                if isinstance(payload, bytes):
                    bodystruct_text = payload.decode('ascii', errors='replace')
                else:
                    bodystruct_text = str(payload)
            size = self._parse_bodystructure_size(bodystruct_text)
            return uid, size, None
        except Exception as e:
            return uid, None, str(e)

    def _handle_imap_size_skip(
        self,
        mail: imaplib.IMAP4,
        acc: Dict[str, Any],
        num: bytes,
        uid: Optional[str],
        size: Optional[int],
        limit: int,
        probe_error: Optional[str] = None,
    ) -> None:
        account_id = int(acc['id'])
        account_email = acc['email']
        seq = num.decode() if isinstance(num, bytes) else str(num)
        key = self._imap_size_skip_key(account_id, uid, seq)

        with self._imap_size_skip_lock:
            count = self._imap_size_skip_tracker.get(key, 0) + 1
            self._imap_size_skip_tracker[key] = count
            self._prune_imap_size_skip_tracker()

        if probe_error:
            logger.warning(
                f"IMAP size probe failed (fail-closed skip): "
                f"account={account_email} uid={uid or 'n/a'} error={probe_error}"
            )
        elif size is None:
            logger.warning(
                f"IMAP size probe failed (fail-closed skip): "
                f"account={account_email} uid={uid or 'n/a'} error=size unknown"
            )
        else:
            logger.warning(
                f"IMAP size skip: account={account_email} uid={uid or seq} "
                f"size={size} limit={limit} skip={count}/{MAX_SIZE_SKIP_RETRIES}"
            )

        if count >= MAX_SIZE_SKIP_RETRIES:
            logger.warning(
                f"IMAP size skip limit reached; marking Seen: "
                f"account={account_email} uid={uid or seq} "
                f"size={size if size is not None else 'unknown'} "
                f"limit={limit} retries={count}"
            )
            if self._mark_imap_message_seen(mail, uid, num):
                with self._imap_size_skip_lock:
                    self._imap_size_skip_tracker.pop(key, None)

    # -------------------------------------------------------------------------
    # Входящая почта: опрос внешнего IMAP и доставка на локальный SMTP
    # -------------------------------------------------------------------------
    def poll_external_imap(self, task: ImapTask) -> None:
        """Опрашивает IMAP-ящик аккаунта и доставляет новые письма на локальный SMTP."""
        acc = task.account
        referent_data = task.referent_data
        logger.info(f"Polling external IMAP account: {acc['email']}")
        try:
            if not self._validate_account_settings(acc, 'imap_encryption', 'IMAP'):
                return
            imap_encryption = acc.get('imap_encryption')
            if imap_encryption == 'ssl':
                mail = imaplib.IMAP4_SSL(
                    acc['imap_host'], int(acc['imap_port']), timeout=IMAP_TIMEOUT
                )
            elif imap_encryption == 'tls':
                mail = imaplib.IMAP4(
                    acc['imap_host'], int(acc['imap_port']), timeout=IMAP_TIMEOUT
                )
                tls_context = ssl.create_default_context()
                mail.starttls(ssl_context=tls_context)
            elif imap_encryption == 'none':
                mail = imaplib.IMAP4(
                    acc['imap_host'], int(acc['imap_port']), timeout=IMAP_TIMEOUT
                )
            else:
                logger.error(
                    f"Unknown or missing imap_encryption "
                    f"({imap_encryption!r}) for account {acc['email']}"
                )
                return
            # Аутентификация: OAuth2 или plain
            if acc['auth_type'] == 'oauth2':
                token = self.get_oauth2_token(acc['id'])
                if not token:
                    logger.error(
                        f"Cannot obtain OAuth2 token for IMAP account {acc['email']}"
                    )
                    return
                mail.authenticate(
                    'XOAUTH2',
                    lambda x: (
                        f"user={acc['email']}\x01auth=Bearer {token}\x01\x01"
                    ).encode('utf-8')
                )
            else:
                login = self._plain_auth_login(acc)
                decrypted_pass = self._plain_auth_password(acc)
                mail.login(login, decrypted_pass)
            mail.select('INBOX')
            status, response = mail.search(None, 'UNSEEN')
            if status != 'OK':
                mail.logout()
                return
            msg_nums = response[0].split()
            logger.info(
                f"Found {len(msg_nums)} unread messages for {acc['email']}"
            )
            account_id = int(acc['id'])
            for num in msg_nums:
                seq = num.decode() if isinstance(num, bytes) else str(num)
                uid, msg_size, probe_error = self._probe_imap_message_size(mail, num)

                if probe_error is not None:
                    self._handle_imap_size_skip(
                        mail, acc, num, uid, None,
                        MAX_INBOUND_MESSAGE_BYTES, probe_error=probe_error,
                    )
                    continue

                if msg_size is None:
                    self._handle_imap_size_skip(
                        mail, acc, num, uid, None, MAX_INBOUND_MESSAGE_BYTES,
                    )
                    continue

                if msg_size > MAX_INBOUND_MESSAGE_BYTES:
                    self._handle_imap_size_skip(
                        mail, acc, num, uid, msg_size, MAX_INBOUND_MESSAGE_BYTES,
                    )
                    continue

                status, data = mail.fetch(num, '(RFC822)')
                if status != 'OK' or not data or not data[0]:
                    continue

                # Ограничение стандартного imaplib:
                # метод fetch() возвращает RFC822 целиком в памяти процесса.
                # Потоковое получение письма напрямую в файл штатными
                # средствами imaplib не поддерживается — это нижний предел
                # без замены протокольного слоя imaplib (вне рамок патча).
                raw_email = data[0][1]
                # Освобождаем ссылку на весь IMAP-ответ как можно раньше:
                # data может содержать дополнительные элементы (FLAGS и т.п.),
                # удерживающие в памяти связанные буферы дольше необходимого.
                data = None

                temp_path = None
                try:
                    fd, temp_name = tempfile.mkstemp(
                        dir=TEMP_DIR, prefix='in_'
                    )
                    os.close(fd)
                    temp_path = Path(temp_name)

                    # Запись через memoryview чанками вместо одного write()
                    # на полный буфер: не снижает пик при fetch() (это уже
                    # внутри imaplib), но уменьшает время удержания второй
                    # полной копии письма в процессе и избегает лишнего
                    # промежуточного объекта при системном вызове write().
                    view = memoryview(raw_email)
                    raw_email = None
                    with open(temp_path, 'wb') as tmp_f:
                        chunk_size = 1024 * 1024
                        for offset in range(0, len(view), chunk_size):
                            tmp_f.write(view[offset:offset + chunk_size])
                    view.release()
                    del view

                    if self._deliver_to_local_smtp(temp_path, referent_data):
                        mail.store(num, '+FLAGS', '\\Seen')
                        self._clear_imap_size_skip_entry(account_id, uid, seq)
                finally:
                    if temp_path is not None and temp_path.exists():
                        try:
                            temp_path.unlink()
                        except OSError as unlink_err:
                            logger.warning(
                                f"Failed to remove temp file {temp_path}: {unlink_err}"
                            )
            mail.close()
            mail.logout()
        except Exception as e:
            logger.error(f"IMAP session exception for {acc['email']}: {e}")
    def _deliver_to_local_smtp(
        self, mail_file: Path, referent_data: Dict[str, Any]
    ) -> bool:
        """Потоковая доставка письма из временного файла на локальный SMTP Postfix."""
        smtp = None
        try:
            with open(mail_file, 'rb') as header_f:
                msg = email.message_from_binary_file(header_f)
            local_rcpts = self._resolve_local_recipients(msg, referent_data)
            if not local_rcpts:
                local_rcpts = [referent_data['local_inbox']]
            logger.info(
                f"Delivering incoming external mail to local SMTP: {local_rcpts}"
            )
            mail_from = msg.get('From', 'forwarder@local-proxy')
            smtp = smtplib.SMTP(
                LOCAL_SMTP_HOST, LOCAL_SMTP_PORT, timeout=SMTP_TIMEOUT
            )
            return self._stream_file_via_smtp(
                smtp, mail_from, local_rcpts, mail_file
            )
        except Exception as e:
            logger.error(f"Local SMTP delivery failed: {e}")
            return False
        finally:
            if smtp is not None:
                try:
                    smtp.quit()
                except Exception:
                    pass

    def _stream_file_via_smtp(
        self,
        server: smtplib.SMTP,
        mail_from: str,
        recipients: List[str],
        file_path: Path,
    ) -> bool:
        """Потоковая передача содержимого файла через SMTP DATA."""
        code, resp = server.mail(mail_from)
        if code != 250:
            logger.error(f"MAIL FROM rejected: {code} {resp}")
            return False
        accepted_rcpts = 0
        rejected_rcpts = []
        for rcpt in recipients:
            code, resp = server.rcpt(rcpt)
            if code not in (250, 251):
                logger.warning(f"RCPT TO <{rcpt}> rejected: {code} {resp}")
                rejected_rcpts.append((rcpt, code, resp))
            else:
                accepted_rcpts += 1
        if accepted_rcpts == 0:
            logger.error(
                f"All RCPT TO rejected for {file_path.name}, aborting "
                f"DATA (no recipients accepted): {rejected_rcpts}"
            )
            server.rset()
            return False
        code, resp = server.docmd('DATA')
        if code != 354:
            logger.error(f"DATA command rejected: {code} {resp}")
            return False

        with open(file_path, 'rb') as f:
            carry = b''
            while True:
                chunk = f.read(CHUNK_SIZE)
                if not chunk:
                    break

                data = carry + chunk

                if len(data) > 1:
                    send_data = data[:-1].replace(b'\n.', b'\n..')
                    server.sock.sendall(send_data)
                    carry = data[-1:]
                else:
                    carry = data

            if carry:
                server.sock.sendall(carry.replace(b'\n.', b'\n..'))

        server.sock.sendall(b'\r\n.\r\n')
        code, resp = server.getreply()
        if code != 250:
            logger.error(f"DATA rejected: {code} {resp}")
            return False
        return True
    def _resolve_local_recipients(
        self, msg: email.message.Message, referent_data: Dict[str, Any]
    ) -> List[str]:
        """Сопоставляет адреса получателей письма с клиентами референта в БД."""
        to_header = msg.get('To', '')
        cc_header = msg.get('Cc', '')
        all_rcpts = email.utils.getaddresses([to_header, cc_header])
        resolved = []
        conn = None
        cursor = None
        try:
            conn = self._db.get_connection()
            cursor = conn.cursor()
            for name, addr in all_rcpts:
                if not addr:
                    continue
                # Проверяем, привязан ли адрес к нашему референту
                query = (
                    "SELECT email FROM clients "
                    "WHERE email = %s AND referent_id = %s AND active = 1"
                )
                cursor.execute(query, (addr, referent_data['id']))
                row = cursor.fetchone()
                if row:
                    resolved.append(referent_data['local_inbox'])
                    break
        except Exception as e:
            logger.error(f"Error resolving local clients: {e}")
        finally:
            if cursor: cursor.close()
            if conn: conn.close()
        return list(set(resolved))
    # -------------------------------------------------------------------------
    # Исходящая почта: потоковая отправка через внешний SMTP
    # -------------------------------------------------------------------------
    def send_via_external_smtp(self, task: SmtpTask) -> bool:
        """
        Потоковая отправка письма через внешний SMTP.
        Реализация полностью соответствует патчу PROMPT 02.
        """
        acc = task.account
        file_path = task.file_path
        recipients = task.recipients
        server = None
        try:
            if not self._validate_account_settings(acc, 'smtp_encryption', 'SMTP'):
                return False
            logger.info(
                f"Connecting to external SMTP {acc['smtp_host']}:{acc['smtp_port']} "
                f"for {acc['email']}"
            )
            # Шаг 1: Соединение и STARTTLS
            server = smtplib.SMTP(
                acc['smtp_host'], int(acc['smtp_port']), timeout=SMTP_TIMEOUT
            )
            server.ehlo()
            smtp_encryption = acc.get('smtp_encryption')
            if REQUIRE_TLS and smtp_encryption == 'tls':
                if not server.has_extn('STARTTLS'):
                    logger.error(
                        f"STARTTLS required but not offered by "
                        f"{acc['smtp_host']}:{acc['smtp_port']} for {acc['email']}"
                    )
                    server.quit()
                    return False
            if server.has_extn('STARTTLS'):
                server.starttls()
                server.ehlo()
            # Шаг 2: Аутентификация
            if acc['auth_type'] == 'oauth2':
                token = self.get_oauth2_token(acc['id'])
                if not token:
                    logger.error(
                        f"Cannot obtain OAuth2 token for SMTP account {acc['email']}"
                    )
                    server.quit()
                    return False
                auth_str = f"user={acc['email']}\x01auth=Bearer {token}\x01\x01"
                code, resp = server.docmd(
                    'AUTH',
                    'XOAUTH2 ' + base64.b64encode(
                        auth_str.encode('utf-8')
                    ).decode('utf-8')
                )
                if code != 235:
                    logger.error(
                        f"XOAUTH2 AUTH failed for {acc['email']}: {code} {resp}"
                    )
                    server.quit()
                    return False
            else:
                login = self._plain_auth_login(acc)
                decrypted_pass = self._plain_auth_password(acc)
                server.login(login, decrypted_pass)
            # Шаг 3: MAIL FROM + RCPT TO
            code, resp = server.mail(acc['email'])
            if code != 250:
                logger.error(f"MAIL FROM rejected: {code} {resp}")
                server.rset()
                server.quit()
                return False
            accepted_rcpts = 0
            rejected_rcpts = []
            for rcpt in recipients:
                code, resp = server.rcpt(rcpt)
                if code not in (250, 251):
                    logger.warning(f"RCPT TO <{rcpt}> rejected: {code} {resp}")
                    rejected_rcpts.append((rcpt, code, resp))
                else:
                    accepted_rcpts += 1
            if accepted_rcpts == 0:
                logger.error(
                    f"All RCPT TO rejected for {file_path.name}, aborting "
                    f"DATA (no recipients accepted): {rejected_rcpts}"
                )
                server.rset()
                server.quit()
                return False
            # Шаг 4: Потоковая передача DATA чанками
            code, resp = server.docmd('DATA')
            if code != 354:
                logger.error(f"DATA command rejected: {code} {resp}")
                server.rset()
                server.quit()
                return False
            file_size = file_path.stat().st_size
            sent_bytes = 0

            with open(file_path, 'rb') as f:
                carry = b''
                while True:
                    chunk = f.read(CHUNK_SIZE)
                    if not chunk:
                        break

                    data = carry + chunk

                    if len(data) > 1:
                        send_data = data[:-1].replace(b'\n.', b'\n..')
                        server.sock.sendall(send_data)
                        sent_bytes += len(send_data)
                        carry = data[-1:]
                    else:
                        carry = data

                if carry:
                    final_data = carry.replace(b'\n.', b'\n..')
                    server.sock.sendall(final_data)
                    sent_bytes += len(final_data)

            server.sock.sendall(b'\r\n.\r\n')
            code, resp = server.getreply()
            if code != 250:
                logger.error(
                    f"DATA rejected: {code} {resp} "
                    f"(sent {sent_bytes}/{file_size} bytes)"
                )
                server.quit()
                return False
            server.quit()
            logger.info(
                f"Email {file_path.name} sent via external SMTP "
                f"({sent_bytes} bytes)"
            )
            return True
        except Exception as e:
            logger.error(f"SMTP delivery error for {acc['email']}: {e}")
            if server:
                try:
                    server.quit()
                except Exception:
                    pass
            return False
# =============================================================================
# Пул IMAP-воркеров
# =============================================================================
class ImapWorkerPool:
    """
    Пул из IMAP_WORKER_COUNT потоков, обрабатывающих задачи опроса входящей почты.
    Все потоки разделяют одну очередь imap_task_queue.
    """
    def __init__(self, task_queue: queue.Queue, mail_handler: MailHandler,
                 stop_event: threading.Event):
        self._queue = task_queue
        self._handler = mail_handler
        self._stop_event = stop_event
        self._workers: List[threading.Thread] = []
    def start(self) -> None:
        """Запускает все воркер-потоки пула."""
        for i in range(IMAP_WORKER_COUNT):
            t = threading.Thread(
                target=self._worker_loop,
                name=f"ImapWorker-{i}",
                daemon=True
            )
            t.start()
            self._workers.append(t)
        logger.info(f"IMAP worker pool started: {IMAP_WORKER_COUNT} threads")
    def _worker_loop(self) -> None:
        """Основной цикл воркера — извлекает задачи из очереди и выполняет их."""
        while not self._stop_event.is_set():
            try:
                # Таймаут позволяет регулярно проверять флаг остановки
                task: ImapTask = self._queue.get(timeout=1.0)
                try:
                    self._handler.poll_external_imap(task)
                except Exception as e:
                    logger.error(
                        f"IMAP task error for {task.account.get('email', '?')}: {e}",
                        exc_info=True
                    )
                finally:
                    # task_done вызывается всегда — даже при ошибке
                    self._queue.task_done()
            except queue.Empty:
                continue
    def join(self, timeout: float = 30.0) -> None:
        """Ожидает завершения всех потоков пула."""
        for t in self._workers:
            t.join(timeout=timeout)
# =============================================================================
# Пул SMTP-воркеров
# =============================================================================
class SmtpWorkerPool:
    """
    Пул из SMTP_WORKER_COUNT потоков, обрабатывающих задачи отправки исходящей почты.
    Все потоки разделяют одну очередь smtp_task_queue.
    """
    def __init__(self, task_queue: queue.Queue, mail_handler: MailHandler,
                 stop_event: threading.Event):
        self._queue = task_queue
        self._handler = mail_handler
        self._stop_event = stop_event
        self._workers: List[threading.Thread] = []
    def start(self) -> None:
        """Запускает все воркер-потоки пула."""
        for i in range(SMTP_WORKER_COUNT):
            t = threading.Thread(
                target=self._worker_loop,
                name=f"SmtpWorker-{i}",
                daemon=True
            )
            t.start()
            self._workers.append(t)
        logger.info(f"SMTP worker pool started: {SMTP_WORKER_COUNT} threads")
    def _worker_loop(self) -> None:
        """Основной цикл воркера — извлекает задачи из очереди и выполняет их."""
        while not self._stop_event.is_set():
            try:
                task: SmtpTask = self._queue.get(timeout=1.0)
                try:
                    success = self._handler.send_via_external_smtp(task)
                    if not success:
                        logger.warning(
                            f"SMTP task failed for {task.file_path.name}, "
                            f"will retry in next scan cycle"
                        )
                    else:
                        # Удаляем файл только после подтверждённой отправки
                        self._safe_delete_file(task.file_path)
                except Exception as e:
                    logger.error(
                        f"SMTP task error for {task.file_path.name}: {e}",
                        exc_info=True
                    )
                finally:
                    # Освобождаем файл из реестра "в очереди/обрабатывается".
                    daemon = getattr(self._handler, "_daemon", None)
                    if daemon is not None:
                        daemon._release_outgoing_file(task.file_path)
                    self._queue.task_done()
            except queue.Empty:
                continue
    def _safe_delete_file(self, file_path: Path) -> None:
        """Безопасное удаление обработанного файла из Maildir/new."""
        try:
            if file_path.exists():
                file_path.unlink()
                logger.info(f"Deleted processed file: {file_path.name}")
        except Exception as e:
            logger.error(f"Failed to delete file {file_path}: {e}")
    def join(self, timeout: float = 30.0) -> None:
        """Ожидает завершения всех потоков пула."""
        for t in self._workers:
            t.join(timeout=timeout)
# =============================================================================
# Планировщик IMAP-опроса
# =============================================================================
class ImapPoller(threading.Thread):
    """
    Фоновый поток-планировщик.
    Раз в IMAP_POLL_INTERVAL секунд создаёт задачи ImapTask
    для всех активных референтов и их аккаунтов и помещает в imap_task_queue.
    """
    def __init__(self, imap_task_queue: queue.Queue, db: Database,
                 stop_event: threading.Event):
        super().__init__(name='ImapPoller', daemon=True)
        self._queue = imap_task_queue
        self._db = db
        self._stop_event = stop_event
        self._skipped_tasks = 0
    def run(self) -> None:
        logger.info("ImapPoller started")
        while not self._stop_event.is_set():
            try:
                self._enqueue_imap_tasks()
            except Exception as e:
                logger.error(f"ImapPoller error: {e}", exc_info=True)
            # Ожидаем следующий интервал опроса, проверяя флаг остановки
            for _ in range(IMAP_POLL_INTERVAL):
                if self._stop_event.is_set():
                    break
                time.sleep(1)
        logger.info("ImapPoller stopped")
    def _enqueue_imap_tasks(self) -> None:
        """Загружает активных референтов и их аккаунты, создаёт задачи для пула."""
        referents = self._load_active_referents()
        task_count = 0
        for ref in referents:
            accounts = self._load_accounts_for_referent(ref['id'])
            for acc in accounts:
                if not acc.get('imap_host'):
                    continue
                task = ImapTask(referent_data=ref, account=acc)
                try:
                    self._queue.put_nowait(task)
                    task_count += 1
                except queue.Full:
                    self._skipped_tasks += 1
                    logger.warning(
                        f"IMAP task queue full, skipping account {acc['email']}"
                    )
        if self._skipped_tasks:
            logger.warning(
                f"IMAP poller skipped {self._skipped_tasks} tasks due to full queue"
            )
            self._skipped_tasks = 0
        if task_count:
            logger.info(f"ImapPoller enqueued {task_count} IMAP tasks")
    def _load_active_referents(self) -> List[Dict[str, Any]]:
        """Загружает список активных референтов из БД."""
        conn = None
        cursor = None
        try:
            conn = self._db.get_connection()
            cursor = conn.cursor(dictionary=True)
            cursor.execute(
                "SELECT id, username, local_inbox, local_outbox "
                "FROM referents WHERE active = 1"
            )
            return cursor.fetchall()
        except Exception as e:
            logger.error(f"ImapPoller: failed to load referents: {e}")
            return []
        finally:
            if cursor: cursor.close()
            if conn: conn.close()
    def _load_accounts_for_referent(self, referent_id: int) -> List[Dict[str, Any]]:
        """Загружает активные аккаунты референта из БД."""
        conn = None
        cursor = None
        try:
            conn = self._db.get_connection()
            cursor = conn.cursor(dictionary=True)
            cursor.execute(
                """
                SELECT id, email, auth_type, imap_host, imap_port,
                       username, password_enc, imap_encryption,
                       smtp_host, smtp_port, smtp_encryption,
                       provider, client_id, client_secret_enc
                FROM external_accounts
                WHERE referent_id = %s AND active = 1
                """,
                (referent_id,)
            )
            return cursor.fetchall()
        except Exception as e:
            logger.error(
                f"ImapPoller: failed to load accounts for referent {referent_id}: {e}"
            )
            return []
        finally:
            if cursor: cursor.close()
            if conn: conn.close()
            
# =============================================================================
# Обработчик watchdog для отслеживания новых файлов в Maildir/new
# =============================================================================
class MaildirHandler(FileSystemEventHandler):
    """
    Обработчик событий watchdog.
    При появлении нового файла в Maildir/new создаёт SmtpTask
    и помещает в централизованную smtp_task_queue.
    """
    def __init__(self, referent_data: Dict[str, Any], smtp_task_queue: queue.Queue,
                 db: Database, daemon):
        self.referent_data = referent_data
        self._smtp_queue = smtp_task_queue
        self._db = db
        self._daemon = daemon
    def on_created(self, event) -> None:
        if event.is_directory:
            return
        file_path = Path(event.src_path)
        # Обрабатываем только файлы непосредственно в директории new
        if file_path.parent.name != 'new':
            return
        logger.info(
            f"Watchdog: new email file for referent "
            f"{self.referent_data['id']}: {file_path.name}"
        )
        # Читаем только заголовки для определения получателей
        try:
            from email.parser import BytesHeaderParser
            with open(file_path, 'rb') as f:
                msg = BytesHeaderParser().parse(f)
        except Exception as e:
            logger.error(f"Failed to parse headers from {file_path.name}: {e}")
            return
        to_header = msg.get('To', '')
        cc_header = msg.get('Cc', '')
        all_rcpts = email.utils.getaddresses([to_header, cc_header])
        recipients = [addr for _, addr in all_rcpts if addr]
        if not recipients:
            logger.warning(f"No recipients in {file_path.name}, skipping")
            return
        # Загружаем первый доступный аккаунт референта для отправки
        account = self._load_first_account()
        if not account:
            logger.warning(
                f"No active accounts for referent {self.referent_data['id']}, "
                f"file {file_path.name} will be picked up on next scan"
            )
            return
        task = SmtpTask(
            referent_data=self.referent_data,
            account=account,
            recipients=recipients,
            file_path=file_path
        )

        if not self._daemon._reserve_outgoing_file(file_path):
            return

        try:
            self._smtp_queue.put_nowait(task)
        except queue.Full:
            self._daemon._release_outgoing_file(file_path)
            logger.warning(
                f"SMTP task queue full, file {file_path.name} "
                f"will be picked up on next scan cycle"
            )
    def _load_first_account(self) -> Optional[Dict[str, Any]]:
        """Загружает первый активный внешний аккаунт референта."""
        conn = None
        cursor = None
        try:
            conn = self._db.get_connection()
            cursor = conn.cursor(dictionary=True)
            cursor.execute(
                """
                SELECT id, email, auth_type, smtp_host, smtp_port,
                       username, password_enc, smtp_encryption,
                       provider, client_id, client_secret_enc
                FROM external_accounts
                WHERE referent_id = %s AND active = 1
                LIMIT 1
                """,
                (self.referent_data['id'],)
            )
            return cursor.fetchone()
        except Exception as e:
            logger.error(f"Failed to load account for referent: {e}")
            return None
        finally:
            if cursor: cursor.close()
            if conn: conn.close()
# =============================================================================
# Главный управляющий класс демона (замена)
# =============================================================================
class ProxyDaemon:
    """
    Главный управляющий класс демона почтового прокси.
    Управляет жизненным циклом пулов воркеров, планировщика и watchdog-наблюдателя.
    """
    def __init__(self):
        self._ensure_temp_dir()
        self._db = Database()
        self._cryptor = Cryptor()
        self._stop_event = threading.Event()
        # Централизованные очереди задач
        self._imap_task_queue: queue.Queue = queue.Queue(maxsize=IMAP_QUEUE_MAXSIZE)
        self._smtp_task_queue: queue.Queue = queue.Queue(maxsize=SMTP_QUEUE_MAXSIZE)
        # Разделяемый обработчик бизнес-логики почты
        self._mail_handler = MailHandler(self._db, self._cryptor)
        self._mail_handler._daemon = self
        # Пулы воркеров
        self._imap_pool = ImapWorkerPool(
            self._imap_task_queue, self._mail_handler, self._stop_event
        )
        self._smtp_pool = SmtpWorkerPool(
            self._smtp_task_queue, self._mail_handler, self._stop_event
        )
        # Планировщик IMAP-опроса
        self._imap_poller = ImapPoller(
            self._imap_task_queue, self._db, self._stop_event
        )
        # Watchdog-наблюдатель за Maildir/new
        self._observer: Optional[Observer] = None
        # Явный флаг фактического запуска потока Observer (Observer.start()
        # был вызван). Объект Observer() всегда truthy после конструктора,
        # независимо от того, запущен ли внутренний поток — поэтому
        # "if self._observer:" недостаточно для безопасного join() при
        # остановке. См. shutdown() и _setup_watchdog_for_referent().
        self._observer_started: bool = False

        # Реестр referent_id, для которых уже зарегистрирован watchdog.
        # Используется в _sync_database_state() для определения новых и деактивированных референтов.
        # Защищён блокировкой — _sync_database_state() вызывается из главного потока,
        # но в будущем может вызываться из отдельного потока-супервизора.
        self._watched_referent_ids: set = set()
        self._watched_lock = threading.Lock()

        # Реестр: referent_id -> строковый путь Maildir/new.
        # Используется _unschedule_watchdog_for_referent() для поиска watch object
        # без обращения к внутренним структурам watchdog.
        self._referent_path_registry: Dict[int, str] = {}

        # Файлы, уже находящиеся в SMTP-очереди либо обрабатываемые SMTP-воркером.
        # Используется для защиты от дублей при watchdog-событиях и периодическом
        # backlog-rescan.
        self._queued_outgoing_files: set = set()
        self._queued_files_lock = threading.Lock()

        # Защита от двойного вызова _shutdown()
        self._shutdown_lock = threading.Lock()
        self._is_shutdown = False
        # Регистрация сигналов POSIX для graceful shutdown
        signal.signal(signal.SIGTERM, self._handle_signal)
        signal.signal(signal.SIGINT, self._handle_signal)

        # Регистрация SIGUSR1 для переоткрытия лог-файла после ротации logrotate.
        # Стандартный механизм: logrotate переименовывает файл, создаёт новый,
        # затем посылает SIGUSR1 — демон переоткрывает дескриптор на новый файл.
        signal.signal(signal.SIGUSR1, self._handle_log_reopen)

    def start(self) -> None:
        """Запускает все компоненты демона и входит в главный цикл супервизора."""
        logger.info("Initializing ProxyDaemon components...")
        # Шаг 1: Запускаем пулы воркеров
        self._imap_pool.start()
        self._smtp_pool.start()
        # Шаг 2: Запускаем планировщик IMAP
        self._imap_poller.start()
        # Шаг 3: Настраиваем watchdog для всех активных референтов
        self._observer = Observer()
        referents = self._load_referents()
        if not referents:
            logger.warning("No active referents found in database.")
        for ref in referents:
            self._setup_watchdog_for_referent(ref)
        # Шаг 4: Подбираем письма, оставшиеся с прошлого запуска
        for ref in referents:
            self._scan_existing_outgoing(ref)
        if referents:
            self._observer.start()
            self._observer_started = True
            logger.info("Watchdog file system observer started")
        logger.info(
            f"ProxyDaemon operational: "
            f"{IMAP_WORKER_COUNT} IMAP workers, "
            f"{SMTP_WORKER_COUNT} SMTP workers, "
            f"{len(referents)} referents watched"
        )
        # Главный цикл супервизора: синхронизирует список референтов с БД
        while not self._stop_event.is_set():
            try:
                self._sync_database_state()
            except Exception as e:
                logger.error(f"Supervisor sync error: {e}")
            time.sleep(60)
    def _setup_watchdog_for_referent(self, ref: Dict[str, Any]) -> None:
        """Регистрирует watchdog-обработчик для Maildir/new референта."""
        maildir_new = Path(ref['local_outbox']) / 'new'
        if not maildir_new.exists():
            try:
                maildir_new.mkdir(parents=True, exist_ok=True)
            except Exception as e:
                logger.error(
                    f"Cannot create maildir {maildir_new} "
                    f"for referent {ref['id']}: {e}"
                )
                return
        handler = MaildirHandler(
            ref,
            self._smtp_task_queue,
            self._db,
            self
        )
        self._observer.schedule(handler, path=str(maildir_new), recursive=False)

        # Ленивый старт: если демон изначально стартовал без активных
        # референтов (Observer.start() не вызывался в start()), а первый
        # референт появился позже через _sync_database_state() — поток
        # Observer нужно запустить здесь, иначе зарегистрированный handler
        # никогда не получит файловые события.
        if not self._observer_started:
            self._observer.start()
            self._observer_started = True
            logger.info("Watchdog file system observer started (lazy)")

        # Синхронно обновляем оба внутренних реестра только после успешной
        # регистрации watchdog. Это исключает рассинхронизацию между
        # фактически зарегистрированными наблюдателями и состоянием
        # _watched_referent_ids.
        with self._watched_lock:
            self._watched_referent_ids.add(ref['id'])
            self._referent_path_registry[ref['id']] = str(maildir_new)

        logger.info(f"Watchdog configured for referent {ref['id']}: {maildir_new}")
    def _reserve_outgoing_file(self, file_path: Path) -> bool:
        """
        Атомарно резервирует файл для постановки в SMTP-очередь.
        Возвращает False, если файл уже находится в очереди или обработке.
        """
        key = str(file_path.resolve())
        with self._queued_files_lock:
            if key in self._queued_outgoing_files:
                return False
            self._queued_outgoing_files.add(key)
            return True

    def _release_outgoing_file(self, file_path: Path) -> None:
        """Удаляет файл из реестра очереди/обработки."""
        key = str(file_path.resolve())
        with self._queued_files_lock:
            self._queued_outgoing_files.discard(key)

    def _scan_existing_outgoing(self, ref: Dict[str, Any]) -> None:
        """
        При старте демона сканирует Maildir/new на наличие необработанных писем
        и добавляет их в smtp_task_queue для доставки.
        """
        maildir_new = Path(ref['local_outbox']) / 'new'
        if not maildir_new.exists():
            return
        try:
            files = [f for f in maildir_new.iterdir() if f.is_file()]
            if not files:
                return
            logger.info(
                f"Found {len(files)} backlog files for referent {ref['id']}"
            )
            # Загружаем аккаунт один раз для всего пакета файлов
            conn = self._db.get_connection()
            cursor = conn.cursor(dictionary=True)
            cursor.execute(
                """
                SELECT id, email, auth_type, smtp_host, smtp_port,
                       username, password_enc, smtp_encryption,
                       provider, client_id, client_secret_enc
                FROM external_accounts
                WHERE referent_id = %s AND active = 1 LIMIT 1
                """,
                (ref['id'],)
            )
            account = cursor.fetchone()
            cursor.close()
            conn.close()
            if not account:
                logger.warning(
                    f"No active account for referent {ref['id']}, "
                    f"backlog will not be processed"
                )
                return
            for f in files:
                if not self._reserve_outgoing_file(f):
                    continue
                try:
                    from email.parser import BytesHeaderParser
                    with open(f, 'rb') as fh:
                        msg = BytesHeaderParser().parse(fh)
                    all_rcpts = email.utils.getaddresses([
                        msg.get('To', ''), msg.get('Cc', '')
                    ])
                    recipients = [addr for _, addr in all_rcpts if addr]
                    if recipients:
                        task = SmtpTask(ref, account, recipients, f)
                        self._smtp_task_queue.put_nowait(task)
                    else:
                        self._release_outgoing_file(f)
                except queue.Full:
                    self._release_outgoing_file(f)
                    logger.warning(
                        f"SMTP queue full during backlog scan, "
                        f"will retry later: {f.name}"
                    )
                except Exception as e:
                    self._release_outgoing_file(f)
                    logger.error(f"Error processing backlog file {f.name}: {e}")
        except Exception as e:
            logger.error(
                f"Error scanning backlog for referent {ref['id']}: {e}"
            )
    def _load_referents(self) -> List[Dict[str, Any]]:
        """Загружает список активных референтов из БД."""
        conn = None
        cursor = None
        try:
            conn = self._db.get_connection()
            cursor = conn.cursor(dictionary=True)
            cursor.execute(
                "SELECT id, username, local_inbox, local_outbox "
                "FROM referents WHERE active = 1"
            )
            return cursor.fetchall()
        except Exception as e:
            logger.critical(f"Failed to load referents: {e}", exc_info=True)
            return []
        finally:
            if cursor: cursor.close()
            if conn: conn.close()
    def _sync_database_state(self) -> None:
        """
        Периодическая синхронизация конфигурации демона с актуальным состоянием БД.
        Вызывается раз в 60 секунд из главного цикла start().

        Логика:
        - Новые активные референты: добавляем watchdog, сканируем backlog.
        - Деактивированные референты: останавливаем watchdog (задачи уже
          в очереди пулов — они будут обработаны, новые не поступят).
        - Пулы воркеров не пересоздаются — они обслуживают общие очереди задач
          и не зависят от количества референтов.
        """
        current_refs = self._load_referents()
        current_ids = {r['id'] for r in current_refs}
        current_refs_by_id = {r['id']: r for r in current_refs}

        with self._watched_lock:
            watched_ids_snapshot = set(self._watched_referent_ids)

        # --- Шаг 1: Обнаруживаем новые референты ---
        new_ids = current_ids - watched_ids_snapshot
        for ref_id in new_ids:
            ref = current_refs_by_id[ref_id]
            logger.info(
                f"Sync: new active referent detected (ID={ref_id}, "
                f"username={ref['username']}), adding to watchdog"
            )
            self._setup_watchdog_for_referent(ref)
            self._scan_existing_outgoing(ref)

        # --- Шаг 2: Обнаруживаем деактивированных референтов ---
        removed_ids = watched_ids_snapshot - current_ids
        for ref_id in removed_ids:
            logger.info(
                f"Sync: referent ID={ref_id} is no longer active, "
                f"removing from watchdog"
            )
            self._unschedule_watchdog_for_referent(ref_id)
            with self._watched_lock:
                self._watched_referent_ids.discard(ref_id)

        # --- Шаг 3: Периодический rescan backlog для всех отслеживаемых ---
        # Подбирает файлы, ранее не поставленные в очередь из-за отсутствия
        # активного аккаунта либо переполнения SMTP-очереди.
        for ref in current_refs:
            self._scan_existing_outgoing(ref)

        if new_ids or removed_ids:
            logger.info(
                f"Sync complete: +{len(new_ids)} added, "
                f"-{len(removed_ids)} removed, "
                f"{len(current_ids)} total active referents"
            )
    def _unschedule_watchdog_for_referent(self, referent_id: int) -> None:
        """
        Снимает watchdog-наблюдение для деактивированного референта.
        Новые файлы в его Maildir/new перестанут попадать в smtp_task_queue.
        Уже поставленные в очередь задачи будут обработаны в штатном режиме.
        """
        if not self._observer:
            return

        # Надёжная альтернатива: ведём собственный реестр путей
        # (добавляется в __init__ и _setup_watchdog_for_referent ниже)
        maildir_path_str = self._referent_path_registry.get(referent_id)
        if maildir_path_str and self._observer:
            try:
                # Находим watch object по пути и снимаем его
                for emitter in list(self._observer.emitters):
                    if str(emitter.watch.path) == maildir_path_str:
                        self._observer.unschedule(emitter.watch)
                        logger.info(
                            f"Watchdog unscheduled for referent {referent_id}: "
                            f"{maildir_path_str}"
                        )
                        break
            except Exception as e:
                logger.error(
                    f"Error unscheduling watchdog for referent {referent_id}: {e}"
                )

        # Удаляем из реестра путей
        self._referent_path_registry.pop(referent_id, None)
    def _handle_signal(self, signum, frame) -> None:
        """Обработчик POSIX-сигналов — инициирует graceful shutdown."""
        logger.info(
            f"Received signal {signum}, initiating graceful shutdown..."
        )
        self._stop_event.set()

    def _handle_log_reopen(self, signum, frame) -> None:
        """
        Обработчик SIGUSR1: переоткрывает все файловые обработчики логгера.
        Вызывается logrotate после ротации лог-файлов.
        Операция атомарна с точки зрения Python logging — записи не теряются.
        """
        logger.info("SIGUSR1 received: reopening log file handlers after logrotate")

        root_logger = logging.getLogger()

        for handler in root_logger.handlers[:]:
            # Переоткрываем только файловые обработчики
            if isinstance(handler, logging.FileHandler):
                try:
                    # Закрываем старый дескриптор (указывает на переименованный файл)
                    handler.close()
                    # Открываем новый дескриптор (указывает на свежесозданный файл)
                    handler.stream = open(handler.baseFilename, handler.mode)
                    logger.info(
                        f"Log file handler reopened: {handler.baseFilename}"
                    )
                except Exception as e:
                    # Логируем в stderr — основной файл может быть временно недоступен
                    import sys
                    print(
                        f"ERROR: failed to reopen log handler "
                        f"{handler.baseFilename}: {e}",
                        file=sys.stderr
                    )

    def _ensure_temp_dir(self) -> None:
        """Создаёт директорию временных файлов с правами 0700."""
        temp_path = Path(TEMP_DIR)
        temp_path.mkdir(mode=0o700, parents=True, exist_ok=True)
        os.chmod(TEMP_DIR, 0o700)

    def _shutdown(self) -> None:
        """
        Корректная остановка всех компонентов демона.
        Защищён от двойного вызова через _shutdown_lock.
        """
        with self._shutdown_lock:
            if self._is_shutdown:
                return
            self._is_shutdown = True
        logger.info("Graceful shutdown: stopping all components...")
        # Шаг 1: Останавливаем watchdog — прекращаем поступление новых задач
        if self._observer:
            try:
                self._observer.stop()
                if self._observer_started:
                    self._observer.join(timeout=10)
                logger.info("Watchdog observer stopped")
            except Exception as e:
                logger.error(f"Error stopping watchdog: {e}")
        # Шаг 2: Ожидаем завершения воркеров (флаг _stop_event уже установлен)
        self._imap_pool.join(timeout=30)
        self._smtp_pool.join(timeout=30)
        logger.info("ProxyDaemon shutdown complete")
# Главная точка входа в приложение
def main():
    logger.info("=" * 60)
    logger.info("DELTA-transit mail-proxy-daemon service starting")
    logger.info("=" * 60)
    daemon = None
    try:
        daemon = ProxyDaemon()
        daemon.start()
    except Exception as e:
        logger.critical(f"Fatal crash inside daemon runtime execution branch: {e}", exc_info=True)
        sys.exit(1)
    finally:
        # _shutdown() вызывается гарантированно — как при нормальном завершении
        # (SIGTERM/SIGINT → _stop_event → выход из while → finally),
        # так и при аварийном (исключение в start()).
        # Внутренний _shutdown_lock исключает двойной вызов.
        if daemon:
            daemon._shutdown()
if __name__ == '__main__':
    main()