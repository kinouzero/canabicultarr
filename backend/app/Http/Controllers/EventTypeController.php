<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveEventTypeRequest;
use App\Models\EventType;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\EventTypes;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventTypeController extends Controller
{
    public function index(Request $request, EventTypes $types)
    {
        $criteria = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'state' => ['sometimes', Rule::in(['all', 'active', 'archived'])], 'sort' => CollectionSorting::rules(EventTypes::SORT_COLUMNS), 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => CollectionPagination::rules()]);
        $page = $types->search($criteria);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()], 'icons' => EventTypes::ICONS, 'contexts' => EventTypes::CONTEXTS, 'canManage' => $request->user()->can('instance-settings.manage')]);
    }

    public function store(SaveEventTypeRequest $request, EventTypes $types)
    {
        return response()->json(['data' => $types->save($request->user(), $request->validated())], 201);
    }

    public function update(SaveEventTypeRequest $request, EventType $eventType, EventTypes $types)
    {
        return response()->json(['data' => $types->save($request->user(), $request->validated(), $eventType)]);
    }
}
