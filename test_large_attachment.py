#!/usr/bin/env python3

import io
import os
import time
import smtplib
import hashlib

from email import encoders
from email.mime.base import MIMEBase
from email.mime.multipart import MIMEMultipart
from smtplib import SMTPException
from socket import timeout as SocketTimeout

SMTP_HOST = '127.0.0.1'
SMTP_PORT = 25

SENDER = 'test-sender@<домен>'
RECIPIENT = 'r1-in@<домен>'

FILE_SIZE_MB = 100
ATTACHMENT_NAME = f'heavy_payload_{FILE_SIZE_MB}mb.bin'
TMP_DIR = '/var/spool/mail-proxy/tmp'


def format_seconds(value: float) -> str:
    return f"{value:.2f} сек"


def print_table(
    generation_time: float,
    encoding_time: float,
    smtp_time: float,
    total_time: float,
    status: str
) -> None:

    print()
    print("┌─────────────────────────┬──────────────┐")
    print("│ Этап                    │ Время        │")
    print("├─────────────────────────┼──────────────┤")
    print(
        f"│ Генерация файла ({FILE_SIZE_MB}MB)"
        f"{' ' * max(0, 2 - len(str(FILE_SIZE_MB)))} │ "
        f"{format_seconds(generation_time):<12}│"
    )
    print(
        f"│ Base64 кодирование      │ "
        f"{format_seconds(encoding_time):<12}│"
    )
    print(
        f"│ SMTP передача           │ "
        f"{format_seconds(smtp_time):<12}│"
    )
    print(
        f"│ ИТОГО                   │ "
        f"{format_seconds(total_time):<12}│"
    )
    print(
        f"│ Статус доставки         │ "
        f"{status:<12}│"
    )
    print("└─────────────────────────┴──────────────┘")
    print()


def main() -> None:

    overall_start = time.perf_counter()

    generation_time = 0.0
    encoding_time = 0.0
    smtp_time = 0.0

    delivery_status = "FAILED"

    temp_file = os.path.join(TMP_DIR, ATTACHMENT_NAME)

    try:

        print("=" * 70)
        print("STEP 1: FILE GENERATION")
        print("=" * 70)

        start = time.perf_counter()

        with open(temp_file, "wb") as f:
            for _ in range(FILE_SIZE_MB):
                f.write(os.urandom(1024 * 1024))

        generation_time = time.perf_counter() - start

        generated_size = os.path.getsize(temp_file)

        print(
            f"Generated file: {temp_file}"
        )
        print(
            f"Size: {generated_size / (1024 * 1024):.2f} MB"
        )
        print(
            f"Generation time: {generation_time:.2f} sec"
        )

        print()
        print("=" * 70)
        print("STEP 2: MIME BUILD")
        print("=" * 70)

        start = time.perf_counter()

        msg = MIMEMultipart()
        msg["From"] = SENDER
        msg["To"] = RECIPIENT
        msg["Subject"] = ATTACHMENT_NAME

        buffer = io.BytesIO()

        with open(temp_file, "rb") as fh:
            while True:
                chunk = fh.read(65536)

                if not chunk:
                    break

                buffer.write(chunk)

        payload_data = buffer.getvalue()

        part = MIMEBase("application", "octet-stream")
        part.set_payload(payload_data)

        encoders.encode_base64(part)

        part.add_header(
            "Content-Disposition",
            f'attachment; filename="{ATTACHMENT_NAME}"'
        )

        msg.attach(part)

        encoding_time = time.perf_counter() - start
        
        mime_bytes = msg.as_bytes()
        mime_size_mb = len(mime_bytes) / (1024 * 1024)

        # Контрольная сумма вложения
        with open(temp_file, "rb") as f:
            file_hash = hashlib.md5(f.read()).hexdigest()

        print(f"Encoding time: {encoding_time:.2f} sec")
        print(f"MIME message size: {mime_size_mb:.2f} MB")
        print(f"MD5 hash of attachment: {file_hash}")

        print()
        print("=" * 70)
        print("STEP 3: SMTP DELIVERY")
        print("=" * 70)

        start = time.perf_counter()

        with smtplib.SMTP(
            SMTP_HOST,
            SMTP_PORT,
            timeout=300
        ) as smtp:

            smtp.set_debuglevel(1)

            smtp.sendmail(
                SENDER,
                RECIPIENT,
                msg.as_bytes()
            )

        smtp_time = time.perf_counter() - start

        delivery_status = "OK"

        print()
        print(
            f"DELIVERY OK ({smtp_time:.2f} sec)"
        )

    except SMTPException as exc:

        smtp_time = time.perf_counter() - start

        delivery_status = "FAILED"

        print()
        print("SMTP ERROR")
        print(str(exc))

    except SocketTimeout:

        smtp_time = time.perf_counter() - start

        delivery_status = "FAILED"

        print()
        print("SMTP TIMEOUT")
        print(
            f"Connection or transfer exceeded "
            f"{300} seconds timeout"
        )

    except Exception as exc:

        delivery_status = "FAILED"

        print()
        print("GENERAL ERROR")
        print(str(exc))

    finally:

        if os.path.exists(temp_file):
            try:
                os.remove(temp_file)
                print()
                print(
                    f"Temporary file removed: {temp_file}"
                )
            except Exception as exc:
                print()
                print(
                    f"Failed to remove temp file: {exc}"
                )

    total_time = time.perf_counter() - overall_start

    print_table(
        generation_time=generation_time,
        encoding_time=encoding_time,
        smtp_time=smtp_time,
        total_time=total_time,
        status=delivery_status
    )


if __name__ == "__main__":
    main()
    