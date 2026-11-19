<?php

namespace App\Http\Requests;

use App\Rules\Check;
use App\Support\Access;
use App\Support\ExpectRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::isAdmin($this->user());
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'path' => $this->input('path') === null || $this->input('path') === '' ? '/' : $this->input('path'),
            'expect' => $this->input('expect') === null || $this->input('expect') === '' ? ExpectRange::DEFAULT : $this->input('expect'),
            'verify_tls' => $this->boolean('verify_tls'),
            'public_check' => $this->boolean('public_check'),
            'websockets' => $this->boolean('websockets'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('services', 'name')->ignore($this->route('service'))],
            'domain' => ['nullable', 'string', 'max:253', Check::hostname()],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'address' => ['nullable', 'string', 'max:253', Check::hostOrIp()],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'scheme' => ['required', Rule::in(['http', 'https'])],
            'path' => ['required', 'string', 'max:500', Check::path()],
            'group' => ['nullable', 'string', 'max:60'],
            'expect' => ['required', Check::expect()],
            'verify_tls' => ['boolean'],
            'public_check' => ['boolean'],
            'websockets' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $hasServer = filled($this->input('server_id'));
                $hasAddress = filled($this->input('address'));

                if ($hasServer === $hasAddress) {
                    $validator->errors()->add('server_id', "Choose a server or enter an address — exactly one of the two.");
                }
            },
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();
        $data['expect'] = (string) ExpectRange::parse($data['expect']);
        $data['server_id'] = filled($data['server_id'] ?? null) ? (int) $data['server_id'] : null;
        $data['address'] = $data['server_id'] ? null : ($data['address'] ?? null);

        return $data;
    }
}
