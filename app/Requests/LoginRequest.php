<?php

declare(strict_types=1);

namespace App\Requests;

use Fw\Validation\FormRequest;
use Fw\Validation\Rule;
use Fw\Validation\Rules\Email;
use Fw\Validation\Rules\Required;

class LoginRequest extends FormRequest
{
    public string $email;

    public string $password;

    /** @return array<string, list<Rule>> */
    public function rules(): array
    {
        return [
            'email' => [new Required(), new Email()],
            'password' => [new Required()],
        ];
    }
}
