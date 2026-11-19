<?php

namespace App\Http\Requests\Admin;

use App\Domain\Catalog\ProductVideos;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * `video` = an mp4 or webm file (up to config shop.video.max_kb, the content is checked, not the file name), `poster` (optional) = a PNG,
 * JPG or WEBP picture up to shop.video.poster_max_kb. Also used with only a `poster` to replace the poster of an existing video.
 */
class UploadProductVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $posterOnly = $this->route('video') !== null;

        return [
            'video' => $posterOnly ? ['prohibited'] : ['required', 'file', 'mimetypes:video/mp4,video/webm', 'mimes:mp4,webm', 'max:'.(int) config('shop.video.max_kb')],
            'poster' => [$posterOnly ? 'required' : 'nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.(int) config('shop.video.poster_max_kb')],
        ];
    }

    public function attributes(): array
    {
        return ['video' => 'video', 'poster' => 'poster picture'];
    }

    /** The PHP upload error code of the video (UPLOAD_ERR_OK when it arrived whole, UPLOAD_ERR_NO_FILE when nothing arrived). */
    private function uploadError(): int
    {
        $file = $this->file('video');

        return $file instanceof UploadedFile ? $file->getError() : UPLOAD_ERR_NO_FILE;
    }

    /** Says why PHP refused the file (the generic "uploaded" message hides it), in words the owner can pass to the host. */
    private function uploadMessage(): string
    {
        $limits = 'upload_max_filesize = '.(ini_get('upload_max_filesize') ?: '?').', post_max_size = '.(ini_get('post_max_size') ?: '?');

        return match ($this->uploadError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "The server refused the file because it is larger than its limit ($limits). Ask your developer to raise them.",
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted: only a part of the file reached the server (a slow or unstable connection, or the host closed the connection). Try again; if it keeps happening, tell your developer.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads. Tell your developer.',
            UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'The server could not save the file (disk full, folder rights or a server module). Tell your developer.',
            default => 'The video could not be uploaded. Try again; if it keeps happening, tell your developer.',
        };
    }

    /** The file never arrived at all: PHP drops the whole request when it is larger than post_max_size, and then no file and no field is seen. */
    private function missingMessage(): string
    {
        $sent = (int) $this->server('CONTENT_LENGTH', 0);
        $max = ProductVideos::iniBytes('post_max_size');
        if ($sent > 0 && $max > 0 && $sent > $max) {
            return 'The server refused the file because it is larger than its limit (post_max_size = '.ini_get('post_max_size').'). Ask your developer to raise it.';
        }

        return $sent > 0 ? 'The file did not reach the server (the connection was closed before it arrived). Try again.' : 'Choose the video file.';
    }

    /** What the host's PHP did with the upload goes to storage/logs/laravel.log, so a failure on a live server can be explained afterwards. */
    protected function failedValidation(Validator $validator)
    {
        if ($this->route('video') === null && $validator->errors()->hasAny(['video'])) {
            $file = $this->file('video');
            Log::warning('Video upload refused', [
                'php_error' => $this->uploadError(), 'size' => $file instanceof UploadedFile ? $file->getSize() : null, 'content_length' => (int) $this->server('CONTENT_LENGTH', 0),
                'upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'), 'max_input_time' => ini_get('max_input_time'),
            ]);
        }

        parent::failedValidation($validator);
    }

    public function messages(): array
    {
        $mb = round((int) config('shop.video.max_kb') / 1024, 1);

        return [
            'video.required' => $this->missingMessage(),
            'video.mimetypes' => 'The video must be an MP4 or WEBM file.',
            'video.mimes' => 'The video must be an MP4 or WEBM file.',
            'video.max' => "The video must be $mb MB or smaller.",
            'video.uploaded' => $this->uploadMessage(),
            'poster.max' => 'The poster picture must be '.round((int) config('shop.video.poster_max_kb') / 1024).' MB or smaller.',
            'poster.mimes' => 'The poster must be a PNG, JPG or WEBP picture.',
            'poster.required' => 'Choose the poster picture.',
        ];
    }
}
