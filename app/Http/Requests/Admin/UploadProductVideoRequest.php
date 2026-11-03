<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

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

    public function messages(): array
    {
        $mb = round((int) config('shop.video.max_kb') / 1024);

        return [
            'video.required' => 'Choose the video file.',
            'video.mimetypes' => 'The video must be an MP4 or WEBM file.',
            'video.mimes' => 'The video must be an MP4 or WEBM file.',
            'video.max' => "The video must be $mb MB or smaller.",
            'video.uploaded' => "The video could not be uploaded. It may be larger than the server allows (limit $mb MB); ask your developer to raise upload_max_filesize and post_max_size.",
            'poster.max' => 'The poster picture must be '.round((int) config('shop.video.poster_max_kb') / 1024).' MB or smaller.',
            'poster.mimes' => 'The poster must be a PNG, JPG or WEBP picture.',
            'poster.required' => 'Choose the poster picture.',
        ];
    }
}
