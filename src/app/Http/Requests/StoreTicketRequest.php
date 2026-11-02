<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                Rule::exists('ticket_categories', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'priority' => [
                'required',
                Rule::enum(TicketPriority::class),
            ],
            'subject' => [
                'required',
                'string',
                'max:255',
            ],
            'description' => [
                'required',
                'string',
                'min:10',
            ],
            'attachments' => [
                'nullable',
                'array',
            ],
            'attachments.*' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,pdf,doc,docx,txt,zip',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected ticket category is either invalid or inactive.',
            'description.min' => 'Please provide a more detailed description (minimum 10 characters).',
            'attachments.*.max' => 'Each attachment may not be greater than 10MB.',
            'attachments.*.mimes' => 'Allowed attachment types: jpg, jpeg, png, pdf, doc, docx, txt, zip.',
        ];
    }
}
