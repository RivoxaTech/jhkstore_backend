<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Wholesale\Actions\UpdateWholesaleRequest;
use App\Domain\Wholesale\Models\WholesaleRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateWholesaleRequestRequest;
use App\Http\Resources\WholesaleRequestResource;
use App\Support\Audit;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WholesaleController extends Controller
{
    /** Paginated, newest first. ?status ?search ?per_page; `counts` = requests per status (for the tabs, same search). */
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(WholesaleRequest::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $searched = fn (): Builder => WholesaleRequest::query()->search($request->query('search'));

        $rows = $searched()->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate((int) $request->query('per_page', 25))->withQueryString();

        return WholesaleRequestResource::collection($rows)->additional([
            'counts' => $searched()->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function show(WholesaleRequest $wholesale): WholesaleRequestResource
    {
        return new WholesaleRequestResource($wholesale);
    }

    public function update(UpdateWholesaleRequestRequest $request, WholesaleRequest $wholesale, UpdateWholesaleRequest $update): WholesaleRequestResource
    {
        return new WholesaleRequestResource($update->handle($wholesale, $request->validated(), $request->user()));
    }

    /** The number behind the sidebar badge: requests that are still `new`. */
    public function newCount(): JsonResponse
    {
        return response()->json(['count' => WholesaleRequest::query()->where('status', 'new')->count()]);
    }

    /** CSV of the (filtered) list: reference, date, status, name, phone, e-mail, business, product, quantity, message, note. */
    public function export(Request $request): StreamedResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(WholesaleRequest::STATUSES)], 'search' => ['nullable', 'string', 'max:100']]);
        Audit::log($request->user(), 'wholesale.exported', null, $request->only(['status', 'search']));

        $query = WholesaleRequest::query()->search($request->query('search'))->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('id');

        return Csv::download('wholesale-requests-'.now()->format('Y-m-d').'.csv',
            ['Reference', 'Date', 'Status', 'Name', 'Phone', 'Email', 'Business', 'Product', 'Quantity', 'Message', 'Note'],
            (function () use ($query) {
                foreach ($query->lazyById(500) as $r) {
                    yield [$r->reference, $r->created_at?->format('Y-m-d H:i'), $r->status, $r->name, $r->phone, $r->email, $r->business_name, $r->product_name, $r->quantity, $r->message, $r->admin_note];
                }
            })());
    }
}
