<?php

namespace App\Http\Requests\Knowledge;

use Illuminate\Foundation\Http\FormRequest;

class KnowledgeSearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'query' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'string', 'max:500'],
            'include_drafts' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'limit.max' => 'Limit cannot be greater than 10.',
            'query.max' => 'Search query is too long.',
        ];
    }
}
