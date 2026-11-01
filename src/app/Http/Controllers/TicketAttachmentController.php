<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TicketAttachment;
use Illuminate\Http\JsonResponse;

final class TicketAttachmentController extends Controller
{
    public function download(TicketAttachment $attachment): JsonResponse
    {
        return response()->json([
            'message' => 'ticket_attachments.download stub',
            'attachment_id' => $attachment->id,
            'file_name' => $attachment->file_name,
        ]);
    }
}
