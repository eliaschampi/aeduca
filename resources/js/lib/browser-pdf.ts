function toArrayBuffer(bytes: Uint8Array): ArrayBuffer {
    const buffer = new ArrayBuffer(bytes.byteLength);
    new Uint8Array(buffer).set(bytes);

    return buffer;
}

export function openPdfInWindow(bytes: Uint8Array, filename: string, target: Window): void {
    const url = URL.createObjectURL(new Blob([toArrayBuffer(bytes)], { type: 'application/pdf' }));

    target.location.replace(url);
    target.document.title = filename;
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
}
