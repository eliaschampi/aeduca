import { openPdfInWindow } from './browser-pdf';
import type { PDFFont, PDFPage } from 'pdf-lib';

type PdfRgb = typeof import('pdf-lib').rgb;
type PdfDegrees = typeof import('pdf-lib').degrees;

export const CARD_WIDTH = 85.6 * (72 / 25.4);
export const CARD_HEIGHT = 53.98 * (72 / 25.4);

export const CR80_LAYOUT = {
    template: { width: 2032, height: 1276 },
    qr: { x: 71, top: 307, size: 703 },
    dni: { centerX: 1080, centerY: 638, fontSize: 10 },
    photo: { x: 1248, top: 95, width: 615, height: 591, frameTop: 0, frameHeight: 686 },
    identity: {
        centerX: 1526,
        nameBaselines: [788, 876] as const,
        line3Baseline: 978,
        line4Baseline: 1072,
        maxWidth: 590,
    },
} as const;

export const CR80_COLORS = {
    name: [34 / 255, 62 / 255, 156 / 255] as const,
    accent: [222 / 255, 97 / 255, 52 / 255] as const,
    dni: [11 / 255, 28 / 255, 46 / 255] as const,
    photoFrame: [230 / 255, 84 / 255, 0] as const,
};

export const pxX = (value: number): number => (value / CR80_LAYOUT.template.width) * CARD_WIDTH;
export const pxY = (value: number): number => (value / CR80_LAYOUT.template.height) * CARD_HEIGHT;
export const fromTop = (top: number, height = 0): number => CARD_HEIGHT - pxY(top + height);
export const normalizeCardText = (value: string): string => value.trim().replace(/\s+/g, ' ');

export function fitCardText(text: string, font: PDFFont, maxWidth: number, sizes: number[]) {
    for (const size of sizes) {
        if (font.widthOfTextAtSize(text, size) <= maxWidth) return { text, size };
    }

    const size = sizes.at(-1) ?? 7;
    const suffix = '…';
    let value = text;
    while (value.length > 0 && font.widthOfTextAtSize(`${value}${suffix}`, size) > maxWidth) {
        value = value.slice(0, -1);
    }

    return { text: value ? `${value}${suffix}` : suffix, size };
}

export function drawCenteredCardText(
    page: PDFPage,
    font: PDFFont,
    text: string,
    centerX: number,
    baselineTop: number,
    size: number,
    color: readonly [number, number, number],
    rgb: PdfRgb,
): void {
    page.drawText(text, {
        x: pxX(centerX) - font.widthOfTextAtSize(text, size) / 2,
        y: fromTop(baselineTop),
        font,
        size,
        color: rgb(...color),
    });
}

export async function fetchCardBytes(url: string, message: string): Promise<Uint8Array> {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error(message);
    return new Uint8Array(await response.arrayBuffer());
}

export async function photoUrlAsJpegBytes(url: string, message: string): Promise<Uint8Array> {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error(message);

    const bitmap = await createImageBitmap(await response.blob());
    const canvas = document.createElement('canvas');
    canvas.width = 384;
    canvas.height = Math.round((384 * CR80_LAYOUT.photo.height) / CR80_LAYOUT.photo.width);

    try {
        const context = canvas.getContext('2d');
        if (!context) throw new Error('El navegador no pudo preparar la foto para el carnet.');

        const sourceAspect = bitmap.width / bitmap.height;
        const targetAspect = canvas.width / canvas.height;
        const sourceWidth =
            sourceAspect > targetAspect ? bitmap.height * targetAspect : bitmap.width;
        const sourceHeight =
            sourceAspect > targetAspect ? bitmap.height : bitmap.width / targetAspect;
        context.drawImage(
            bitmap,
            (bitmap.width - sourceWidth) / 2,
            (bitmap.height - sourceHeight) / 2,
            sourceWidth,
            sourceHeight,
            0,
            0,
            canvas.width,
            canvas.height,
        );

        const jpeg = await new Promise<Blob>((resolve, reject) => {
            canvas.toBlob(
                (blob) =>
                    blob
                        ? resolve(blob)
                        : reject(new Error('El navegador no pudo convertir la foto del carnet.')),
                'image/jpeg',
                0.86,
            );
        });
        return new Uint8Array(await jpeg.arrayBuffer());
    } finally {
        bitmap.close();
    }
}

export function drawCr80PhotoFrame(page: PDFPage, rgb: PdfRgb): void {
    const x = pxX(CR80_LAYOUT.photo.x);
    const right = pxX(CR80_LAYOUT.photo.x + CR80_LAYOUT.photo.width);
    const top = fromTop(CR80_LAYOUT.photo.frameTop);
    const bottom = fromTop(CR80_LAYOUT.photo.frameHeight);
    const color = rgb(...CR80_COLORS.photoFrame);

    page.drawLine({ start: { x, y: top }, end: { x, y: bottom }, thickness: 1.2, color });
    page.drawLine({
        start: { x: right, y: top },
        end: { x: right, y: bottom },
        thickness: 1.2,
        color,
    });
    page.drawLine({ start: { x, y: bottom }, end: { x: right, y: bottom }, thickness: 1.5, color });
}

export function drawCr80Dni(
    page: PDFPage,
    font: PDFFont,
    dni: string,
    degrees: PdfDegrees,
    rgb: PdfRgb,
): void {
    const text = dni.split('').join(' ');
    const width = font.widthOfTextAtSize(text, CR80_LAYOUT.dni.fontSize);
    page.drawText(text, {
        x: pxX(CR80_LAYOUT.dni.centerX) - CR80_LAYOUT.dni.fontSize * 0.25,
        y: fromTop(CR80_LAYOUT.dni.centerY) - width / 2,
        font,
        size: CR80_LAYOUT.dni.fontSize,
        color: rgb(...CR80_COLORS.dni),
        rotate: degrees(90),
    });
}

export function openCr80Window(): Window {
    const printWindow = window.open('', '_blank');
    if (!printWindow) {
        throw new Error(
            'El navegador bloqueó la pestaña del carnet. Permite las ventanas emergentes e inténtalo nuevamente.',
        );
    }
    return printWindow;
}

export { openPdfInWindow };
