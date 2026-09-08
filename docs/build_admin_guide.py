#!/usr/bin/env python3
"""Rebuild docs/DELTA_transit_admin_guide.pdf.

Linux (preferred): regenerate full guide from HTML via WeasyPrint.
Windows / no Pango: splice v3.3 PDF pages 2–6 + reportlab title + §3.2/§4 + v3.3 pages 8–9.
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

# Sampled from WeasyPrint baseline (_baseline_v33.pdf) to match splice pages.
HEADING_BLUE = "#2980B9"
ACCENT_GREEN = "#8DC63F"
CODE_BG = "#F4F4F4"
CODE_BORDER = "#DDDDDD"
CODE_LEFT_BAR = "#5D6D7E"
TABLE_HEAD_BG = "#EEEEEE"
WARN_TEXT = "#660000"
TITLE_BAND_BLUE = "#1F4E79"


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


def _first_existing(*candidates: Path) -> Path:
    for candidate in candidates:
        if candidate.is_file():
            return candidate
    raise SystemExit(f"No font file found among: {', '.join(str(c) for c in candidates)}")


def _serif_font_path() -> Path:
    local = SRC_DIR / "AdminGuideSerif.ttf"
    return _first_existing(
        local,
        Path(r"C:\Windows\Fonts\ARIALUNI.TTF"),
        Path("/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf"),
        Path("/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf"),
    )


def _mono_font_path() -> Path:
    return _first_existing(
        Path("/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf"),
        Path("/usr/share/fonts/truetype/liberation/LiberationMono-Regular.ttf"),
        Path(r"C:\Windows\Fonts\consola.ttf"),
        Path(r"C:\Windows\Fonts\cour.ttf"),
        _serif_font_path(),
    )


def _register_fonts() -> tuple[str, str]:
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.ttfonts import TTFont

    serif_name = "AdminGuideSerif"
    mono_name = "AdminGuideMono"
    pdfmetrics.registerFont(TTFont(serif_name, str(_serif_font_path())))
    pdfmetrics.registerFont(TTFont(mono_name, str(_mono_font_path())))
    return serif_name, mono_name


def _heading_bar_table(
    text: str,
    style,
    bar_color: str,
    bar_width: float,
    *,
    bottom_rule: bool = False,
) -> object:
    from reportlab.lib import colors
    from reportlab.lib.units import cm
    from reportlab.platypus import Paragraph, Table, TableStyle

    content = Table(
        [[Paragraph(text, style)]],
        colWidths=[16.5 * cm],
    )
    content.setStyle(
        TableStyle(
            [
                ("LEFTPADDING", (0, 0), (-1, -1), 6),
                ("RIGHTPADDING", (0, 0), (-1, -1), 0),
                ("TOPPADDING", (0, 0), (-1, -1), 0),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 2),
                ("LINEBEFORE", (0, 0), (0, -1), bar_width, colors.HexColor(bar_color)),
            ]
        )
    )
    if not bottom_rule:
        return content

    ruled = Table(
        [[content]],
        colWidths=[16.5 * cm],
    )
    ruled.setStyle(
        TableStyle(
            [
                ("LINEBELOW", (0, 0), (-1, -1), 2, colors.HexColor(HEADING_BLUE)),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
            ]
        )
    )
    return ruled


def _code_block(text: str, mono_style, mono_name: str) -> object:
    from reportlab.lib import colors
    from reportlab.lib.units import cm
    from reportlab.platypus import Preformatted, Table, TableStyle

    block = Table(
        [[Preformatted(text, mono_style)]],
        colWidths=[15.9 * cm],
    )
    block.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, -1), colors.HexColor(CODE_BG)),
                ("BOX", (0, 0), (-1, -1), 0.5, colors.HexColor(CODE_BORDER)),
                ("LEFTPADDING", (0, 0), (-1, -1), 8),
                ("RIGHTPADDING", (0, 0), (-1, -1), 8),
                ("TOPPADDING", (0, 0), (-1, -1), 6),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
                ("FONTNAME", (0, 0), (-1, -1), mono_name),
            ]
        )
    )
    outer = Table(
        [[block]],
        colWidths=[16.5 * cm],
    )
    outer.setStyle(
        TableStyle(
            [
                ("LINEBEFORE", (0, 0), (0, -1), 4, colors.HexColor(CODE_LEFT_BAR)),
                ("LEFTPADDING", (0, 0), (-1, -1), 0),
                ("RIGHTPADDING", (0, 0), (-1, -1), 0),
            ]
        )
    )
    return outer


def _build_title_page_pdf(serif_name: str) -> bytes:
    from reportlab.lib import colors
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.styles import ParagraphStyle
    from reportlab.lib.units import cm
    from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

    title = ParagraphStyle(
        "TitleMain",
        fontName=serif_name,
        fontSize=22,
        leading=26,
        textColor=colors.HexColor(HEADING_BLUE),
        alignment=1,
        spaceAfter=4,
    )
    subtitle = ParagraphStyle(
        "TitleSub",
        fontName=serif_name,
        fontSize=14,
        leading=18,
        textColor=colors.HexColor(ACCENT_GREEN),
        alignment=1,
        spaceAfter=10,
    )
    meta = ParagraphStyle(
        "TitleMeta",
        fontName=serif_name,
        fontSize=11,
        leading=14,
        alignment=0,
    )

    band = Table([[""]], colWidths=[16.5 * cm], rowHeights=[2.2 * cm])
    band.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, -1), colors.HexColor(TITLE_BAND_BLUE)),
            ]
        )
    )

    story = [
        band,
        Spacer(1, 1.4 * cm),
        Paragraph("DELTA-транзит", title),
        Paragraph("Руководство системного администратора", subtitle),
        Spacer(1, 0.6 * cm),
        Paragraph("Версия дистрибутива: <b>v3.4</b>", meta),
        Paragraph("Установщик: <font name='AdminGuideMono'>delta-transit-install.sh</font> v3.1.0", meta),
        Paragraph(
            "Применённые патчи: PROMPT 01–09, PROMPT V2.0, PROMPT 10–13, PROMPT 35, PROMPT 36",
            meta,
        ),
        Paragraph("Статус PROMPT 14: Не применён (см. Раздел 9)", meta),
    ]

    buffer = BytesIO()
    doc = SimpleDocTemplate(
        buffer,
        pagesize=A4,
        leftMargin=2 * cm,
        rightMargin=2 * cm,
        topMargin=1.5 * cm,
        bottomMargin=2 * cm,
    )
    doc.build(story)
    return buffer.getvalue()


def _build_section4_pdf(serif_name: str, mono_name: str) -> bytes:
    from reportlab.lib import colors
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
    from reportlab.lib.units import cm
    from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

    styles = getSampleStyleSheet()
    body = ParagraphStyle(
        "BodyRu",
        parent=styles["Normal"],
        fontName=serif_name,
        fontSize=11,
        leading=14,
    )
    h2 = ParagraphStyle(
        "H2Ru",
        parent=body,
        fontSize=14,
        leading=17,
        textColor=colors.HexColor(HEADING_BLUE),
        spaceBefore=4,
        spaceAfter=2,
    )
    h3 = ParagraphStyle(
        "H3Ru",
        parent=body,
        fontSize=12,
        leading=15,
        spaceBefore=6,
        spaceAfter=3,
    )
    h4 = ParagraphStyle(
        "H4Ru",
        parent=body,
        fontSize=11,
        leading=14,
        spaceBefore=4,
        spaceAfter=2,
    )
    mono = ParagraphStyle(
        "MonoRu",
        parent=body,
        fontName=mono_name,
        fontSize=9,
        leading=11,
    )
    warn = ParagraphStyle(
        "WarnRu",
        parent=body,
        textColor=colors.HexColor(WARN_TEXT),
        borderPadding=6,
        leftIndent=6,
    )

    story = [
        _heading_bar_table("3.2. Сигналы, понимаемые демоном", h3, ACCENT_GREEN, 4),
        Spacer(1, 0.15 * cm),
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
                ("FONTNAME", (0, 0), (-1, -1), serif_name),
                ("FONTSIZE", (0, 0), (-1, -1), 9),
                ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor(TABLE_HEAD_BG)),
                ("GRID", (0, 0), (-1, -1), 0.5, colors.black),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ]
        )
    )
    story.extend(
        [
            sig_table,
            Spacer(1, 0.35 * cm),
            _heading_bar_table("4. Настройка лимитов вложений (150 МБ)", h2, HEADING_BLUE, 0, bottom_rule=True),
            Spacer(1, 0.1 * cm),
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
            _code_block("sudo ./configure_limits.sh", mono, mono_name),
            Spacer(1, 0.2 * cm),
        ]
    )

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
                ("FONTNAME", (0, 0), (-1, -1), serif_name),
                ("FONTSIZE", (0, 0), (-1, -1), 9),
                ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor(TABLE_HEAD_BG)),
                ("GRID", (0, 0), (-1, -1), 0.5, colors.black),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ]
        )
    )
    story.extend(
        [
            tbl,
            Spacer(1, 0.3 * cm),
            _heading_bar_table("4.1. Интерактивный шаг: квоты почтовых ящиков (MariaDB)", h3, ACCENT_GREEN, 4),
            Spacer(1, 0.1 * cm),
            Paragraph(
                "После настройки <font name='Courier'>max_allowed_packet</font> в конфигурации MariaDB скрипт "
                "<b>останавливается и запрашивает данные с клавиатуры</b>. Без ответов на запросы "
                "<font name='Courier'>configure_limits.sh</font> не продолжит настройку Nginx/PHP-FPM "
                "и перезапуск служб.",
                body,
            ),
            _heading_bar_table("Запрос 1: пароль root MariaDB", h4, ACCENT_GREEN, 3),
            Spacer(1, 0.05 * cm),
            _code_block("MySQL root password:", mono, mono_name),
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
            _heading_bar_table("Запрос 2: выбор почтовых ящиков", h4, ACCENT_GREEN, 3),
            Spacer(1, 0.05 * cm),
            _code_block("Email-адреса через запятую или --all-referents:", mono, mono_name),
            Paragraph("Допустимы <b>два варианта</b>:", body),
            Paragraph(
                "1. <b>Список адресов через запятую</b> — например, "
                "<font name='Courier'>referent1@example.com, referent2@example.com</font>. "
                "Скрипт выполняет <font name='Courier'>UPDATE vmail.mailbox SET quota = 10240 WHERE username IN ('…');</font>",
                body,
            ),
            Paragraph(
                "2. <b>Флаг --all-referents</b> — скрипт задаёт дополнительный запрос "
                "<b>Домен:</b> и выполняет "
                "<font name='Courier'>UPDATE vmail.mailbox SET quota = 10240 WHERE username LIKE '%@</font>"
                "&lt;домен&gt;"
                "<font name='Courier'>;</font>, "
                "поднимая квоту всем ящикам указанного домена.",
                body,
            ),
            _heading_bar_table("Значение квоты", h4, ACCENT_GREEN, 3),
            Spacer(1, 0.05 * cm),
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
            Spacer(1, 0.15 * cm),
            Table(
                [[Paragraph(
                    "<b>Интерактивный режим — не для автоматизации.</b> У configure_limits.sh "
                    "<b>нет флагов</b> для передачи пароля MariaDB или списка ящиков неинтерактивно. "
                    "Перенаправление stdin и запуск из cron/CI <b>не поддерживаются</b> — оператор должен "
                    "присутствовать у консоли и отвечать на запросы вручную.",
                    warn,
                )]],
                colWidths=[16.5 * cm],
                style=TableStyle(
                    [
                        ("LINEBEFORE", (0, 0), (0, -1), 3, colors.HexColor("#AA0000")),
                        ("LEFTPADDING", (0, 0), (-1, -1), 8),
                        ("RIGHTPADDING", (0, 0), (-1, -1), 4),
                        ("TOPPADDING", (0, 0), (-1, -1), 4),
                        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
                    ]
                ),
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
    serif_name, mono_name = _register_fonts()
    title_reader = PdfReader(BytesIO(_build_title_page_pdf(serif_name)))
    section4_reader = PdfReader(BytesIO(_build_section4_pdf(serif_name, mono_name)))
    original = PdfReader(str(BASELINE))

    writer = PdfWriter()
    writer.add_page(title_reader.pages[0])
    for idx in range(1, 6):
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
        print(f"Wrote {OUT} via splice (reportlab title + v3.3 pages 2–6 + reportlab §4 + v3.3 tail)")
    except ImportError as exc:
        raise SystemExit(
            "Install build deps: pip install weasyprint  OR  pip install pypdf reportlab"
        ) from exc


if __name__ == "__main__":
    main()
