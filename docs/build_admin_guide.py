#!/usr/bin/env python3
"""Rebuild docs/DELTA_transit_admin_guide.pdf.

Linux (preferred): regenerate full guide from HTML via WeasyPrint.
Windows / no Pango: splice v3.3 PDF pages 1–6 + reportlab Section 4 (with §4.1) + v3.3 pages 8–9.
"""

from __future__ import annotations

import subprocess
import sys
from io import BytesIO
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC_DIR = ROOT / "docs" / "src"
SRC = SRC_DIR / "DELTA_transit_admin_guide.html"
OUT = ROOT / "docs" / "DELTA_transit_admin_guide.pdf"
BASELINE = SRC_DIR / "_baseline_v33.pdf"


def build_with_weasyprint() -> None:
    from weasyprint import HTML

    HTML(filename=str(SRC), base_url=str(SRC_DIR)).write_pdf(str(OUT))


def _ensure_baseline() -> None:
    if BASELINE.is_file():
        return
    print(f"Extracting baseline PDF to {BASELINE}", file=sys.stderr)
    data = subprocess.check_output(
        ["git", "show", "master:docs/DELTA_transit_admin_guide.pdf"],
        cwd=ROOT,
    )
    BASELINE.write_bytes(data)


def _font_path() -> Path:
    for candidate in (
        Path(r"C:\Windows\Fonts\ARIALUNI.TTF"),
        Path("/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf"),
        Path("/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf"),
    ):
        if candidate.is_file():
            return candidate
    raise SystemExit("Cyrillic font not found for PDF section build")


def _build_section4_pdf(font_name: str) -> bytes:
    from reportlab.lib import colors
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
    from reportlab.lib.units import cm
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.ttfonts import TTFont
    from reportlab.platypus import Paragraph, Preformatted, SimpleDocTemplate, Spacer, Table, TableStyle

    pdfmetrics.registerFont(TTFont(font_name, str(_font_path())))

    styles = getSampleStyleSheet()
    body = ParagraphStyle("BodyRu", parent=styles["Normal"], fontName=font_name, fontSize=11, leading=14)
    h2 = ParagraphStyle("H2Ru", parent=body, fontSize=14, spaceBefore=12, spaceAfter=6)
    h3 = ParagraphStyle("H3Ru", parent=body, fontSize=12, spaceBefore=10, spaceAfter=4)
    h4 = ParagraphStyle("H4Ru", parent=body, fontSize=11, spaceBefore=8, spaceAfter=3)
    mono = ParagraphStyle("MonoRu", parent=body, fontName=font_name, fontSize=9, leading=11)
    warn = ParagraphStyle("WarnRu", parent=body, textColor=colors.HexColor("#660000"))

    story = [
        Paragraph("3.2. Сигналы, понимаемые демоном", h3),
    ]
    sig_table = Table(
        [
            ["Сигнал", "Обработчик", "Эффект"],
            ["SIGTERM", "_handle_signal", "Инициирует graceful shutdown (_stop_event.set())"],
            ["SIGINT", "_handle_signal", "То же самое (Ctrl+C при запуске в foreground)"],
            ["SIGUSR1", "_handle_log_reopen", "Переоткрывает файловые хендлеры логгера после ротации"],
        ],
        colWidths=[2.5 * cm, 4.5 * cm, 8.5 * cm],
    )
    sig_table.setStyle(
        TableStyle(
            [
                ("FONTNAME", (0, 0), (-1, -1), font_name),
                ("FONTSIZE", (0, 0), (-1, -1), 9),
                ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#eeeeee")),
                ("GRID", (0, 0), (-1, -1), 0.5, colors.black),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ]
        )
    )
    story.extend([sig_table, Spacer(1, 0.4 * cm), Paragraph("4. Настройка лимитов вложений (150 МБ)", h2),
        Paragraph(
            "Целевой лимит вложения для конечного пользователя — <b>150 МБ</b>. "
            "Фактический лимит на уровне почтовых протоколов и веб-сервера установлен с запасом — "
            "≈200–210 МБ (с учётом Base64 overhead ~33%).",
            body,
        ),
        Paragraph(
            "Скрипт <font name='Courier'>configure_limits.sh</font> применяет согласованные значения "
            "и перезапускает затронутые службы. Перед изменением конфигурационных файлов создаёт "
            "резервные копии с суффиксом <font name='Courier'>.bak_YYYYMMDD_…</font>.",
            body,
        ),
        Preformatted("sudo ./configure_limits.sh", mono),
        Spacer(1, 0.2 * cm),
    ])

    table_data = [
        ["Компонент", "Параметр", "Значение", "Назначение"],
        ["Postfix", "message_size_limit", "209715200 (200 МБ)", "Максимальный размер письма"],
        ["Postfix", "mailbox_size_limit", "314572800 (300 МБ)", "Максимальный размер ящика"],
        ["MariaDB", "max_allowed_packet", "256M", "Макс. размер пакета для крупных данных"],
        ["Nginx", "client_max_body_size", "210M", "Лимит тела HTTP-запроса веб-панели"],
        ["PHP-FPM", "upload_max_filesize", "200M", "Лимит загружаемого файла"],
        ["PHP-FPM", "memory_limit", "512M", "Лимит памяти PHP-процесса"],
    ]
    tbl = Table(table_data, colWidths=[2.2 * cm, 4.2 * cm, 3.5 * cm, 6.5 * cm])
    tbl.setStyle(
        TableStyle(
            [
                ("FONTNAME", (0, 0), (-1, -1), font_name),
                ("FONTSIZE", (0, 0), (-1, -1), 9),
                ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#eeeeee")),
                ("GRID", (0, 0), (-1, -1), 0.5, colors.black),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ]
        )
    )
    story.extend(
        [
            tbl,
            Spacer(1, 0.3 * cm),
            Paragraph("4.1. Интерактивный шаг: квоты почтовых ящиков (MariaDB)", h3),
            Paragraph(
                "После настройки <font name='Courier'>max_allowed_packet</font> в конфигурации MariaDB скрипт "
                "<b>останавливается и запрашивает данные с клавиатуры</b>. Без ответов на запросы "
                "<font name='Courier'>configure_limits.sh</font> не продолжит настройку Nginx/PHP-FPM "
                "и перезапуск служб.",
                body,
            ),
            Paragraph("Запрос 1: пароль root MariaDB", h4),
            Preformatted("MySQL root password:", mono),
            Paragraph("• Ввод скрыт (символы не отображаются на экране).", body),
            Paragraph(
                "• Используется тот же пароль root, который задавался при первоначальной установке MariaDB / iRedMail.",
                body,
            ),
            Paragraph(
                "• Пароль нужен для выполнения SQL-запроса "
                "<font name='Courier'>UPDATE vmail.mailbox SET quota = …</font> от имени "
                "<font name='Courier'>mysql -u root</font>.",
                body,
            ),
            Paragraph("Запрос 2: выбор почтовых ящиков", h4),
            Preformatted("Email-адреса через запятую или --all-referents:", mono),
            Paragraph("Допустимы <b>два варианта</b>:", body),
            Paragraph(
                "1. <b>Список адресов через запятую</b> — например, "
                "<font name='Courier'>referent1@example.com, referent2@example.com</font>. "
                "Скрипт выполняет <font name='Courier'>UPDATE vmail.mailbox SET quota = 10240 WHERE username IN ('…');</font>",
                body,
            ),
            Paragraph(
                "2. <b>Флаг --all-referents</b> — скрипт задаёт дополнительный запрос "
                "<font name='Courier'>Домен:</font> и выполняет "
                "<font name='Courier'>UPDATE vmail.mailbox SET quota = 10240 WHERE username LIKE '%@&lt;домен&gt;';</font>, "
                "поднимая квоту всем ящикам указанного домена.",
                body,
            ),
            Paragraph("Значение квоты", h4),
            Paragraph(
                "Скрипт устанавливает <font name='Courier'>quota = 10240</font> в таблице "
                "<font name='Courier'>vmail.mailbox</font> (схема iRedMail). В этой колонке значение хранится в "
                "<b>мегабайтах (МБ)</b>; <font name='Courier'>10240</font> соответствует <b>10 ГБ</b> дисковой квоты на ящик. "
                "Это отдельный параметр от лимитов Postfix/Nginx/PHP в таблице выше.",
                body,
            ),
            Paragraph(
                "После выполнения запроса скрипт выводит строку вида "
                "<font name='Courier'>Updated mailbox rows: N</font>. Если <font name='Courier'>N = 0</font>, "
                "проверьте правильность адресов или домена.",
                body,
            ),
            Paragraph(
                "<b>Интерактивный режим — не для автоматизации.</b> У configure_limits.sh "
                "<b>нет флагов</b> для передачи пароля MariaDB или списка ящиков неинтерактивно. "
                "Перенаправление stdin и запуск из cron/CI <b>не поддерживаются</b> — оператор должен "
                "присутствовать у консоли и отвечать на запросы вручную.",
                warn,
            ),
        ]
    )

    buffer = BytesIO()
    doc = SimpleDocTemplate(
        buffer,
        pagesize=A4,
        leftMargin=2 * cm,
        rightMargin=2 * cm,
        topMargin=2 * cm,
        bottomMargin=2 * cm,
    )
    doc.build(story)
    return buffer.getvalue()


def build_with_splice() -> None:
    from pypdf import PdfReader, PdfWriter

    _ensure_baseline()
    section4_reader = PdfReader(BytesIO(_build_section4_pdf("AdminGuideSerif")))
    original = PdfReader(str(BASELINE))

    writer = PdfWriter()
    for idx in range(6):
        writer.add_page(original.pages[idx])
    for page in section4_reader.pages:
        writer.add_page(page)
    for idx in range(7, len(original.pages)):
        writer.add_page(original.pages[idx])

    with OUT.open("wb") as handle:
        writer.write(handle)


def main() -> None:
    try:
        build_with_weasyprint()
        print(f"Wrote {OUT} via WeasyPrint")
        return
    except Exception as exc:  # noqa: BLE001
        print(f"WeasyPrint unavailable ({exc}); using baseline splice + reportlab", file=sys.stderr)

    try:
        build_with_splice()
        print(f"Wrote {OUT} via splice (v3.3 pages + reportlab §4)")
    except ImportError as exc:
        raise SystemExit(
            "Install build deps: pip install weasyprint  OR  pip install pypdf reportlab"
        ) from exc


if __name__ == "__main__":
    main()
