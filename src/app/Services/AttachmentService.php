<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AttachmentService
{
    public const array ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'txt', 'zip',
    ];

    public const int MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10MB = 10240 KB

    public const string DISK = 'private';

    /**
     * Upload and store attachment metadata.
     *
     * @throws InvalidArgumentException
     */
    public function upload(UploadedFile $file, Model $attachable, User $uploader): TicketAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException(
                sprintf('File extension "%s" is not allowed. Allowed: %s', $extension, implode(', ', self::ALLOWED_EXTENSIONS))
            );
        }

        $size = $file->getSize();

        if ($size > self::MAX_SIZE_BYTES) {
            throw new InvalidArgumentException(
                sprintf('File size %d bytes exceeds maximum allowed limit of %d bytes (10MB).', $size, self::MAX_SIZE_BYTES)
            );
        }

        $now = Date::now();
        $directory = sprintf('attachments/%s/%s', $now->format('Y'), $now->format('m'));
        $randomHash = Str::random(40);
        $fileNameToStore = $randomHash.($extension !== '' ? '.'.$extension : '');

        $storedPath = $file->storeAs($directory, $fileNameToStore, self::DISK);

        if (! is_string($storedPath)) {
            throw new InvalidArgumentException('Could not store file on private disk.');
        }

        /** @var TicketAttachment $attachment */
        $attachment = $attachable->attachments()->create([
            'disk' => self::DISK,
            'file_path' => $storedPath,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'file_size' => $size,
            'uploaded_by' => $uploader->id,
        ]);

        return $attachment;
    }

    /**
     * Delete physical file from storage disk and remove record.
     */
    public function delete(TicketAttachment $attachment): bool
    {
        if (Storage::disk($attachment->disk)->exists($attachment->file_path)) {
            Storage::disk($attachment->disk)->delete($attachment->file_path);
        }

        return (bool) $attachment->delete();
    }
}
