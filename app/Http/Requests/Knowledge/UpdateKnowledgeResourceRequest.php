<?php

namespace App\Http\Requests\Knowledge;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKnowledgeResourceRequest extends FormRequest
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
            'type' => ['required', 'in:link,file'],
            'label' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'required_if:type,link'],
            'storage_path' => ['nullable', 'string', 'max:2048', 'required_if:type,file'],
            'mime' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'integer', 'min:0'],
            'extracted_text' => ['nullable', 'string'],
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
            'type.required' => 'Please select a resource type.',
            'url.required_if' => 'A URL is required for link resources.',
            'storage_path.required_if' => 'A storage path is required for file resources.',
        ];
    }
}
