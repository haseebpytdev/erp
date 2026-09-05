<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\LegacyVisaTravelMasterRepository;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * ERP-11.3.152 — Visa Management uses EXISTING Travel Masters identities and the canonical native master route.
 *
 * Saudi Company and Pakistani IATA remain native Travel Masters records.
 * Only Visa Rate Cards are product-specific commercial master data.
 */
final class VisaMasterController extends Controller
{
    public function __construct(
        private readonly LegacyVisaTravelMasterRepository $masters,
        private readonly NativeErpLayoutResolver $layout,
    ) {
    }

    public function index(Request $request): View
    {
        $this->assertSchema();

        $iatas = $this->masters->pakistaniIatas();
        $saudis = $this->masters->saudiCompanies();
        $rates = $this->rateRows($saudis, $iatas);

        return view('operations.bookings.visa-masters-v113147', [
            'layoutMeta' => $this->layout->resolve(),
            'iatas' => $iatas,
            'saudis' => $saudis,
            'rates' => $rates,
            'returnBooking' => max(0, (int) $request->query('booking', 0)),
            'activeTab' => in_array((string) $request->query('tab', 'rates'), ['iata', 'saudi', 'rates'], true)
                ? (string) $request->query('tab', 'rates')
                : 'rates',
        ]);
    }

    /**
     * Compatibility endpoint only. Master creation stays on native Travel Masters.
     */
    public function storePakistaniIata(Request $request): RedirectResponse
    {
        return redirect('/master-data/travel-masters')->with(
            'visa_master_success',
            'Pakistani IATA is maintained in Travel Masters → Pakistan Visa / IATA. No duplicate Visa master was created.'
        );
    }

    /**
     * Compatibility endpoint only. Master creation stays on native Travel Masters.
     */
    public function storeSaudiCompany(Request $request): RedirectResponse
    {
        return redirect('/master-data/travel-masters')->with(
            'visa_master_success',
            'Saudi Company is maintained in Travel Masters → Saudi Visa Companies. No duplicate Visa master was created.'
        );
    }

    public function storeRate(Request $request): RedirectResponse
    {
        $this->assertSchema();
        $data = $request->validate([
            'country' => ['required', 'string', 'max:120'],
            'visa_type' => ['required', 'string', 'max:120'],
            'saudi_master_key' => ['required', 'string', 'max:255'],
            'cost_currency' => ['required', 'string', 'max:12'],
            'cost_rate' => ['required', 'numeric', 'min:0'],
            'default_sale_pkr' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $saudi = $this->masters->findSaudiByKey((string) $data['saudi_master_key']);
        abort_unless($saudi, 422, 'Selected Saudi Company was not found in the existing Travel Masters.');
        abort_unless((bool) ($saudi['is_active'] ?? true), 422, 'Selected Saudi Company is inactive in Travel Masters.');
        $relationshipStatus = (string) ($saudi['status'] ?? 'IATA LINK REQUIRED');
        abort_unless(
            (bool) ($saudi['link_complete'] ?? false),
            422,
            $relationshipStatus === 'VENDOR LINK REQUIRED'
                ? 'The linked Pakistani IATA has no valid Vendor Account. Complete that Vendor link in Travel Masters before adding a Visa Rate.'
                : 'The selected Saudi Company has no valid Pakistani IATA link. Complete that IATA link in Travel Masters before adding a Visa Rate.'
        );

        $now = now();
        $row = [
            'country' => trim((string) $data['country']),
            'visa_type' => trim((string) $data['visa_type']),
            // Compatibility IDs intentionally store the native Travel Master ids.
            'saudi_company_id' => (int) ($saudi['id'] ?? 0),
            'pakistani_iata_id' => (int) ($saudi['pakistani_iata_id'] ?? 0),
            'vendor_id' => (int) ($saudi['vendor_id'] ?? 0),
            'cost_currency' => $this->normalizeCurrency((string) $data['cost_currency']),
            'cost_rate' => round((float) $data['cost_rate'], 4),
            'default_sale_pkr' => round((float) $data['default_sale_pkr'], 2),
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'is_active' => (bool) $data['is_active'],
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'updated_at' => $now,
            'created_at' => $now,
        ];

        foreach ([
            'saudi_master_table' => (string) ($saudi['source_table'] ?? ''),
            'saudi_master_id' => (int) ($saudi['id'] ?? 0),
            'pakistani_iata_master_table' => (string) ($saudi['pakistani_iata_source_table'] ?? ''),
            'pakistani_iata_master_id' => (int) ($saudi['pakistani_iata_id'] ?? 0),
            'saudi_company_name_snapshot' => (string) ($saudi['name'] ?? ''),
            'pakistani_iata_name_snapshot' => (string) ($saudi['pakistani_iata_name'] ?? ''),
        ] as $column => $value) {
            if (Schema::hasColumn('visa_rate_cards', $column)) {
                $row[$column] = $value ?: null;
            }
        }

        DB::table('visa_rate_cards')->insert($row);

        return redirect()->route('travel-masters.visa-management', ['tab' => 'rates'])
            ->with('visa_master_success', 'Visa Rate saved using the existing Travel Masters Saudi Company → Pakistani IATA → Vendor chain.');
    }

    /** @param list<array<string,mixed>> $saudis @param list<array<string,mixed>> $iatas */
    private function rateRows(array $saudis, array $iatas): array
    {
        $saudiByKey = collect($saudis)->keyBy('master_key');
        $saudiById = collect($saudis)->groupBy('id');
        $iataById = collect($iatas)->groupBy('id');

        return DB::table('visa_rate_cards')
            ->orderByDesc('effective_from')->orderBy('country')->orderBy('visa_type')->get()
            ->map(function (object $object) use ($saudiByKey, $saudiById, $iataById): array {
                $row = (array) $object;
                $saudiKey = trim((string) ($row['saudi_master_table'] ?? '')) !== ''
                    ? (string) $row['saudi_master_table'] . ':' . (int) ($row['saudi_master_id'] ?? 0)
                    : '';
                $saudi = $saudiKey !== '' ? ($saudiByKey[$saudiKey] ?? null) : null;
                if (! $saudi) {
                    $matches = $saudiById[(int) ($row['saudi_company_id'] ?? 0)] ?? collect();
                    $saudi = $matches->first();
                }
                $iataMatches = $iataById[(int) ($row['pakistani_iata_id'] ?? 0)] ?? collect();
                $iata = $iataMatches->first();

                $row['saudi_company_name'] = trim((string) ($row['saudi_company_name_snapshot'] ?? ''))
                    ?: (string) (($saudi['name'] ?? '') ?: '—');
                $row['pakistani_iata_name'] = trim((string) ($row['pakistani_iata_name_snapshot'] ?? ''))
                    ?: (string) (($saudi['pakistani_iata_name'] ?? ($iata['name'] ?? '')) ?: '—');
                $row['vendor_name'] = (string) (($saudi['vendor_name'] ?? ($iata['vendor_name'] ?? '')) ?: '—');
                return $row;
            })->values()->all();
    }

    private function assertSchema(): void
    {
        abort_unless(
            Schema::hasTable('visa_rate_cards'),
            503,
            'Visa Rates are not installed yet. Open System Health & Updates and run Safe Database Upgrade, then Clear Application Cache.'
        );
    }

    private function normalizeCurrency(string $value): string
    {
        $value = strtoupper(trim($value));
        return in_array($value, ['SR', 'RIYAL', 'RIYALS', 'SAUDI RIYAL', 'SAUDI RIYALS'], true) ? 'SAR' : ($value ?: 'SAR');
    }
}
