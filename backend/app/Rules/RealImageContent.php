<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Http\UploadedFile;

/**
 * Defense-in-depth for image uploads (#977 / CVE-2025-27515 class).
 *
 * Laravel's `mimes:`/`image` rules can be bypassed so that a script is accepted
 * under an image extension. This rule inspects the file's ACTUAL content via
 * getMimeType() (finfo/magic-bytes, not the client-supplied type or extension)
 * and rejects anything that is not a real image of an allowed type — so a
 * disguised .jpg that is really PHP/HTML/etc. is refused even if it slips past
 * the extension check. Independent of the framework version.
 */
class RealImageContent implements Rule
{
    /** @var list<string> */
    private array $allowed;

    /** @param list<string> $allowedMimes */
    public function __construct(array $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
    {
        $this->allowed = array_map('strtolower', $allowedMimes);
    }

    public function passes($attribute, $value): bool
    {
        if (!$value instanceof UploadedFile || !$value->isValid()) {
            return false;
        }

        $mime = strtolower((string) $value->getMimeType());

        return in_array($mime, $this->allowed, true);
    }

    public function message(): string
    {
        return '檔案內容不是有效的圖片（實際內容與副檔名不符），請重新選擇圖片檔。';
    }
}
