<?php

namespace App\Http\Requests;

use App\Models\DocumentFolder;
use Illuminate\Foundation\Http\FormRequest;

class SaveDocumentFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $folder = $this->route('documentFolder');

        return $folder ? $this->user()->can($this->isMethod('DELETE') ? 'delete' : 'update', $folder) : $this->user()->can('create', DocumentFolder::class);
    }

    public function rules(): array
    {
        return $this->isMethod('DELETE') ? ['version' => ['required', 'integer', 'min:1']] : [
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\\x00-\\x1f\\x7f\\/\\\\\\\\]+$/u', function ($attribute, $value, $fail) {
                if (in_array(trim($value), ['', '.', '..'], true)) {
                    $fail('documents.validation.folderNameInvalid');
                }
            }],
            'parent_id' => ['present', 'nullable', 'uuid'],
            'version' => $this->route('documentFolder') ? ['required', 'integer', 'min:1'] : ['exclude'],
        ];
    }

    public function messages(): array
    {
        return ['name.*' => 'documents.validation.folderNameInvalid', 'parent_id.*' => 'documents.validation.folderInvalid', 'version.*' => 'documents.validation.versionInvalid'];
    }
}
