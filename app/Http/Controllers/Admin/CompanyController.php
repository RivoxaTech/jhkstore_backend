<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Company\Actions\CreateCompany;
use App\Domain\Company\Actions\ReviewCompany;
use App\Domain\Company\Enums\CompanySource;
use App\Domain\Company\Enums\CompanyStatus;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\CompanyRegistrationType;
use App\Domain\Company\Models\Country;
use App\Domain\Company\Models\VendorType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectCompanyRequest;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Http\Resources\CompanyListResource;
use App\Http\Resources\CompanyResource;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CompanyController extends Controller
{
    private const WITH = ['registrationType', 'vendorType', 'originCountry'];

    /** Dropdown data for the admin company form (same options as the public form). */
    public function options(): JsonResponse
    {
        $named = fn ($model) => $model::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'registration_types' => $named(CompanyRegistrationType::class),
            'vendor_types' => $named(VendorType::class),
            'countries' => Country::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'statuses' => array_column(CompanyStatus::cases(), 'value'),
        ]);
    }

    /**
     * Companies list. Filters: ?status=pending|approved|rejected|suspended ?source=public_form|admin
     * ?q=name/GST/email ?per_page=1..100. Newest first; counts include pending requests.
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_column(CompanyStatus::cases(), 'value'))],
            'source' => ['nullable', Rule::in(array_column(CompanySource::cases(), 'value'))],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Lean list: only what the table shows (logo, names, contact, a few brand names). No N+1 queries.
        $companies = Company::query()
            ->with([...self::WITH, 'media', 'brands:id,company_id,name,sort_order'])
            ->withCount('brands')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->query('q'), function ($q, $term) {
                $q->where(fn ($w) => $w->where('name', 'like', "%$term%")
                    ->orWhere('vat_gst_number', 'like', "%$term%")
                    ->orWhere('email', 'like', "%$term%"));
            })
            ->latest('id')
            ->paginate((int) $request->query('per_page', 20))
            ->withQueryString();

        return CompanyListResource::collection($companies)->additional([
            'counts' => Company::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    /** Admin creates a company directly (auto-approved). multipart/form-data. `logo` is required. */
    public function store(StoreCompanyRequest $request, CreateCompany $createCompany): JsonResponse
    {
        $company = $createCompany->execute(
            data: collect($request->validated())->except(['brands', 'catalogue', 'logo'])->all(),
            brands: $request->validated('brands'),
            catalogue: $request->file('catalogue', []),
            source: CompanySource::Admin,
            admin: $request->user(),
            logo: $request->file('logo'),
        );

        return (new CompanyResource($company->load('media')))->response()->setStatusCode(201);
    }

    /** Company detail with its brands and catalogue files. */
    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company->load([...self::WITH, 'brands.media', 'brands.originCountry', 'reviewer', 'media']));
    }

    // (detail loads brands with `chaperone`, so a brand without a logo can show its company's logo without extra queries)

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $data = collect($request->validated())->except(['catalogue', 'logo'])->all();
        if (isset($data['vat_gst_number'])) {
            $data['vat_gst_number'] = strtoupper(trim($data['vat_gst_number']));
        }
        $company->fill($data)->save();

        if ($request->hasFile('logo')) {
            $company->addMedia($request->file('logo'))->toMediaCollection('logo'); // replaces the old logo
        }
        foreach ($request->file('catalogue', []) as $file) {
            $company->addMedia($file)->toMediaCollection('catalogue');
        }
        Audit::log($request->user(), 'company.updated', $company, $company->getChanges());

        return new CompanyResource($company->fresh([...self::WITH, 'brands.media', 'reviewer', 'media']));
    }

    public function approve(Request $request, Company $company, ReviewCompany $review): CompanyResource
    {
        return new CompanyResource($review->approve($company, $request->user()));
    }

    public function reject(RejectCompanyRequest $request, Company $company, ReviewCompany $review): CompanyResource
    {
        return new CompanyResource($review->reject($company, $request->user(), $request->validated('reason')));
    }

    public function suspend(Request $request, Company $company, ReviewCompany $review): CompanyResource
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        return new CompanyResource($review->suspend($company, $request->user(), $reason));
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        if (DB::table('products')->where('company_id', $company->id)->exists()) {
            return response()->json(['message' => 'This company has products. Suspend it instead of deleting.'], 422);
        }
        $company->brands()->delete();
        $company->delete();
        Audit::log($request->user(), 'company.deleted', $company);

        return response()->json(['message' => 'Company deleted.']);
    }

    /** Private catalogue download (admin token required). */
    public function catalogue(Company $company, int $media): BinaryFileResponse
    {
        $file = $company->getMedia('catalogue')->firstWhere('id', $media);
        abort_unless($file, 404);

        return response()->download($file->getPath(), $file->file_name);
    }
}
