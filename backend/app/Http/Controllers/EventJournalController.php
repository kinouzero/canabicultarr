<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListEventsRequest;
use App\Http\Requests\SaveEventRequest;
use App\Http\Resources\EventEntryResource;
use App\Http\Resources\EventRevisionResource;
use App\Models\EventEntry;
use App\Services\EventJournal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EventJournalController extends Controller
{
    public function index(ListEventsRequest $request, EventJournal $journal)
    {
        return EventEntryResource::collection($journal->search($request->validated()));
    }

    public function store(SaveEventRequest $request, EventJournal $journal)
    {
        return (new EventEntryResource($journal->save($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function show(Request $request, EventEntry $event)
    {
        Gate::authorize('view', $event);

        return new EventEntryResource($event->load('author:id,uuid,name'));
    }

    public function update(SaveEventRequest $request, EventEntry $event, EventJournal $journal)
    {
        return new EventEntryResource($journal->save($request->user(), $request->validated(), $event));
    }

    public function revisions(ListEventsRequest $request, EventEntry $event, EventJournal $journal)
    {
        Gate::authorize('view', $event);

        return EventRevisionResource::collection($journal->revisions($event, $request->validated()));
    }
}
