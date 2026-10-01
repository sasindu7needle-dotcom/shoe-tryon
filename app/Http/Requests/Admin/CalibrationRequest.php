<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalibrationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'forwardAxis' => ['required', Rule::in(Product::AXES)],
            'upAxis' => ['required', Rule::in(Product::AXES)],
            'mirror' => ['required', 'boolean'],
            'scale' => ['required', 'numeric', 'between:0.5,2'],
            'offsetCm' => ['required', 'array', 'size:3'],
            'offsetCm.*' => ['required', 'numeric', 'between:-10,10'],
            'rotationDeg' => ['required', 'array', 'size:3'],
            'rotationDeg.*' => ['required', 'numeric', 'between:-45,45'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (substr((string) $this->input('forwardAxis'), 1) === substr((string) $this->input('upAxis'), 1)) {
                    $validator->errors()->add('upAxis', 'The up axis must be perpendicular to the forward axis.');
                }
            },
        ];
    }

    /**
     * @return array{forwardAxis: string, upAxis: string, mirror: bool, scale: float, offsetCm: array<int, float>, rotationDeg: array<int, float>}
     */
    public function calibration(): array
    {
        return [
            'forwardAxis' => $this->validated('forwardAxis'),
            'upAxis' => $this->validated('upAxis'),
            'mirror' => (bool) $this->validated('mirror'),
            'scale' => round((float) $this->validated('scale'), 4),
            'offsetCm' => array_map(fn ($value) => round((float) $value, 2), array_values($this->validated('offsetCm'))),
            'rotationDeg' => array_map(fn ($value) => round((float) $value, 1), array_values($this->validated('rotationDeg'))),
        ];
    }
}
