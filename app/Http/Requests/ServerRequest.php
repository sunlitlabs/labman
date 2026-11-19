<?php

namespace App\Http\Requests;

use App\Domain\ServerInput;
use App\Support\Access;
use Illuminate\Foundation\Http\FormRequest;

class ServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::isAdmin($this->user());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_intersect_key(ServerInput::prepare($this->all()), array_flip(['ports', 'ssh_port', 'monitor', 'check_ssh'])));
    }

    public function rules(): array
    {
        return ServerInput::rules($this->route('server'));
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ServerInput::payload($this->validated());
    }
}
