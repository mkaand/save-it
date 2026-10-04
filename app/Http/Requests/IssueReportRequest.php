<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IssueReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'message' => ['required', 'string', 'min:1', 'max:5000'],
            'submitted_url' => ['nullable', 'string', 'max:2048'],
            'provider' => ['nullable', Rule::in(['youtube', 'youtube_shorts', 'x', 'instagram', 'linkedin', 'pinterest', 'tiktok', 'facebook', 'unknown'])],
            'error_code' => ['nullable', 'regex:/^[a-z0-9_]{1,64}$/'],
            'request_id' => ['nullable', 'regex:/^[A-Za-z0-9_-]{1,128}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['email', 'message', 'submitted_url', 'provider', 'error_code', 'request_id'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }
}
