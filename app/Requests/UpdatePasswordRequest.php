<?php

declare(strict_types=1);

namespace App\Requests;

use Fw\Validation\FormRequest;
use Fw\Validation\Rule;
use Fw\Validation\Rules\Confirmed;
use Fw\Validation\Rules\MinLength;
use Fw\Validation\Rules\Required;

class UpdatePasswordRequest extends FormRequest
{
    public string $current_password;

    public string $password;

    public string $password_confirmation;

    /** @return array<string, list<Rule>> */
    public function rules(): array
    {
        return [
            'current_password' => [new Required()],
            'password' => [new Required(), new MinLength(8), new Confirmed()],
        ];
    }
}
