<?php

namespace App\Http\Requests;

use App\Models\TryOnEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTryOnEventRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'type' => ['required', Rule::in(TryOnEvent::TYPES)],
            'session_id' => ['required', 'uuid'],
            'variant' => ['nullable', 'string', 'max:64'],
            'device' => ['required', Rule::in(TryOnEvent::DEVICES)],
        ];
    }
}
