<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Evidence
{
    public const MAX_BYTES = 2_097_152;
    public const MAX_ENCODED_BYTES = 2_796_204;

    public static function encoded(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_ENCODED_BYTES) {
            throw new BusinessError('附件须为 2 MiB 内的 PDF 或文本文件。', 413);
        }
        return $value;
    }

    public static function parse(mixed $name, mixed $encoded): array
    {
        $name = Input::text($name, '附件文件名', 180);
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw new BusinessError('附件文件名无效。');
        }
        $bytes = base64_decode(self::encoded($encoded), true);
        if ($bytes === false || $bytes === '' || base64_encode($bytes) !== $encoded || strlen($bytes) > self::MAX_BYTES) {
            throw new BusinessError('附件内容无效或超过 2 MiB。');
        }
        $media = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => preg_match('/^%PDF-[12]\.\d/', $bytes) ? 'application/pdf' : null,
            'txt' => mb_check_encoding($bytes, 'UTF-8') && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $bytes) ? 'text/plain' : null,
            default => null,
        };
        if ($media === null) {
            throw new BusinessError('附件格式须为 PDF 或 UTF-8 文本。', 415);
        }
        return ['name' => $name, 'media' => $media, 'length' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'body' => $bytes];
    }

    public static function uploaded(array $file): array
    {
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name']) || ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new BusinessError('请选择 2 MiB 内的 PDF 或文本附件。');
        }
        $body = file_get_contents($file['tmp_name'], false, null, 0, self::MAX_BYTES + 1);
        if ($body === false) {
            throw new BusinessError('无法读取附件，请重新上传。');
        }
        return ['file_name' => $file['name'], 'file_base64' => base64_encode($body)];
    }
}
