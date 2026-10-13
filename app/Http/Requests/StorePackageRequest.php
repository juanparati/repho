<?php

namespace App\Http\Requests;

use App\Enums\Ecosystem;
use App\Enums\PackageType;
use App\Models\Package;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ecosystem' => $this->input('ecosystem', Ecosystem::Composer->value),
            'download_dists' => $this->boolean('download_dists'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ecosystem' => ['required', Rule::enum(Ecosystem::class)],
            'name' => [
                'exclude_unless:ecosystem,'.Ecosystem::Npm->value,
                'required',
                'string',
                'max:214',
                'regex:/^'.Package::NPM_NAME_PATTERN.'$/',
                Rule::unique('packages')->where('ecosystem', Ecosystem::Npm->value),
            ],
            'repository_url' => ['required', 'url', 'max:500', 'regex:#^https?://.+/.+#'],
            'type' => ['required', Rule::enum(PackageType::class)],
            'credential_id' => ['nullable', 'exists:credentials,id'],
            'download_dists' => ['boolean'],
            'repository_ids' => ['required', 'array', 'min:1'],
            'repository_ids.*' => ['exists:repositories,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The npm package name is required (as declared in package.json).',
            'name.regex' => 'The npm package name must be lowercase and URL-safe, e.g. my-package or @scope/my-package.',
            'name.unique' => 'An npm package with this name already exists.',
        ];
    }
}
