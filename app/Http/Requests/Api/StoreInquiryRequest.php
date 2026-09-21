<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fullname' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_channel' => ['required', 'string', 'in:website,email,facebook,instagram,whatsapp,phone'],
            'message' => ['required', 'string', 'max:5000'],
            'service' => ['nullable', 'string', 'max:255'],
            'budget' => ['nullable', 'string', 'max:255'],
        ];
    }
}
