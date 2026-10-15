<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Rules\GlbFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug($this->input('slug') ?: $this->input('name', '')),
            'currency' => Str::upper((string) $this->input('currency', 'USD')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:140', Rule::unique('products')->ignore($product)],
            'brand' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'sizes' => ['nullable', 'string', 'max:500'],
            'model' => [
                $product ? 'nullable' : 'required',
                'file',
                'extensions:glb',
                'max:'.config('tryon.max_model_upload_kb'),
                new GlbFile,
            ],
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('tryon.max_poster_upload_kb')],
            'default_variant' => ['nullable', 'string', 'max:64'],
            'attribution' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * Validated attributes mapped onto product columns (files are handled separately).
     *
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        $sizes = collect(explode(',', (string) $this->validated('sizes')))
            ->map(fn (string $size) => trim($size))
            ->filter()
            ->values()
            ->all();

        return [
            'name' => $this->validated('name'),
            'slug' => $this->validated('slug'),
            'brand' => $this->validated('brand'),
            'description' => $this->validated('description'),
            'price_cents' => (int) round($this->validated('price') * 100),
            'currency' => $this->validated('currency'),
            'sizes' => $sizes ?: null,
            'default_variant' => $this->validated('default_variant'),
            'attribution' => $this->validated('attribution'),
            'is_active' => $this->validated('is_active'),
            'sort_order' => (int) $this->validated('sort_order', 0),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $product = $this->route('product');
            if ($this->boolean('is_active') && $product?->requires_fit_review && ! $this->hasFile('model')) {
                $validator->errors()->add('is_active', 'Review the model in the calibrator and save its fit before publishing.');
            }
        }];
    }
}
