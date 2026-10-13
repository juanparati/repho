<?php

namespace App\Http\Requests;

use App\Enums\Ecosystem;
use App\Enums\RepositoryAuthType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProxyUpstreamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ecosystem' => $this->input('ecosystem', Ecosystem::Composer->value),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ecosystem' => ['required', Rule::enum(Ecosystem::class)],
            'name' => ['required', 'string', 'max:255'],
            'upstream_url' => ['required', 'url', 'max:255'],
            'auth_type' => ['required', Rule::enum(RepositoryAuthType::class)],
            'auth_username' => ['nullable', 'required_if:auth_type,basic', 'string', 'max:255'],
            'auth_password' => ['nullable', 'required_if:auth_type,basic', 'string', 'max:255'],
            'auth_token' => ['nullable', 'required_if:auth_type,token', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
