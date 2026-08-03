import { openPdfInWindow } from './browser-pdf';
import type {
    AttendanceEffectiveState,
    StudentAttendanceConstancy,
    StudentAttendanceHistoryRow,
} from '@/types/attendance';
import type { PDFFont, PDFPage, RGB } from 'pdf-lib';

type PdfRgb = typeof import('pdf-lib').rgb;

const A4 = {
    width: 595.28,
    height: 841.89,
    margin: 44,
    contentWidth: 595.28 - 88,
    firstTableTop: 588,
    continuationTableTop: 718,
    tableBottom: 86,
} as const;

const TABLE = {
    headerHeight: 25,
    fontSize: 8.5,
    lineHeight: 10,
    minimumRowHeight: 29,
    columns: [
        { label: 'Fecha', width: 88 },
        { label: 'Estado', width: 96 },
        { label: 'Ingreso', width: 78 },
        { label: 'Motivo', width: A4.contentWidth - 262 },
    ],
} as const;

interface PdfFonts {
    regular: PDFFont;
    bold: PDFFont;
}

interface Palette {
    accent: RGB;
    border: RGB;
    danger: RGB;
    info: RGB;
    muted: RGB;
    success: RGB;
    surface: RGB;
    text: RGB;
    warning: RGB;
    white: RGB;
}

function palette(rgb: PdfRgb): Palette {
    return {
        accent: rgb(0.16, 0.34, 0.82),
        border: rgb(0.84, 0.86, 0.9),
        danger: rgb(0.78, 0.16, 0.2),
        info: rgb(0.15, 0.45, 0.68),
        muted: rgb(0.4, 0.43, 0.49),
        success: rgb(0.12, 0.55, 0.3),
        surface: rgb(0.97, 0.975, 0.985),
        text: rgb(0.1, 0.12, 0.17),
        warning: rgb(0.78, 0.47, 0.06),
        white: rgb(1, 1, 1),
    };
}

function normalizeText(value: string | null | undefined, fallback = '-'): string {
    const normalized = value?.trim().replace(/\s+/g, ' ') ?? '';
    return normalized || fallback;
}

function fitText(text: string, font: PDFFont, size: number, maxWidth: number): string {
    if (font.widthOfTextAtSize(text, size) <= maxWidth) return text;

    const suffix = '...';
    let fitted = text;
    while (fitted.length > 0 && font.widthOfTextAtSize(`${fitted}${suffix}`, size) > maxWidth) {
        fitted = fitted.slice(0, -1);
    }

    return fitted ? `${fitted}${suffix}` : suffix;
}

function wrapText(text: string, font: PDFFont, size: number, maxWidth: number): string[] {
    const words = normalizeText(text).split(' ');
    const lines: string[] = [];
    let line = '';

    for (const word of words) {
        const candidate = line ? `${line} ${word}` : word;
        if (font.widthOfTextAtSize(candidate, size) <= maxWidth) {
            line = candidate;
            continue;
        }

        if (line) lines.push(line);
        line =
            font.widthOfTextAtSize(word, size) <= maxWidth
                ? word
                : fitText(word, font, size, maxWidth);
    }

    if (line) lines.push(line);
    return lines.length > 0 ? lines : ['-'];
}

function limitedLines(text: string, font: PDFFont, size: number, maxWidth: number): string[] {
    const lines = wrapText(text, font, size, maxWidth);
    if (lines.length <= 2) return lines;

    return [lines[0], fitText(`${lines[1]}...`, font, size, maxWidth)];
}

function formatDate(value: string, long = false): string {
    try {
        return new Intl.DateTimeFormat('es-PE', {
            timeZone: 'UTC',
            day: '2-digit',
            month: long ? 'long' : '2-digit',
            year: 'numeric',
        }).format(new Date(`${value}T00:00:00Z`));
    } catch {
        return value;
    }
}

function formatTime(value: string | null, timezone: string): string {
    if (!value) return '-';

    try {
        return new Intl.DateTimeFormat('es-PE', {
            timeZone: timezone,
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(new Date(value));
    } catch {
        return value;
    }
}

function stateColor(state: AttendanceEffectiveState, colors: Palette): RGB {
    if (state === 'present') return colors.success;
    if (state === 'late') return colors.warning;
    if (state === 'absent') return colors.danger;
    if (state === 'justified') return colors.info;
    return colors.muted;
}

function drawHeader(
    page: PDFPage,
    fonts: PdfFonts,
    colors: Palette,
    branchName: string,
    continuation = false,
): void {
    page.drawText('AEDUCA', {
        x: A4.margin,
        y: 805,
        font: fonts.bold,
        size: 10,
        color: colors.accent,
    });
    const branch = fitText(normalizeText(branchName), fonts.regular, 8, 220);
    page.drawText(branch, {
        x: A4.width - A4.margin - fonts.regular.widthOfTextAtSize(branch, 8),
        y: 805,
        font: fonts.regular,
        size: 8,
        color: colors.muted,
    });
    page.drawLine({
        start: { x: A4.margin, y: 794 },
        end: { x: A4.width - A4.margin, y: 794 },
        thickness: 0.8,
        color: colors.border,
    });

    const title = continuation
        ? 'CONSTANCIA DE ASISTENCIA · CONTINUACIÓN'
        : 'CONSTANCIA DE ASISTENCIA';
    const size = continuation ? 12 : 17;
    const width = fonts.bold.widthOfTextAtSize(title, size);
    page.drawText(title, {
        x: (A4.width - width) / 2,
        y: continuation ? 766 : 754,
        font: fonts.bold,
        size,
        color: colors.text,
    });

    page.drawLine({
        start: { x: A4.width / 2 - 62, y: continuation ? 757 : 742 },
        end: { x: A4.width / 2 + 62, y: continuation ? 757 : 742 },
        thickness: 1.2,
        color: colors.accent,
    });
}

function drawSummary(
    page: PDFPage,
    fonts: PdfFonts,
    colors: Palette,
    report: StudentAttendanceConstancy,
): void {
    const top = 716;
    const height = 104;
    const bottom = top - height;
    const halfWidth = (A4.contentWidth - 20) / 2;
    const items = [
        ['Alumno', `${report.student.full_name} · DNI ${report.student.dni}`],
        [
            'Matrícula',
            `Código ${report.enrollment.roll_code} · ${report.enrollment.is_active ? 'Activa' : 'Inactiva'}`,
        ],
        [
            'Contexto académico',
            `${report.enrollment.cycle_name} · ${report.enrollment.degree_label} · Sección ${report.enrollment.group_name}`,
        ],
        [
            'Consulta',
            `${formatDate(report.period.from, true)} al ${formatDate(report.period.to, true)} · ${report.shift.name}`,
        ],
    ] as const;

    page.drawRectangle({
        x: A4.margin,
        y: bottom,
        width: A4.contentWidth,
        height,
        color: colors.white,
        borderColor: colors.border,
        borderWidth: 0.8,
    });
    page.drawRectangle({
        x: A4.margin,
        y: top - 3,
        width: A4.contentWidth,
        height: 3,
        color: colors.accent,
    });

    items.forEach(([label, value], index) => {
        const column = index % 2;
        const row = Math.floor(index / 2);
        const x = A4.margin + 14 + column * (halfWidth + 20);
        const y = top - 24 - row * 43;

        page.drawText(label.toUpperCase(), {
            x,
            y,
            font: fonts.regular,
            size: 6.7,
            color: colors.muted,
        });

        const lines = limitedLines(value, fonts.bold, 9, halfWidth - 18);
        lines.forEach((line, lineIndex) => {
            page.drawText(line, {
                x,
                y: y - 13 - lineIndex * 10,
                font: fonts.bold,
                size: 9,
                color: colors.text,
            });
        });
    });
}

function columnPositions(): number[] {
    const positions: number[] = [A4.margin];
    for (const column of TABLE.columns.slice(0, -1)) {
        positions.push(positions.at(-1)! + column.width);
    }
    return positions;
}

function drawTableHeader(page: PDFPage, fonts: PdfFonts, colors: Palette, top: number): number {
    const bottom = top - TABLE.headerHeight;
    const positions = columnPositions();

    page.drawRectangle({
        x: A4.margin,
        y: bottom,
        width: A4.contentWidth,
        height: TABLE.headerHeight,
        color: colors.surface,
        borderColor: colors.border,
        borderWidth: 0.8,
    });
    page.drawLine({
        start: { x: A4.margin, y: top },
        end: { x: A4.width - A4.margin, y: top },
        thickness: 1.2,
        color: colors.accent,
    });

    TABLE.columns.forEach((column, index) => {
        const textWidth = fonts.bold.widthOfTextAtSize(column.label, TABLE.fontSize);
        page.drawText(column.label, {
            x: positions[index] + (column.width - textWidth) / 2,
            y: bottom + 8,
            font: fonts.bold,
            size: TABLE.fontSize,
            color: colors.text,
        });
        if (index > 0) {
            page.drawLine({
                start: { x: positions[index], y: bottom },
                end: { x: positions[index], y: top },
                thickness: 0.5,
                color: colors.border,
            });
        }
    });

    return bottom;
}

function rowCells(
    row: StudentAttendanceHistoryRow,
    fonts: PdfFonts,
    timezone: string,
): Array<{ lines: string[]; bold?: boolean }> {
    return [
        { lines: [formatDate(row.attendance_date)] },
        { lines: [row.state_label], bold: true },
        { lines: [formatTime(row.arrival_at, timezone)] },
        {
            lines: limitedLines(
                normalizeText(row.reason),
                fonts.regular,
                TABLE.fontSize,
                TABLE.columns[3].width - 16,
            ),
        },
    ];
}

function drawTableRow(
    page: PDFPage,
    fonts: PdfFonts,
    colors: Palette,
    row: StudentAttendanceHistoryRow,
    timezone: string,
    top: number,
    index: number,
): number {
    const cells = rowCells(row, fonts, timezone);
    const lineCount = Math.max(...cells.map((cell) => cell.lines.length));
    const height = Math.max(TABLE.minimumRowHeight, lineCount * TABLE.lineHeight + 12);
    const bottom = top - height;
    const positions = columnPositions();

    page.drawRectangle({
        x: A4.margin,
        y: bottom,
        width: A4.contentWidth,
        height,
        color: index % 2 === 0 ? colors.white : colors.surface,
        borderColor: colors.border,
        borderWidth: 0.6,
    });

    cells.forEach((cell, cellIndex) => {
        if (cellIndex > 0) {
            page.drawLine({
                start: { x: positions[cellIndex], y: bottom },
                end: { x: positions[cellIndex], y: top },
                thickness: 0.45,
                color: colors.border,
            });
        }

        cell.lines.forEach((line, lineIndex) => {
            page.drawText(line, {
                x: positions[cellIndex] + 8,
                y: top - 18 - lineIndex * TABLE.lineHeight,
                font: cell.bold ? fonts.bold : fonts.regular,
                size: TABLE.fontSize,
                color: cellIndex === 1 ? stateColor(row.effective_state, colors) : colors.text,
            });
        });
    });

    return bottom;
}

function drawEmptyState(page: PDFPage, fonts: PdfFonts, colors: Palette, top: number): void {
    page.drawRectangle({
        x: A4.margin,
        y: top - 58,
        width: A4.contentWidth,
        height: 58,
        color: colors.white,
        borderColor: colors.border,
        borderWidth: 0.8,
    });
    const message = 'No hay fechas esperadas en el periodo y turno seleccionados.';
    const width = fonts.bold.widthOfTextAtSize(message, 9.5);
    page.drawText(message, {
        x: (A4.width - width) / 2,
        y: top - 33,
        font: fonts.bold,
        size: 9.5,
        color: colors.muted,
    });
}

function drawFooters(
    pages: PDFPage[],
    fonts: PdfFonts,
    colors: Palette,
    report: StudentAttendanceConstancy,
): void {
    const generated = new Intl.DateTimeFormat('es-PE', {
        timeZone: report.business_timezone,
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(report.generated_at));

    pages.forEach((page, index) => {
        page.drawText(
            'Detalle operativo: las faltas derivadas aún no consideran feriados ni suspensiones.',
            {
                x: A4.margin,
                y: 60,
                font: fonts.regular,
                size: 7.2,
                color: colors.muted,
            },
        );
        page.drawLine({
            start: { x: A4.margin, y: 50 },
            end: { x: A4.width - A4.margin, y: 50 },
            thickness: 0.5,
            color: colors.border,
        });
        page.drawText(`Generado por Aeduca · ${generated}`, {
            x: A4.margin,
            y: 34,
            font: fonts.regular,
            size: 7,
            color: colors.muted,
        });
        const pageLabel = `Página ${index + 1} de ${pages.length}`;
        page.drawText(pageLabel, {
            x: A4.width - A4.margin - fonts.regular.widthOfTextAtSize(pageLabel, 7),
            y: 34,
            font: fonts.regular,
            size: 7,
            color: colors.muted,
        });
    });
}

export async function buildStudentAttendancePdf(
    report: StudentAttendanceConstancy,
): Promise<{ bytes: Uint8Array; filename: string }> {
    const { PDFDocument, StandardFonts, rgb } = await import('pdf-lib');
    const pdf = await PDFDocument.create();
    const [regular, bold] = await Promise.all([
        pdf.embedFont(StandardFonts.Helvetica),
        pdf.embedFont(StandardFonts.HelveticaBold),
    ]);
    const fonts = { regular, bold };
    const colors = palette(rgb);

    let page = pdf.addPage([A4.width, A4.height]);
    page.drawRectangle({ x: 0, y: 0, width: A4.width, height: A4.height, color: colors.white });
    drawHeader(page, fonts, colors, report.enrollment.branch_name);
    drawSummary(page, fonts, colors, report);
    let cursor = drawTableHeader(page, fonts, colors, A4.firstTableTop);

    if (report.rows.length === 0) {
        drawEmptyState(page, fonts, colors, cursor);
    } else {
        for (const [index, row] of report.rows.entries()) {
            const cells = rowCells(row, fonts, report.business_timezone);
            const requiredHeight = Math.max(
                TABLE.minimumRowHeight,
                Math.max(...cells.map((cell) => cell.lines.length)) * TABLE.lineHeight + 12,
            );

            if (cursor - requiredHeight < A4.tableBottom) {
                page = pdf.addPage([A4.width, A4.height]);
                page.drawRectangle({
                    x: 0,
                    y: 0,
                    width: A4.width,
                    height: A4.height,
                    color: colors.white,
                });
                drawHeader(page, fonts, colors, report.enrollment.branch_name, true);
                cursor = drawTableHeader(page, fonts, colors, A4.continuationTableTop);
            }

            cursor = drawTableRow(
                page,
                fonts,
                colors,
                row,
                report.business_timezone,
                cursor,
                index,
            );
        }
    }

    drawFooters(pdf.getPages(), fonts, colors, report);
    const filename = `constancia-asistencia-${report.student.dni}-${report.period.from}-${report.period.to}.pdf`;
    pdf.setTitle('Constancia de asistencia');
    pdf.setAuthor('Aeduca');
    pdf.setSubject(`${report.student.full_name} · ${report.shift.name}`);

    return { bytes: await pdf.save(), filename };
}

export async function generateStudentAttendancePdf(
    report: StudentAttendanceConstancy,
    printWindow: Window,
): Promise<void> {
    const { bytes, filename } = await buildStudentAttendancePdf(report);
    openPdfInWindow(bytes, filename, printWindow);
}
