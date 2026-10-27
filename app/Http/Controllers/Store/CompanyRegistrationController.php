<?php

namespace App\Http\Controllers\Store;

use App\Domain\Company\Actions\CreateCompany;
use App\Domain\Company\Enums\CompanySource;
use App\Domain\Company\Models\Country;
use App\Domain\Company\Models\CompanyRegistrationType;
use App\Domain\Company\Models\VendorType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\RegisterCompanyRequest;
use Illuminate\Http\JsonResponse;

class CompanyRegistrationController extends Controller
{
    private function registrationTypeList()
    {
        return CompanyRegistrationType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
    }

    private function vendorTypeList()
    {
        return VendorType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
    }

    private function countryList()
    {
        return Country::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }

    /** Dropdown "Company registered as" only. `{ data: [{ id, name }] }` */
    public function registrationTypes(): JsonResponse
    {
        return response()->json(['data' => $this->registrationTypeList()]);
    }

    /** Dropdown "Vendor type" only. `{ data: [{ id, name }] }` */
    public function vendorTypes(): JsonResponse
    {
        return response()->json(['data' => $this->vendorTypeList()]);
    }

    /** Dropdown "Origin country" only (also used for a brand's country). `{ data: [{ id, code, name }] }` */
    public function countries(): JsonResponse
    {
        return response()->json(['data' => $this->countryList()]);
    }

    /** All three dropdowns + the form limits in one call (alternative to the three separate endpoints). */
    public function options(): JsonResponse
    {
        return response()->json([
            'registration_types' => $this->registrationTypeList(),
            'vendor_types' => $this->vendorTypeList(),
            'countries' => $this->countryList(),
            'rules' => [
                'promotion_support_min' => 200,
                'promotion_support_max' => 500,
                'logo_required' => true,
                'logo_mimes' => ['png', 'jpg', 'jpeg', 'webp'],
                'logo_max_kb' => 4096,
                'catalogue_required' => false,
                'catalogue_mimes' => ['png', 'jpg', 'jpeg', 'webp', 'pdf'],
                'catalogue_max_kb' => 10240,
                'catalogue_max_files' => 10,
            ],
        ]);
    }

    /**
     * Seller registration ("Sell on Dentalkart"). multipart/form-data; creates a pending company.
     * Required: all company/contact/address fields, `promotion_support` (200-500 chars), at least one brand and the company `logo`.
     * Optional: `catalogue[]` files, `contact_whatsapp`, `landmark`, brand country.
     */
    public function store(RegisterCompanyRequest $request, CreateCompany $createCompany): JsonResponse
    {
        $company = $createCompany->execute(
            data: collect($request->validated())->except(['brands', 'catalogue', 'logo'])->all(),
            brands: $request->validated('brands'),
            catalogue: $request->file('catalogue', []),
            source: CompanySource::PublicForm,
            logo: $request->file('logo'),
        );

        return response()->json([
            'message' => 'Your request has been submitted. Our team will get back to you within 7-10 business days.',
            'reference' => 'COMP-'.str_pad((string) $company->id, 6, '0', STR_PAD_LEFT),
            'status' => $company->status->value,
        ], 201);
    }
}
