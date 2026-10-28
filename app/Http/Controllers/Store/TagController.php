<?php

namespace App\Http\Controllers\Store;

use App\Domain\Catalog\Models\Tag;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TagController extends Controller
{
    /** Active tags, e.g. to build the homepage sections (slug is what `?tag=` expects). */
    public function index(): JsonResponse
    {
        $tags = Tag::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['name', 'slug', 'color', 'style']);

        return response()->json(['data' => $tags]);
    }
}
