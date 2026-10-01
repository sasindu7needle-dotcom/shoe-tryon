<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTryOnEventRequest;
use App\Models\TryOnEvent;
use Illuminate\Http\Response;

class TryOnEventController extends Controller
{
    public function store(StoreTryOnEventRequest $request): Response
    {
        TryOnEvent::create($request->validated());

        return response()->noContent();
    }
}
