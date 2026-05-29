#!/usr/bin/env python3
"""
generate_pdf.py  —  Called by export.php via exec()
Usage: python3 generate_pdf.py <input_json> <output_pdf>

Reads a JSON payload written by export.php and produces a formatted
attendance report PDF using ReportLab.
"""

import sys
import json
from datetime import datetime

from reportlab.lib.pagesizes import A4
from reportlab.lib import colors
from reportlab.lib.units import mm
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, HRFlowable
)

# ── Colour palette matching style.css ────────────────────────────────────────
C_BG        = colors.HexColor('#f5f0eb')
C_SURFACE   = colors.HexColor('#e8dfd3')
C_BORDER    = colors.HexColor('#c4b7a6')
C_ACCENT    = colors.HexColor('#c49a6c')
C_ACCENT2   = colors.HexColor('#a67873')
C_TEXT      = colors.HexColor('#3d3a35')
C_MUTED     = colors.HexColor('#7a7268')
C_SUCCESS   = colors.HexColor('#7da27d')
C_DANGER    = colors.HexColor('#c97b7b')
C_WARNING   = colors.HexColor('#d8a85c')
C_WHITE     = colors.white
C_HEADER_BG = colors.HexColor('#d9cfc2')


def status_color(status):
    return {
        'Present': C_SUCCESS,
        'Late':    C_WARNING,
        'Absent':  C_DANGER,
    }.get(status, C_MUTED)


def build_pdf(data, out_path):
    rows   = data.get('rows', [])
    title  = data.get('title', 'Attendance Report')
    today  = data.get('date',  datetime.now().strftime('%B %d, %Y'))
    filt   = data.get('filters', {})

    doc = SimpleDocTemplate(
        out_path,
        pagesize=A4,
        leftMargin=18*mm,
        rightMargin=18*mm,
        topMargin=18*mm,
        bottomMargin=18*mm,
    )

    styles = getSampleStyleSheet()
    W, H   = A4
    inner_w = W - 36*mm   # usable width

    # ── Custom paragraph styles ───────────────────────────────────────────────
    title_style = ParagraphStyle(
        'AT_Title',
        parent=styles['Normal'],
        fontSize=20,
        fontName='Helvetica-Bold',
        textColor=C_TEXT,
        spaceAfter=4,
        alignment=TA_LEFT,
    )
    sub_style = ParagraphStyle(
        'AT_Sub',
        parent=styles['Normal'],
        fontSize=10,
        fontName='Helvetica',
        textColor=C_MUTED,
        spaceAfter=2,
        alignment=TA_LEFT,
    )
    filter_style = ParagraphStyle(
        'AT_Filter',
        parent=styles['Normal'],
        fontSize=9,
        fontName='Helvetica-Oblique',
        textColor=C_MUTED,
        alignment=TA_LEFT,
    )
    cell_style = ParagraphStyle(
        'AT_Cell',
        parent=styles['Normal'],
        fontSize=9,
        fontName='Helvetica',
        textColor=C_TEXT,
    )
    status_style = ParagraphStyle(
        'AT_Status',
        parent=styles['Normal'],
        fontSize=9,
        fontName='Helvetica-Bold',
        alignment=TA_CENTER,
    )

    story = []

    # ── Header ───────────────────────────────────────────────────────────────
    story.append(Paragraph('📋 AttendTrack', sub_style))
    story.append(Paragraph(title, title_style))
    story.append(Paragraph(f'Generated: {today}', sub_style))

    # Active filters
    filter_parts = []
    if filt.get('filter_date'): filter_parts.append(f"Date: {filt['filter_date']}")
    if filt.get('date_from'):   filter_parts.append(f"From: {filt['date_from']}")
    if filt.get('date_to'):     filter_parts.append(f"To: {filt['date_to']}")
    if filter_parts:
        story.append(Paragraph('Filters: ' + '  |  '.join(filter_parts), filter_style))

    story.append(Spacer(1, 4*mm))
    story.append(HRFlowable(width='100%', thickness=1.5, color=C_ACCENT, spaceAfter=4*mm))

    # ── Summary row ──────────────────────────────────────────────────────────
    total   = len(rows)
    present = sum(1 for r in rows if r.get('status') == 'Present')
    late    = sum(1 for r in rows if r.get('status') == 'Late')
    absent  = sum(1 for r in rows if r.get('status') == 'Absent')

    summary_data = [
        [
            Paragraph(f'<b>{total}</b><br/><font size="8" color="#7a7268">Total Records</font>', cell_style),
            Paragraph(f'<b><font color="#7da27d">{present}</font></b><br/><font size="8" color="#7a7268">Present</font>', cell_style),
            Paragraph(f'<b><font color="#d8a85c">{late}</font></b><br/><font size="8" color="#7a7268">Late</font>', cell_style),
            Paragraph(f'<b><font color="#c97b7b">{absent}</font></b><br/><font size="8" color="#7a7268">Absent</font>', cell_style),
        ]
    ]
    col_w = inner_w / 4
    summary_table = Table(summary_data, colWidths=[col_w]*4, rowHeights=[22*mm])
    summary_table.setStyle(TableStyle([
        ('BACKGROUND',  (0,0), (-1,-1), C_SURFACE),
        ('BOX',         (0,0), (-1,-1), 0.5, C_BORDER),
        ('INNERGRID',   (0,0), (-1,-1), 0.5, C_BORDER),
        ('ALIGN',       (0,0), (-1,-1), 'CENTER'),
        ('VALIGN',      (0,0), (-1,-1), 'MIDDLE'),
        ('TOPPADDING',  (0,0), (-1,-1), 6),
        ('BOTTOMPADDING',(0,0),(-1,-1), 6),
        ('FONTSIZE',    (0,0), (-1,-1), 14),
    ]))
    story.append(summary_table)
    story.append(Spacer(1, 5*mm))

    # ── Attendance Table ──────────────────────────────────────────────────────
    col_widths = [
        inner_w * 0.26,   # Name
        inner_w * 0.16,   # School ID
        inner_w * 0.17,   # Section
        inner_w * 0.13,   # Status
        inner_w * 0.14,   # Date
        inner_w * 0.14,   # Time In
    ]
    headers = ['Name', 'School ID', 'Section', 'Status', 'Date', 'Time In']
    header_row = [
        Paragraph(f'<b>{h}</b>', ParagraphStyle(
            'TH', parent=styles['Normal'],
            fontSize=8, fontName='Helvetica-Bold',
            textColor=C_MUTED, alignment=TA_LEFT
        ))
        for h in headers
    ]
    table_data = [header_row]

    for r in rows:
        try:
            time_str = datetime.strptime(r['time_in'], '%H:%M:%S').strftime('%I:%M %p')
        except Exception:
            time_str = r.get('time_in', '—')
        try:
            date_str = datetime.strptime(r['date'], '%Y-%m-%d').strftime('%b %d, %Y')
        except Exception:
            date_str = r.get('date', '—')

        sc = status_color(r.get('status', ''))
        status_para = Paragraph(
            r.get('status', '—'),
            ParagraphStyle('SC', parent=styles['Normal'],
                           fontSize=8, fontName='Helvetica-Bold',
                           textColor=sc, alignment=TA_CENTER)
        )
        row = [
            Paragraph(r.get('name', ''),       cell_style),
            Paragraph(r.get('school_id', ''),   cell_style),
            Paragraph(r.get('section', ''),     cell_style),
            status_para,
            Paragraph(date_str,                 cell_style),
            Paragraph(time_str,                 cell_style),
        ]
        table_data.append(row)

    if not rows:
        table_data.append([
            Paragraph('No records found for the selected filters.', cell_style),
            '', '', '', '', ''
        ])

    att_table = Table(table_data, colWidths=col_widths, repeatRows=1)
    row_count  = len(table_data)

    ts = TableStyle([
        # Header
        ('BACKGROUND',   (0,0), (-1,0), C_HEADER_BG),
        ('ROWBACKGROUNDS',(0,1),(-1,-1), [C_WHITE, C_SURFACE]),
        ('BOX',          (0,0), (-1,-1), 0.5, C_BORDER),
        ('INNERGRID',    (0,0), (-1,-1), 0.3, C_BORDER),
        ('TOPPADDING',   (0,0), (-1,-1), 5),
        ('BOTTOMPADDING',(0,0), (-1,-1), 5),
        ('LEFTPADDING',  (0,0), (-1,-1), 6),
        ('RIGHTPADDING', (0,0), (-1,-1), 6),
        ('VALIGN',       (0,0), (-1,-1), 'MIDDLE'),
        ('FONTSIZE',     (0,0), (-1,-1), 9),
        # Status column: center
        ('ALIGN',        (3,0), (3,-1), 'CENTER'),
    ])

    # Highlight absent rows subtly
    for i, r in enumerate(rows, start=1):
        if r.get('status') == 'Absent':
            ts.add('BACKGROUND', (0,i), (-1,i), colors.HexColor('#f9ecec'))
        elif r.get('status') == 'Late':
            ts.add('BACKGROUND', (0,i), (-1,i), colors.HexColor('#fdf6e3'))

    att_table.setStyle(ts)
    story.append(att_table)
    story.append(Spacer(1, 6*mm))

    # ── Footer note ───────────────────────────────────────────────────────────
    story.append(HRFlowable(width='100%', thickness=0.5, color=C_BORDER))
    story.append(Spacer(1, 2*mm))
    story.append(Paragraph(
        f'AttendTrack — Exported on {today}  |  Total records: {total}',
        ParagraphStyle('Footer', parent=styles['Normal'],
                       fontSize=8, textColor=C_MUTED, alignment=TA_CENTER)
    ))

    doc.build(story)


# ── Entry point ───────────────────────────────────────────────────────────────
if __name__ == '__main__':
    if len(sys.argv) != 3:
        print("Usage: generate_pdf.py <input.json> <output.pdf>", file=sys.stderr)
        sys.exit(1)

    json_path, pdf_path = sys.argv[1], sys.argv[2]
    with open(json_path, 'r', encoding='utf-8') as f:
        payload = json.load(f)

    build_pdf(payload, pdf_path)
    print(f"PDF written to {pdf_path}")
