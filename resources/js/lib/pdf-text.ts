import type { PDFFont } from 'pdf-lib';

export function normalizePdfText(value: string | null | undefined, fallback = '-'): string {
    const normalized = value?.trim().replace(/\s+/g, ' ') ?? '';
    return normalized || fallback;
}

export function fitPdfText(text: string, font: PDFFont, size: number, maxWidth: number): string {
    if (font.widthOfTextAtSize(text, size) <= maxWidth) return text;

    const suffix = '...';
    let fitted = text;
    while (fitted.length > 0 && font.widthOfTextAtSize(`${fitted}${suffix}`, size) > maxWidth) {
        fitted = fitted.slice(0, -1);
    }

    return fitted ? `${fitted}${suffix}` : suffix;
}

export function wrapPdfText(text: string, font: PDFFont, size: number, maxWidth: number): string[] {
    const words = normalizePdfText(text).split(' ');
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
                : fitPdfText(word, font, size, maxWidth);
    }

    if (line) lines.push(line);
    return lines.length > 0 ? lines : ['-'];
}
