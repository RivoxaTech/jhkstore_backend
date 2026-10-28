<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Company\Models\Brand;
use App\Domain\Company\Models\Company;
use App\Domain\Support\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Numbers and lists for the admin dashboard. Three small aggregate queries + two short lists,
     * so it stays fast however many companies exist.
     */
    public function __invoke(): JsonResponse
    {
        $byStatus = Company::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $brands = Brand::query()->selectRaw('COUNT(*) as total, COALESCE(SUM(is_active), 0) as active')->first();

        $recentPending = Company::query()
            ->where('status', 'pending')
            ->latest('submitted_at')
            ->limit(6)
            ->get(['id', 'name', 'email', 'city', 'source', 'submitted_at']);

        $activity = AuditLog::query()->latest('id')->limit(8)->get();
        $adminNames = Admin::whereIn('id', $activity->where('actor_type', 'admin')->pluck('actor_id')->filter()->unique())->pluck('name', 'id');
        $companyNames = Company::withTrashed()->whereIn('id', $activity->where('subject_type', 'Company')->pluck('subject_id')->filter()->unique())->pluck('name', 'id');
        $brandNames = Brand::withTrashed()->whereIn('id', $activity->where('subject_type', 'Brand')->pluck('subject_id')->filter()->unique())->pluck('name', 'id');
        $productNames = Product::withTrashed()->whereIn('id', $activity->where('subject_type', 'Product')->pluck('subject_id')->filter()->unique())->pluck('name', 'id');
        $tagNames = Tag::whereIn('id', $activity->where('subject_type', 'Tag')->pluck('subject_id')->filter()->unique())->pluck('name', 'id');

        return response()->json([
            'companies' => [
                'total' => (int) $byStatus->sum(),
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'approved' => (int) ($byStatus['approved'] ?? 0),
                'rejected' => (int) ($byStatus['rejected'] ?? 0),
                'suspended' => (int) ($byStatus['suspended'] ?? 0),
            ],
            'brands' => [
                'total' => (int) $brands->total,
                'active' => (int) $brands->active,
            ],
            'recent_pending' => $recentPending,
            'recent_activity' => $activity->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor_type === 'admin' ? ($adminNames[$log->actor_id] ?? 'Admin') : ucfirst($log->actor_type ?? 'system'),
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'subject_name' => match ($log->subject_type) {
                    'Company' => $companyNames[$log->subject_id] ?? null,
                    'Brand' => $brandNames[$log->subject_id] ?? null,
                    'Product' => $productNames[$log->subject_id] ?? null,
                    'Tag' => $tagNames[$log->subject_id] ?? null,
                    default => null,
                },
                'created_at' => $log->created_at,
            ])->values(),
        ]);
    }
}
