<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Rules\DocumentFile;
use App\Services\DocumentStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('document') ? $this->user()->can('update', $this->route('document')) : $this->user()->can('create', Document::class);
    }

    public function rules(): array
    {
        $updating = $this->route('document') !== null;

        return [
            'disk' => $updating ? ['prohibited'] : ['sometimes', Rule::in(app(DocumentStorage::class)->disks())],
            'folder_id' => ['sometimes', 'nullable', 'uuid'],
            'title' => [$updating ? 'sometimes' : 'required', 'required', 'string', 'max:160'], 'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'file' => $updating ? ['prohibited'] : ['bail', 'required', 'file', new DocumentFile],
            'version' => $updating ? ['required', 'integer', 'min:1', 'max:2147483647'] : ['exclude'],
            'is_archived' => $updating ? ['sometimes', 'boolean'] : ['exclude'],
            'subject' => ['sometimes', 'nullable', 'array:type,id', 'required_array_keys:type,id'],
            'subject.type' => ['required_with:subject', Rule::in(['site', 'room', 'zone', 'asset', 'sensor', 'event', 'strain'])], 'subject.id' => ['required_with:subject', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return ['disk.*' => 'documents.validation.storageInvalid', 'folder_id.*' => 'documents.validation.folderInvalid', 'title.*' => 'documents.validation.titleInvalid', 'description.*' => 'documents.validation.descriptionInvalid', 'file.*' => 'documents.validation.fileInvalid',
            'version.*' => 'documents.validation.versionInvalid', 'is_archived.*' => 'documents.validation.stateInvalid', 'subject.*' => 'documents.validation.subjectInvalid', 'subject.type.*' => 'documents.validation.subjectInvalid', 'subject.id.*' => 'documents.validation.subjectInvalid'];
    }
}
