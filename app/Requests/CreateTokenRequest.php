<?php

declare(strict_types=1);

namespace App\Requests;

use Fw\Validation\FormRequest;
use Fw\Validation\Rule;
use Fw\Validation\Rules\Required;

class CreateTokenRequest extends FormRequest
{
    public string $name;

    /** @return array<string, list<Rule>> */
    public function rules(): array
    {
        return [
            'name' => [new Required()],
        ];
    }
}
