<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'slug', 'brand', 'description', 'price_cents', 'currency', 'sizes',
    'model_path', 'poster_path', 'default_variant', 'calibration', 'attribution',
    'is_active', 'sort_order',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Files uploaded through the admin are stored on the public disk and
     * referenced from the web root through the storage symlink.
     */
    public const UPLOAD_PREFIX = 'storage/';

    public const AXES = ['+x', '-x', '+y', '-y', '+z', '-z'];

    /**
     * How a model is mapped onto the tracked foot. Offsets are centimetres and
     * rotations degrees in the foot frame: X to the right, Y toward the toes,
     * Z up. See resources/js/tryon/shoe-fitter.js.
     */
    public const DEFAULT_CALIBRATION = [
        'forwardAxis' => '+z',
        'upAxis' => '+y',
        'mirror' => false,
        'scale' => 1.0,
        'offsetCm' => [0.0, 0.0, 0.0],
        'rotationDeg' => [0.0, 0.0, 0.0],
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'sizes' => 'array',
            'calibration' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<TryOnEvent, $this>
     */
    public function tryOnEvents(): HasMany
    {
        return $this->hasMany(TryOnEvent::class);
    }

    #[Scope]
    protected function listed(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    public function modelUrl(): string
    {
        return $this->publicUrl($this->model_path);
    }

    public function posterUrl(): ?string
    {
        return $this->poster_path ? $this->publicUrl($this->poster_path) : null;
    }

    public function formattedPrice(): string
    {
        $amount = $this->price_cents / 100;

        // Number::currency needs ext-intl; fall back rather than fail a page.
        return class_exists(\NumberFormatter::class)
            ? Number::currency($amount, in: $this->currency)
            : $this->currency.' '.number_format($amount, 2);
    }

    /**
     * @return array{forwardAxis: string, upAxis: string, mirror: bool, scale: float, offsetCm: array<int, float>, rotationDeg: array<int, float>}
     */
    public function resolvedCalibration(): array
    {
        $calibration = array_merge(self::DEFAULT_CALIBRATION, $this->calibration ?? []);

        return [
            'forwardAxis' => (string) $calibration['forwardAxis'],
            'upAxis' => (string) $calibration['upAxis'],
            'mirror' => (bool) $calibration['mirror'],
            'scale' => (float) $calibration['scale'],
            'offsetCm' => array_map('floatval', array_values($calibration['offsetCm'])),
            'rotationDeg' => array_map('floatval', array_values($calibration['rotationDeg'])),
        ];
    }

    /**
     * The product as consumed by the try-on runtime.
     *
     * @return array<string, mixed>
     */
    public function toTryOnPayload(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'brand' => $this->brand,
            'price' => $this->formattedPrice(),
            'modelUrl' => $this->modelUrl(),
            'posterUrl' => $this->posterUrl(),
            'defaultVariant' => $this->default_variant,
            'calibration' => $this->resolvedCalibration(),
            'productUrl' => route('products.show', $this),
            'tryOnUrl' => route('try-on.show', $this),
        ];
    }

    /**
     * Relative path of an uploaded file on the public disk, or null for files
     * that ship with the application (sample models).
     */
    public static function uploadedDiskPath(?string $path): ?string
    {
        return $path && Str::startsWith($path, self::UPLOAD_PREFIX)
            ? Str::after($path, self::UPLOAD_PREFIX)
            : null;
    }

    public function deleteUploadedFiles(): void
    {
        foreach ([$this->model_path, $this->poster_path] as $path) {
            if ($diskPath = self::uploadedDiskPath($path)) {
                Storage::disk('public')->delete($diskPath);
            }
        }
    }

    private function publicUrl(string $path): string
    {
        // Uploaded files have unique names; bundled files are versioned so they
        // can be cached for a year.
        $url = asset($path);

        return self::uploadedDiskPath($path) ? $url : $url.'?v='.config('tryon.asset_version');
    }
}
