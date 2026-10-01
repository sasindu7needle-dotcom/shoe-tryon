<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Accepts binary glTF 2.0 files by checking the 12-byte GLB header rather than
 * trusting the client-supplied extension or MIME type.
 */
class GlbFile implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The :attribute must be a valid upload.');

            return;
        }

        $handle = fopen($value->getRealPath(), 'rb');
        $header = $handle ? fread($handle, 12) : false;
        if ($handle) {
            fclose($handle);
        }

        if ($header === false || strlen($header) < 12) {
            $fail('The :attribute is not a GLB file.');

            return;
        }

        ['magic' => $magic, 'version' => $version, 'length' => $length] = unpack('a4magic/Vversion/Vlength', $header);

        if ($magic !== 'glTF' || $version !== 2) {
            $fail('The :attribute must be a binary glTF 2.0 (.glb) file.');
        } elseif ($length !== $value->getSize()) {
            $fail('The :attribute is truncated or corrupted.');
        }
    }
}
