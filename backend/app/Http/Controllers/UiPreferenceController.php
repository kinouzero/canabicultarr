<?php

namespace App\Http\Controllers;

use App\Services\UiPreferences;
use Illuminate\Http\Request;

class UiPreferenceController extends Controller
{
    public function index(Request $request, UiPreferences $preferences)
    {
        return response()->json(['data' => (object) $preferences->read($request->user())]);
    }

    public function update(Request $request, UiPreferences $preferences)
    {
        $data = $request->validate([
            'user_id' => ['prohibited'],
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*' => ['required', 'array:key,value'],
            'entries.*.key' => ['required', 'string', 'max:240', 'distinct', 'regex:/^(?:(?:sorting|disclosures|pagination|layout):[A-Za-z0-9:_-]+|(?:analysis|culture|measurements):[A-Za-z0-9:_-]+)$/D'],
            'entries.*.value' => ['present', 'nullable', function ($attribute, $value, $fail) {
                $valid = is_bool($value) || is_int($value) || is_float($value) || (is_string($value) && strlen($value) <= 1000)
                    || (is_array($value) && array_is_list($value) && count($value) <= 100 && collect($value)->every(fn ($item) => is_string($item) && strlen($item) <= 240));
                if (! $valid) {
                    $fail('collection.invalidPreference');
                }
            }],
        ]);
        $preferences->update($request->user(), $data['entries']);

        return response()->noContent();
    }
}
