<?php

namespace App\Http\Requests;

use App\Enums\RepositoryAuthType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProxySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'composer_enabled' => ['required', 'boolean'],
            'npm_enabled' => ['required', 'boolean'],
            'auth_type' => ['required', Rule::enum(RepositoryAuthType::class)],
            'auth_username' => ['nullable', 'required_if:auth_type,basic', 'string', 'max:255'],
            'auth_password' => ['nullable', 'required_if:auth_type,basic', 'string', 'max:255'],
            'auth_token' => ['nullable', 'required_if:auth_type,token', 'string', 'max:255'],
            'metadata_cache_ttl' => ['required', 'integer', 'min:60'],
        ];
    }
}
