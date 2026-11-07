<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Cart\AbandonedCarts;
use App\Domain\Customer\Models\Customer;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The customers list (read-only): contact records created by orders, and the abandoned carts tab. Contains personal data, hence its own permissions. */
class CustomerController extends Controller
{
    /** Paginated list, most recent buyers first. Query: ?q (name, phone or e-mail) ?page ?per_page (max 100). */
    public function index(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $customers = $this->query($request)->paginate((int) $request->query('per_page', 25))->withQueryString();

        return JsonResource::collection($customers->through(fn (Customer $c) => $this->row($c)));
    }

    /** CSV download of the (filtered) list: name, phone, email, city, orders count, total spent, last order date. */
    public function export(Request $request): StreamedResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        Audit::log($request->user(), 'customers.exported', null, ['q' => $request->query('q')]);

        $query = $this->query($request);

        return Csv::download('customers-'.now()->format('Y-m-d').'.csv',
            ['Name', 'Phone', 'Email', 'City', 'Orders count', 'Total spent', 'Last order date'],
            (function () use ($query) {
                foreach ($query->lazyById(500) as $c) {
                    yield [$c->name, $c->phone, $c->email, $c->city, $c->orders_count, $c->total_spent, $c->last_order_at?->toDateString()];
                }
            })());
    }

    /**
     * Abandoned carts: a saved phone or e-mail, items, no activity for two hours and no order since (see AbandonedCarts). ?q ?page ?per_page.
     * Needs `customers.view`; the cart token is never sent.
     */
    public function abandonedCarts(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $carts = AbandonedCarts::query($request->query('q'))->paginate((int) $request->query('per_page', 25))->withQueryString();

        return JsonResource::collection($carts->through(fn ($c) => AbandonedCarts::row($c)));
    }

    /** CSV of the abandoned carts: name, phone, e-mail, items, value, last activity. `customers.export`, audited. */
    public function exportAbandonedCarts(Request $request): StreamedResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        Audit::log($request->user(), 'customers.abandoned_carts_exported', null, ['q' => $request->query('q')]);
        $query = AbandonedCarts::query($request->query('q'));

        return Csv::download('abandoned-carts-'.now()->format('Y-m-d').'.csv',
            ['Name', 'Phone', 'Email', 'Items', 'Value', 'Last activity'],
            (function () use ($query) {
                foreach ($query->lazyById(500, 'carts.id', 'id') as $c) {
                    $r = AbandonedCarts::row($c);
                    yield [$r['name'], $r['phone'], $r['email'], $r['items'], $r['value'], $c->last_activity_at?->format('Y-m-d H:i')];
                }
            })());
    }

    private function query(Request $request): Builder
    {
        return Customer::query()->search($request->query('q'))->orderByRaw('last_order_at IS NULL')->orderByDesc('last_order_at')->orderByDesc('id');
    }

    private function row(Customer $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'city' => $c->city,
            'orders_count' => $c->orders_count, 'total_spent' => (float) $c->total_spent,
            'first_order_at' => $c->first_order_at, 'last_order_at' => $c->last_order_at,
        ];
    }
}
