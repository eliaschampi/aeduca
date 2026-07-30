import cardTemplateUrl from '@/assets/student-card-template.png';
import type { EnrollmentSummary, StudentProfile } from '@/types/student';
import type { PDFFont, PDFPage } from 'pdf-lib';

type PdfRgb = typeof import('pdf-lib').rgb;
type PdfDegrees = typeof import('pdf-lib').degrees;

type StudentCardInput = Pick<StudentProfile, 'dni' | 'first_name' | 'last_name' | 'photo_url'> & {
    enrollment: Pick<EnrollmentSummary, 'roll_code' | 'degree_label' | 'group_name' | 'cycle_name'>;
};

const POINTS_PER_MILLIMETER = 72 / 25.4;
const CARD_WIDTH = 85.6 * POINTS_PER_MILLIMETER;
const CARD_HEIGHT = 53.98 * POINTS_PER_MILLIMETER;
const PHOTO_OUTPUT_WIDTH = 384;

const CARD_LAYOUT = {
    template: { width: 2032, height: 1276 },
    qr: { x: 71, top: 307, size: 703 },
    dni: { centerX: 1080, centerY: 638, fontSize: 10 },
    photo: { x: 1248, top: 95, width: 615, height: 591, frameTop: 0, frameHeight: 686 },
    identity: {
        centerX: 1526,
        nameBaselines: [788, 876],
        academicBaseline: 978,
        rollCodeBaseline: 1072,
        maxWidth: 590,
    },
} as const;

const COLORS = {
    name: [34 / 255, 62 / 255, 156 / 255],
    academic: [222 / 255, 97 / 255, 52 / 255],
    dni: [11 / 255, 28 / 255, 46 / 255],
    photoFrame: [230 / 255, 84 / 255, 0],
} as const;

function pxX(value: number): number {
    return (value / CARD_LAYOUT.template.width) * CARD_WIDTH;
}

function pxY(value: number): number {
    return (value / CARD_LAYOUT.template.height) * CARD_HEIGHT;
}

function fromTop(top: number, height = 0): number {
    return CARD_HEIGHT - pxY(top + height);
}

function toArrayBuffer(bytes: Uint8Array): ArrayBuffer {
    const buffer = new ArrayBuffer(bytes.byteLength);
    new Uint8Array(buffer).set(bytes);

    return buffer;
}

function normalizeText(value: string): string {
    return value.trim().replace(/\s+/g, ' ');
}

function fitText(
    text: string,
    font: PDFFont,
    maxWidth: number,
    sizes: number[],
): {
    text: string;
    size: number;
} {
    for (const size of sizes) {
        if (font.widthOfTextAtSize(text, size) <= maxWidth) {
            return { text, size };
        }
    }

    const size = sizes.at(-1) ?? 7;
    const suffix = '…';
    let value = text;

    while (value.length > 0 && font.widthOfTextAtSize(`${value}${suffix}`, size) > maxWidth) {
        value = value.slice(0, -1);
    }

    return { text: value ? `${value}${suffix}` : suffix, size };
}

function drawCenteredText(
    page: PDFPage,
    font: PDFFont,
    text: string,
    centerX: number,
    baselineTop: number,
    size: number,
    color: readonly [number, number, number],
    rgb: PdfRgb,
): void {
    const width = font.widthOfTextAtSize(text, size);

    page.drawText(text, {
        x: pxX(centerX) - width / 2,
        y: fromTop(baselineTop),
        font,
        size,
        color: rgb(color[0], color[1], color[2]),
    });
}

async function fetchResponse(url: string, unavailableMessage: string): Promise<Response> {
    const response = await fetch(url, { credentials: 'same-origin' });

    if (!response.ok) {
        throw new Error(unavailableMessage);
    }

    return response;
}

async function fetchBytes(url: string, unavailableMessage: string): Promise<Uint8Array> {
    return new Uint8Array(await (await fetchResponse(url, unavailableMessage)).arrayBuffer());
}

async function photoAsJpegBytes(photoUrl: string): Promise<Uint8Array> {
    const photo = await (
        await fetchResponse(photoUrl, 'No se pudo cargar la foto actual del alumno.')
    ).blob();
    const bitmap = await createImageBitmap(photo);
    const canvas = document.createElement('canvas');
    const outputHeight = Math.round(
        (PHOTO_OUTPUT_WIDTH * CARD_LAYOUT.photo.height) / CARD_LAYOUT.photo.width,
    );

    canvas.width = PHOTO_OUTPUT_WIDTH;
    canvas.height = outputHeight;

    try {
        const context = canvas.getContext('2d');
        if (!context) {
            throw new Error('El navegador no pudo preparar la foto para el carnet.');
        }

        const sourceAspect = bitmap.width / bitmap.height;
        const targetAspect = canvas.width / canvas.height;
        const sourceWidth =
            sourceAspect > targetAspect ? bitmap.height * targetAspect : bitmap.width;
        const sourceHeight =
            sourceAspect > targetAspect ? bitmap.height : bitmap.width / targetAspect;
        const sourceX = (bitmap.width - sourceWidth) / 2;
        const sourceY = (bitmap.height - sourceHeight) / 2;

        context.drawImage(
            bitmap,
            sourceX,
            sourceY,
            sourceWidth,
            sourceHeight,
            0,
            0,
            canvas.width,
            canvas.height,
        );

        const jpeg = await new Promise<Blob>((resolve, reject) => {
            canvas.toBlob(
                (blob) => {
                    if (blob) {
                        resolve(blob);
                        return;
                    }

                    reject(new Error('El navegador no pudo convertir la foto del alumno.'));
                },
                'image/jpeg',
                0.86,
            );
        });

        return new Uint8Array(await jpeg.arrayBuffer());
    } finally {
        bitmap.close();
    }
}

function drawPhotoFrame(page: PDFPage, rgb: PdfRgb): void {
    const x = pxX(CARD_LAYOUT.photo.x);
    const right = pxX(CARD_LAYOUT.photo.x + CARD_LAYOUT.photo.width);
    const top = fromTop(CARD_LAYOUT.photo.frameTop);
    const bottom = fromTop(CARD_LAYOUT.photo.frameHeight);
    const frameColor = rgb(...COLORS.photoFrame);

    page.drawLine({
        start: { x, y: top },
        end: { x, y: bottom },
        thickness: 1.2,
        color: frameColor,
    });
    page.drawLine({
        start: { x: right, y: top },
        end: { x: right, y: bottom },
        thickness: 1.2,
        color: frameColor,
    });
    page.drawLine({
        start: { x, y: bottom },
        end: { x: right, y: bottom },
        thickness: 1.5,
        color: frameColor,
    });
}

function drawIdentity(
    page: PDFPage,
    font: PDFFont,
    input: StudentCardInput,
    academicFont: PDFFont,
    rgb: PdfRgb,
): void {
    const nameLines = [normalizeText(input.first_name), normalizeText(input.last_name)];
    const nameWidth = pxX(CARD_LAYOUT.identity.maxWidth);

    nameLines.forEach((line, index) => {
        const fitted = fitText(line, font, nameWidth, [10, 9, 8, 7]);
        drawCenteredText(
            page,
            font,
            fitted.text,
            CARD_LAYOUT.identity.centerX,
            CARD_LAYOUT.identity.nameBaselines[index],
            fitted.size,
            COLORS.name,
            rgb,
        );
    });

    const academicLabel = `${input.enrollment.degree_label}${input.enrollment.group_name} de ${input.enrollment.cycle_name}`;
    const academic = fitText(academicLabel, academicFont, nameWidth, [8.5, 8, 7]);
    drawCenteredText(
        page,
        academicFont,
        academic.text,
        CARD_LAYOUT.identity.centerX,
        CARD_LAYOUT.identity.academicBaseline,
        academic.size,
        COLORS.academic,
        rgb,
    );

    const rollCode = input.enrollment.roll_code.split('').join(' ');
    drawCenteredText(
        page,
        academicFont,
        rollCode,
        CARD_LAYOUT.identity.centerX,
        CARD_LAYOUT.identity.rollCodeBaseline,
        8.5,
        [0, 0, 0],
        rgb,
    );
}

function drawDni(
    page: PDFPage,
    font: PDFFont,
    dni: string,
    degrees: PdfDegrees,
    rgb: PdfRgb,
): void {
    const text = dni.split('').join(' ');
    const width = font.widthOfTextAtSize(text, CARD_LAYOUT.dni.fontSize);

    page.drawText(text, {
        x: pxX(CARD_LAYOUT.dni.centerX) - CARD_LAYOUT.dni.fontSize * 0.25,
        y: fromTop(CARD_LAYOUT.dni.centerY) - width / 2,
        font,
        size: CARD_LAYOUT.dni.fontSize,
        color: rgb(...COLORS.dni),
        rotate: degrees(90),
    });
}

function openPdf(bytes: Uint8Array, filename: string, target: Window): void {
    const url = URL.createObjectURL(new Blob([toArrayBuffer(bytes)], { type: 'application/pdf' }));

    target.location.replace(url);
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
    target.document.title = filename;
}

export async function generateStudentCardPdf(input: StudentCardInput): Promise<void> {
    if (!input.photo_url) {
        throw new Error('El alumno no tiene una foto disponible para el carnet.');
    }

    const printWindow = window.open('', '_blank');
    if (!printWindow) {
        throw new Error(
            'El navegador bloqueó la pestaña del carnet. Permite las ventanas emergentes e inténtalo nuevamente.',
        );
    }

    try {
        const [{ PDFDocument, StandardFonts, degrees, rgb }, QRCode, templateBytes, photoBytes] =
            await Promise.all([
                import('pdf-lib'),
                import('qrcode'),
                fetchBytes(cardTemplateUrl, 'No se pudo cargar la plantilla del carnet.'),
                photoAsJpegBytes(input.photo_url),
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
            x: pxX(CARD_LAYOUT.qr.x),
            y: fromTop(CARD_LAYOUT.qr.top, CARD_LAYOUT.qr.size),
            width: pxX(CARD_LAYOUT.qr.size),
            height: pxY(CARD_LAYOUT.qr.size),
        });
        page.drawImage(photo, {
            x: pxX(CARD_LAYOUT.photo.x),
            y: fromTop(CARD_LAYOUT.photo.top, CARD_LAYOUT.photo.height),
            width: pxX(CARD_LAYOUT.photo.width),
            height: pxY(CARD_LAYOUT.photo.height),
        });
        drawPhotoFrame(page, rgb);
        drawDni(page, boldFont, input.dni, degrees, rgb);
        drawIdentity(page, boldFont, input, font, rgb);

        const filename = `carnet-${input.dni}.pdf`;
        pdf.setTitle(filename);
        openPdf(await pdf.save(), filename, printWindow);
    } catch (error) {
        printWindow.close();
        throw error;
    }
}
