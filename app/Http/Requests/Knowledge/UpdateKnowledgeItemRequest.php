<?php

namespace App\Http\Requests\Knowledge;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKnowledgeItemRequest extends FormRequest
{
    /**
     * Prepare request data for validation.
     */
    protected function prepareForValidation(): void
    {
        $tags = $this->input('tags');

        if (is_string($tags)) {
            $this->merge([
                'tags' => collect(explode(',', $tags))
                    ->map(fn (string $tag) => trim($tag))
                    ->filter(fn (string $tag) => $tag !== '')
                    ->values()
                    ->all(),
            ]);
        }
    }

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
            'title' => ['required', 'string', 'max:500'],
            'content_markdown' => ['required', 'string'],
            'category' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:60'],
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
            'title.required' => 'Please provide a title.',
            'content_markdown.required' => 'Content is required.',
            'tags.array' => 'Tags must be provided as a list.',
            'tags.*.string' => 'Each tag must be text.',
        ];
    }
}
