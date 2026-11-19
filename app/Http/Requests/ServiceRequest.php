<?php

namespace App\Http\Requests;

use App\Domain\ServiceInput;
use App\Support\Access;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::isAdmin($this->user());
    }

    protected function prepareForValidation(): void
    {
        $prepared = ServiceInput::prepare($this->all(), form: true);

        $this->merge(array_intersect_key($prepared, array_flip(['path', 'expect', 'verify_tls', 'public_check', 'websockets', 'monitor', 'check_tcp'])));
    }

    public function rules(): array
    {
        return ServiceInput::rules($this->route('service'));
    }

    public function after(): array
    {
        return [fn (Validator $validator) => ServiceInput::exactlyOne($validator, $this->all())];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ServiceInput::payload($this->validated());
    }
}
