<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModelGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper((string) $this->input('currency', 'USD'))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'between:0,100000'],
            'currency' => ['required', 'alpha', 'size:3'],
            'sizes' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'between:0,100000'],
            'photos' => ['required', 'array:front,left,back,right', 'min:1', 'max:4'],
            'photos.front' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:'.config('tripo.max_image_kb'), 'dimensions:min_width=256,min_height=256,max_width=8192,max_height=8192'],
            'photos.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:'.config('tripo.max_image_kb'), 'dimensions:min_width=256,min_height=256,max_width=8192,max_height=8192'],
            'model' => ['required', Rule::in(config('tripo.models'))],
            'face_limit' => ['required', 'integer', 'between:1000,50000'],
        ];
    }
}
