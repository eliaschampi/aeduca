<?php

namespace App\Support\Drive;

/**
 * Upload allow-list, mirroring Lumi's `ALLOWED_MIME_TYPES` so the server and
 * the Drive UI agree on what may be stored and how it is rendered.
 */
final class DriveMimeType
{
    /** @var array<string, string> */
    private const TYPE_BY_MIME = [
        'image/jpeg' => 'img',
        'image/png' => 'img',
        'image/gif' => 'img',
        'image/webp' => 'img',
        'image/svg+xml' => 'img',
        'image/avif' => 'img',
        'video/mp4' => 'vid',
        'video/webm' => 'vid',
        'video/quicktime' => 'vid',
        'audio/mpeg' => 'aud',
        'audio/wav' => 'aud',
        'audio/ogg' => 'aud',
        'audio/webm' => 'aud',
        'application/pdf' => 'doc',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'doc',
        'text/plain' => 'doc',
        'application/zip' => 'zip',
        'application/x-rar-compressed' => 'zip',
        'application/x-7z-compressed' => 'zip',
    ];

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return array_keys(self::TYPE_BY_MIME);
    }

    public static function fileType(string $mimeType): string
    {
        return self::TYPE_BY_MIME[$mimeType] ?? 'otr';
    }
}
