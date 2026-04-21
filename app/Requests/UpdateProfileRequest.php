<?php

declare(strict_types=1);

namespace App\Requests;

use Fw\Validation\FormRequest;
use Fw\Validation\Rule;
use Fw\Validation\Rules\Email;
use Fw\Validation\Rules\MinLength;
use Fw\Validation\Rules\Required;

class UpdateProfileRequest extends FormRequest
{
    public string $name;

    public string $email;

    /** @return array<string, list<Rule>> */
    public function rules(): array
    {
        return [
            'name' => [new Required(), new MinLength(2)],
            'email' => [new Required(), new Email()],
        ];
    }
}
