import cardTemplateUrl from '@/assets/student-card-template.png';
import {
    CARD_HEIGHT,
    CARD_WIDTH,
    CR80_COLORS,
    CR80_LAYOUT,
    drawCenteredCardText,
    drawCr80Dni,
    drawCr80PhotoFrame,
    fetchCardBytes,
    fitCardText,
    fromTop,
    normalizeCardText,
    openCr80Window,
    openPdfInWindow,
    photoUrlAsJpegBytes,
    pxX,
    pxY,
} from './cr80-card-core';
import type { PDFFont, PDFPage } from 'pdf-lib';

type PdfRgb = typeof import('pdf-lib').rgb;
export type EmployeeCardInput = {
    dni: string;
    first_name: string;
    last_name: string;
    role_name: string | null;
    photo_url: string;
};

function drawIdentity(
    page: PDFPage,
    font: PDFFont,
    input: EmployeeCardInput,
    regular: PDFFont,
    rgb: PdfRgb,
): void {
    const width = pxX(CR80_LAYOUT.identity.maxWidth);
    [normalizeCardText(input.first_name), normalizeCardText(input.last_name)].forEach(
        (line, index) => {
            const fitted = fitCardText(line, font, width, [10, 9, 8, 7]);
            drawCenteredCardText(
                page,
                font,
                fitted.text,
                CR80_LAYOUT.identity.centerX,
                CR80_LAYOUT.identity.nameBaselines[index],
                fitted.size,
                CR80_COLORS.name,
                rgb,
            );
        },
    );
    const role = fitCardText(
        normalizeCardText(input.role_name ?? 'Personal'),
        regular,
        width,
        [8.5, 8, 7],
    );
    drawCenteredCardText(
        page,
        regular,
        role.text,
        CR80_LAYOUT.identity.centerX,
        CR80_LAYOUT.identity.line3Baseline,
        role.size,
        CR80_COLORS.accent,
        rgb,
    );
    drawCenteredCardText(
        page,
        regular,
        'PERSONAL',
        CR80_LAYOUT.identity.centerX,
        CR80_LAYOUT.identity.line4Baseline,
        8.5,
        [0, 0, 0],
        rgb,
    );
}

/** CR80 employee card. The QR payload is the employee's plain eight-digit DNI. */
export async function generateEmployeeCardPdf(input: EmployeeCardInput): Promise<void> {
    if (!/^\d{8}$/.test(input.dni))
        throw new Error('El usuario necesita un DNI de ocho dígitos para el carnet.');
    if (!input.photo_url)
        throw new Error('El usuario no tiene una foto disponible para el carnet.');
    const printWindow = openCr80Window();

    try {
        const [{ PDFDocument, StandardFonts, degrees, rgb }, QRCode, templateBytes, photoBytes] =
            await Promise.all([
                import('pdf-lib'),
                import('qrcode'),
                fetchCardBytes(cardTemplateUrl, 'No se pudo cargar la plantilla del carnet.'),
                photoUrlAsJpegBytes(
                    input.photo_url,
                    'No se pudo cargar la foto actual del usuario.',
                ),
            ]);
        const pdf = await PDFDocument.create();
        const page = pdf.addPage([CARD_WIDTH, CARD_HEIGHT]);
        const [font, boldFont, template, photo, qrDataUrl] = await Promise.all([
            pdf.embedFont(StandardFonts.Helvetica),
            pdf.embedFont(StandardFonts.HelveticaBold),
            pdf.embedPng(templateBytes),
            pdf.embedJpg(photoBytes),
            QRCode.default.toDataURL(input.dni, {
                errorCorrectionLevel: 'M',
                margin: 1,
                width: 512,
                color: { dark: '#000000', light: '#FFFFFFFF' },
            }),
        ]);
        const qr = await pdf.embedPng(qrDataUrl);
        page.drawImage(template, { x: 0, y: 0, width: CARD_WIDTH, height: CARD_HEIGHT });
        page.drawImage(qr, {
            x: pxX(CR80_LAYOUT.qr.x),
            y: fromTop(CR80_LAYOUT.qr.top, CR80_LAYOUT.qr.size),
            width: pxX(CR80_LAYOUT.qr.size),
            height: pxY(CR80_LAYOUT.qr.size),
        });
        page.drawImage(photo, {
            x: pxX(CR80_LAYOUT.photo.x),
            y: fromTop(CR80_LAYOUT.photo.top, CR80_LAYOUT.photo.height),
            width: pxX(CR80_LAYOUT.photo.width),
            height: pxY(CR80_LAYOUT.photo.height),
        });
        drawCr80PhotoFrame(page, rgb);
        drawCr80Dni(page, boldFont, input.dni, degrees, rgb);
        drawIdentity(page, boldFont, input, font, rgb);

        const filename = `carnet-personal-${input.dni}.pdf`;
        pdf.setTitle(filename);
        openPdfInWindow(await pdf.save(), filename, printWindow);
    } catch (error) {
        printWindow.close();
        throw error;
    }
}
