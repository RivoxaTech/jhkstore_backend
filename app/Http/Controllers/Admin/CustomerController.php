<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Models\Customer;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The customers list (read-only): contact records created by orders. Contains personal data, hence its own permissions. */
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

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 byte order mark, so Excel reads names with accents correctly
            fputcsv($out, ['Name', 'Phone', 'Email', 'City', 'Orders count', 'Total spent', 'Last order date']);
            foreach ($query->lazyById(500) as $c) {
                fputcsv($out, array_map([self::class, 'safe'], [
                    $c->name, $c->phone, $c->email, $c->city, $c->orders_count, $c->total_spent, $c->last_order_at?->toDateString(),
                ]));
            }
            fclose($out);
        }, 'customers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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

    /** A spreadsheet runs a cell that starts with = + - @ as a formula: a customer-typed name must not do that. */
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || preg_match('/^\+?[0-9][0-9 ()\-]*$/', $value)) {
            return $value;   // empty, or a plain phone number such as +49 170 1234567
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
