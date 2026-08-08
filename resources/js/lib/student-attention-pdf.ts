import { openPdfInWindow } from './browser-pdf';
import { fitPdfText, normalizePdfText, wrapPdfText } from './pdf-text';
import type { StudentAttentionCertificate } from '@/types/student-attention';
import type { PDFFont, PDFPage, RGB } from 'pdf-lib';

type PdfRgb = typeof import('pdf-lib').rgb;

const PAGE = {
    width: 595.28,
    height: 841.89,
    margin: 48,
    contentWidth: 499.28,
    contentTop: 742,
    contentBottom: 88,
} as const;

interface Fonts {
    regular: PDFFont;
    bold: PDFFont;
}

interface Colors {
    accent: RGB;
    border: RGB;
    muted: RGB;
    surface: RGB;
    text: RGB;
    white: RGB;
}

function colors(rgb: PdfRgb): Colors {
    return {
        accent: rgb(0.16, 0.34, 0.82),
        border: rgb(0.84, 0.86, 0.9),
        muted: rgb(0.4, 0.43, 0.49),
        surface: rgb(0.97, 0.975, 0.985),
        text: rgb(0.1, 0.12, 0.17),
        white: rgb(1, 1, 1),
    };
}

function formatDateTime(value: string, timezone: string): string {
    return new Intl.DateTimeFormat('es-PE', {
        timeZone: timezone,
        dateStyle: 'long',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatDateKey(value: string, timezone: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date(value));
    const date = Object.fromEntries(parts.map((part) => [part.type, part.value]));

    return `${date.year}-${date.month}-${date.day}`;
}

function paragraphLines(text: string, font: PDFFont, size: number, width: number): string[] {
    return text
        .trim()
        .split(/\n+/)
        .flatMap((paragraph, index) => [
            ...(index > 0 ? [''] : []),
            ...wrapPdfText(paragraph, font, size, width),
        ]);
}

function drawHeader(
    page: PDFPage,
    fonts: Fonts,
    palette: Colors,
    branchName: string,
    continuation: boolean,
): void {
    page.drawText('AEDUCA', {
        x: PAGE.margin,
        y: 805,
        font: fonts.bold,
        size: 10,
        color: palette.accent,
    });
    const branch = fitPdfText(normalizePdfText(branchName), fonts.regular, 8, 220);
    page.drawText(branch, {
        x: PAGE.width - PAGE.margin - fonts.regular.widthOfTextAtSize(branch, 8),
        y: 805,
        font: fonts.regular,
        size: 8,
        color: palette.muted,
    });
    page.drawLine({
        start: { x: PAGE.margin, y: 794 },
        end: { x: PAGE.width - PAGE.margin, y: 794 },
        thickness: 0.8,
        color: palette.border,
    });

    const title = continuation ? 'CONSTANCIA DE ATENCIÓN · CONTINUACIÓN' : 'CONSTANCIA DE ATENCIÓN';
    const size = continuation ? 12 : 17;
    page.drawText(title, {
        x: (PAGE.width - fonts.bold.widthOfTextAtSize(title, size)) / 2,
        y: 758,
        font: fonts.bold,
        size,
        color: palette.text,
    });
    page.drawLine({
        start: { x: PAGE.width / 2 - 60, y: 746 },
        end: { x: PAGE.width / 2 + 60, y: 746 },
        thickness: 1.2,
        color: palette.accent,
    });
}

function drawSummary(
    page: PDFPage,
    fonts: Fonts,
    palette: Colors,
    certificate: StudentAttentionCertificate,
): number {
    const top = PAGE.contentTop;
    const height = 116;
    const bottom = top - height;
    const half = (PAGE.contentWidth - 20) / 2;
    const items = [
        ['Alumno', certificate.student.full_name],
        ['DNI', certificate.student.dni],
        ['Tipo', certificate.attention.type_label],
        [
            'Fecha de atención',
            formatDateTime(certificate.attention.occurred_at, certificate.business_timezone),
        ],
        ['Registrado por', certificate.author.full_name],
        ['Cargo', certificate.author.role_name ?? 'Personal'],
    ] as const;

    page.drawRectangle({
        x: PAGE.margin,
        y: bottom,
        width: PAGE.contentWidth,
        height,
        color: palette.surface,
        borderColor: palette.border,
        borderWidth: 0.8,
    });
    page.drawRectangle({
        x: PAGE.margin,
        y: top - 3,
        width: PAGE.contentWidth,
        height: 3,
        color: palette.accent,
    });

    items.forEach(([label, value], index) => {
        const column = index % 2;
        const row = Math.floor(index / 2);
        const x = PAGE.margin + 14 + column * (half + 20);
        const y = top - 22 - row * 34;
        page.drawText(label.toUpperCase(), {
            x,
            y,
            font: fonts.regular,
            size: 6.8,
            color: palette.muted,
        });
        page.drawText(fitPdfText(normalizePdfText(value), fonts.bold, 9, half - 18), {
            x,
            y: y - 13,
            font: fonts.bold,
            size: 9,
            color: palette.text,
        });
    });

    return bottom - 24;
}

function drawFooters(
    pages: PDFPage[],
    fonts: Fonts,
    palette: Colors,
    certificate: StudentAttentionCertificate,
): void {
    const generated = formatDateTime(certificate.generated_at, certificate.business_timezone);

    pages.forEach((page, index) => {
        page.drawLine({
            start: { x: PAGE.margin, y: 54 },
            end: { x: PAGE.width - PAGE.margin, y: 54 },
            thickness: 0.5,
            color: palette.border,
        });
        page.drawText(`Generado por Aeduca · ${generated}`, {
            x: PAGE.margin,
            y: 37,
            font: fonts.regular,
            size: 7,
            color: palette.muted,
        });
        const pageLabel = `Página ${index + 1} de ${pages.length}`;
        page.drawText(pageLabel, {
            x: PAGE.width - PAGE.margin - fonts.regular.widthOfTextAtSize(pageLabel, 7),
            y: 37,
            font: fonts.regular,
            size: 7,
            color: palette.muted,
        });
    });
}

export async function buildStudentAttentionCertificatePdf(
    certificate: StudentAttentionCertificate,
): Promise<{ bytes: Uint8Array; filename: string }> {
    const { PDFDocument, StandardFonts, rgb } = await import('pdf-lib');
    const pdf = await PDFDocument.create();
    const [regular, bold] = await Promise.all([
        pdf.embedFont(StandardFonts.Helvetica),
        pdf.embedFont(StandardFonts.HelveticaBold),
    ]);
    const fonts = { regular, bold };
    const palette = colors(rgb);
    const createPage = (continuation: boolean): PDFPage => {
        const nextPage = pdf.addPage([PAGE.width, PAGE.height]);
        nextPage.drawRectangle({
            x: 0,
            y: 0,
            width: PAGE.width,
            height: PAGE.height,
            color: palette.white,
        });
        drawHeader(nextPage, fonts, palette, certificate.branch.name, continuation);

        return nextPage;
    };
    let page = createPage(false);
    let cursor = drawSummary(page, fonts, palette, certificate);
    const ensureSpace = (height: number): void => {
        if (cursor - height >= PAGE.contentBottom) return;
        page = createPage(true);
        cursor = PAGE.contentTop;
    };
    const drawSection = (title: string, text: string): void => {
        ensureSpace(44);
        page.drawText(title.toUpperCase(), {
            x: PAGE.margin,
            y: cursor,
            font: fonts.bold,
            size: 9,
            color: palette.accent,
        });
        cursor -= 12;
        page.drawLine({
            start: { x: PAGE.margin, y: cursor },
            end: { x: PAGE.width - PAGE.margin, y: cursor },
            thickness: 0.6,
            color: palette.border,
        });
        cursor -= 18;

        for (const line of paragraphLines(text, fonts.regular, 10, PAGE.contentWidth)) {
            ensureSpace(16);
            if (line) {
                page.drawText(line, {
                    x: PAGE.margin,
                    y: cursor,
                    font: fonts.regular,
                    size: 10,
                    color: palette.text,
                });
            }
            cursor -= 14;
        }
        cursor -= 16;
    };

    drawSection('Motivo', certificate.attention.reason);
    drawSection('Desarrollo', certificate.attention.development);
    drawSection('Conclusión y acuerdos', certificate.attention.conclusion);

    if (certificate.attention.has_attachment) {
        ensureSpace(28);
        page.drawText('Esta atención registra un archivo adjunto.', {
            x: PAGE.margin,
            y: cursor,
            font: fonts.regular,
            size: 8,
            color: palette.muted,
        });
        cursor -= 28;
    }

    ensureSpace(94);
    const columnWidth = (PAGE.contentWidth - 40) / 2;
    const rightX = PAGE.margin + columnWidth + 40;
    page.drawLine({
        start: { x: PAGE.margin, y: cursor - 38 },
        end: { x: PAGE.margin + columnWidth, y: cursor - 38 },
        thickness: 0.7,
        color: palette.text,
    });
    page.drawLine({
        start: { x: rightX, y: cursor - 38 },
        end: { x: rightX + columnWidth, y: cursor - 38 },
        thickness: 0.7,
        color: palette.text,
    });
    const author = fitPdfText(certificate.author.full_name, fonts.bold, 8.5, columnWidth);
    page.drawText(author, {
        x: PAGE.margin + (columnWidth - fonts.bold.widthOfTextAtSize(author, 8.5)) / 2,
        y: cursor - 53,
        font: fonts.bold,
        size: 8.5,
        color: palette.text,
    });
    const role = fitPdfText(
        certificate.author.role_name ?? 'Personal',
        fonts.regular,
        7.5,
        columnWidth,
    );
    page.drawText(role, {
        x: PAGE.margin + (columnWidth - fonts.regular.widthOfTextAtSize(role, 7.5)) / 2,
        y: cursor - 66,
        font: fonts.regular,
        size: 7.5,
        color: palette.muted,
    });
    const guardian = 'Firma del apoderado';
    page.drawText(guardian, {
        x: rightX + (columnWidth - fonts.regular.widthOfTextAtSize(guardian, 8.5)) / 2,
        y: cursor - 53,
        font: fonts.regular,
        size: 8.5,
        color: palette.text,
    });

    drawFooters(pdf.getPages(), fonts, palette, certificate);
    const date = formatDateKey(certificate.attention.occurred_at, certificate.business_timezone);
    const filename = `constancia-atencion-${certificate.student.dni}-${date}.pdf`;
    pdf.setTitle('Constancia de atención');
    pdf.setAuthor('Aeduca');
    pdf.setSubject(`${certificate.student.full_name} · ${certificate.attention.reason}`);

    return { bytes: await pdf.save(), filename };
}

export async function generateStudentAttentionCertificatePdf(
    certificate: StudentAttentionCertificate,
    printWindow: Window,
): Promise<void> {
    const { bytes, filename } = await buildStudentAttentionCertificatePdf(certificate);
    openPdfInWindow(bytes, filename, printWindow);
}
