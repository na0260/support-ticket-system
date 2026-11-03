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

        $user = $this->user();
        if ($user === null) {
            return false;
        }

        // Check priority update authorization if priority is provided
        if ($this->has('priority') && ! $user->can('updatePriority', $ticket)) {
            return false;
        }

        // Check status update authorization if status is provided
        if ($this->has('status') && ! $user->can('updateStatus', $ticket)) {
            return false;
        }

        // Check general ticket update authorization if other fields are provided
        if (($this->has('subject') || $this->has('description') || $this->has('category_id'))
            && ! $user->can('update', $ticket)) {
            return false;
        }

        return $user->can('update', $ticket) || $user->can('updatePriority', $ticket) || $user->can('updateStatus', $ticket);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();

        if ($user !== null && $user->role->isCustomer()) {
            return [
                'priority' => [
                    'sometimes',
                    'required',
                    Rule::enum(TicketPriority::class),
                ],
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
