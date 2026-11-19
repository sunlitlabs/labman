<?php

namespace App\Http\Requests;

use App\Rules\Check;
use App\Support\Access;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::isAdmin($this->user());
    }

    protected function prepareForValidation(): void
    {
        $ports = $this->input('ports');

        if (is_string($ports)) {
            $parts = array_filter(preg_split('/[\s,]+/', trim($ports)), fn ($p) => $p !== '');
            $this->merge(['ports' => array_values($parts)]);
        }

        $this->merge(['ssh_port' => $this->input('ssh_port') === null || $this->input('ssh_port') === '' ? 22 : $this->input('ssh_port')]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('servers', 'name')->ignore($this->route('server'))],
            'address' => ['required', 'string', 'max:253', Check::hostOrIp()],
            'domain' => ['nullable', 'string', 'max:253', Check::hostname()],
            'group' => ['nullable', 'string', 'max:60'],
            'ports' => ['nullable', 'array', 'max:50'],
            'ports.*' => ['integer', 'between:1,65535'],
            'ssh_user' => ['nullable', 'string', 'max:32', Check::sshUser()],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();
        $data['ports'] = array_values(array_unique(array_map('intval', $data['ports'] ?? [])));

        return $data;
    }
}
