<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        if (! $ticket instanceof Ticket) {
            return false;
        }

        return $this->user()?->can('update', $ticket) ?? false;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();

        if ($user !== null && $user->role->isCustomer()) {
            return [
                'subject' => ['sometimes', 'required', 'string', 'max:255'],
                'description' => ['sometimes', 'required', 'string', 'min:10'],
            ];
        }

        // Staff (Agent & Admin)
        return [
            'category_id' => [
                'sometimes',
                'required',
                Rule::exists('ticket_categories', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'priority' => [
                'sometimes',
                'required',
                Rule::enum(TicketPriority::class),
            ],
            'status' => [
                'sometimes',
                'required',
                Rule::enum(TicketStatus::class),
            ],
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'min:10'],
        ];
    }
}
