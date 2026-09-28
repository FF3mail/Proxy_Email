#!/usr/bin/env python3
"""
Local referent notification for outbound disposal (PROMPT-79.2).

Template text is fixed by docs/decisions/PROMPT-79-decisions-log.md §4.
"""

from __future__ import annotations

import logging
import smtplib
from datetime import datetime
from email.message import EmailMessage
from typing import Optional

logger = logging.getLogger(__name__)

DEFAULT_LOCAL_SMTP_HOST = '127.0.0.1'
DEFAULT_LOCAL_SMTP_PORT = 25


def build_disposal_notification(
    *,
    referent_display: str,
    client_display: str,
    received_at: datetime,
    mail_from: str,
    mail_to: str,
) -> EmailMessage:
    """Build the exact §4 notification message (Russian template)."""
    date_str = received_at.strftime('%d.%m.%Y')
    time_str = received_at.strftime('%H:%M')
    subject = f'Ошибка доставки — {client_display}'
    body = (
        f'Уважаемый(ая) {referent_display},\n'
        f'\n'
        f'Ваше письмо для {client_display} от {date_str}, отправленное в {time_str},\n'
        f'не может быть доставлено в связи с нарушением формата.\n'
        f'\n'
        f'Пожалуйста, убедитесь, что:\n'
        f'— к письму приложен ровно один файл — защищённый паролем архив;\n'
        f'— тема письма точно совпадает с названием файла архива, включая\n'
        f'  расширение (например, Contract_2026.zip).\n'
        f'\n'
        f'После исправления, пожалуйста, отправьте письмо повторно.\n'
        f'\n'
        f'--\n'
        f'Автоматическое уведомление. На это письмо отвечать не нужно.\n'
    )
    msg = EmailMessage()
    msg['From'] = mail_from
    msg['To'] = mail_to
    msg['Subject'] = subject
    msg.set_content(body)
    return msg


def send_local_notification(
    message: EmailMessage,
    *,
    smtp_host: str = DEFAULT_LOCAL_SMTP_HOST,
    smtp_port: int = DEFAULT_LOCAL_SMTP_PORT,
    timeout: float = 30.0,
) -> bool:
    """Send via local SMTP. Returns True only on confirmed acceptance."""
    mail_from = message['From']
    mail_to = message['To']
    if not mail_from or not mail_to:
        logger.error('[REFERENT_NOTIFY] missing From/To')
        return False
    try:
        with smtplib.SMTP(smtp_host, smtp_port, timeout=timeout) as smtp:
            smtp.ehlo()
            refused = smtp.send_message(message)
            if refused:
                logger.error(
                    '[REFERENT_NOTIFY] partial refuse to=%s refused=%s',
                    mail_to,
                    refused,
                )
                return False
        return True
    except Exception as exc:
        logger.error('[REFERENT_NOTIFY] send failed to=%s err=%s', mail_to, exc)
        return False
