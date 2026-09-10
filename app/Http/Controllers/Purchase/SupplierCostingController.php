<?php

namespace App\Http\Controllers\Purchase;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Purchase\BookingSupplierObligationResolver;
use App\Services\Purchase\SupplierCostingDrilldownResolver;
use App\Services\Purchase\SupplierCostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SupplierCostingController extends Controller
{
    public function __construct(
        private readonly SupplierCostingService $service,
        private readonly BookingSupplierObligationResolver $obligations,
        private readonly SupplierCostingDrilldownResolver $drilldowns,
        private readonly NativeErpLayoutResolver $layout,
    ) {}

    public function index(Request $request)
    {
        $query = DB::table('supplier_costings')->orderByDesc('id');
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->filled('q')) {
            $term = '%'.$request->string('q').'%';
            $query->where(fn ($q) => $q->where('costing_no', 'like', $term)->orWhere('supplier_name', 'like', $term)->orWhere('supplier_invoice_no', 'like', $term)->orWhere('supplier_reference', 'like', $term));
        }
        return view('purchase.supplier-costing.index', ['rows' => $query->paginate(25)->withQueryString(), 'layoutMeta' => $this->layout->resolve()]);
    }

    public function create(Request $request)
    {
        return view('purchase.supplier-costing.form', $this->formData(null, $request));
    }

    public function store(Request $request)
    {
        if (! Schema::hasTable('supplier_costing_source_links')) {
            throw ValidationException::withMessages(['booking_id' => 'Supplier Costing source traceability is unavailable. Run the current database migrations before creating a booking-driven costing.']);
        }
        $header = $this->validateHeader($request, true);
        $submittedLines = $this->validateBookingLines($request);
        $id = DB::transaction(function () use ($header, $submittedLines, $request): int {
            DB::table('bookings')->where('id', (int) $header['booking_id'])->lockForUpdate()->first();
            $prepared = $this->prepareBookingLines((int) $header['booking_id'], (int) $header['supplier_id'], $submittedLines);
            $now = now();
            $id = DB::table('supplier_costings')->insertGetId(array_merge($this->bookingHeader($header, $prepared), [
                'costing_no' => $this->service->nextNumber(), 'status' => 'draft',
                'created_by' => $request->user()?->id, 'created_at' => $now, 'updated_at' => $now,
            ]));
            $this->replaceBookingLines($id, $prepared['lines']);
            $this->service->recalculate($id);
            $this->service->activity($id, 'create', null, 'draft', $request->user(), 'Booking-driven supplier costing created from authoritative vendor obligations.');
            return $id;
        });
        return redirect()->route('purchase.supplier-costing.show', $id)->with('success', 'Supplier costing draft created from booking obligations.');
    }

    public function show(int $costing)
    {
        $row = $this->find($costing);
        $lines = DB::table('supplier_costing_lines')->where('supplier_costing_id', $costing)->orderBy('line_no')->get();
        $postings = DB::table('supplier_costing_posting_lines')->where('supplier_costing_id', $costing)->orderBy('id')->get();
        $previewError = null;
        try {
            $accountingRows = $postings->isEmpty() ? collect($this->service->accountingPreview($costing)) : $postings;
        } catch (\Throwable $exception) {
            report($exception);
            $accountingRows = collect();
            $previewError = $exception->getMessage();
        }
        $accountUrls = [];
        foreach ($accountingRows->pluck('account_code')->filter()->unique() as $code) $accountUrls[(string) $code] = $this->drilldowns->accountLedgerUrl((string) $code);
        $sourceLinks = Schema::hasTable('supplier_costing_source_links')
            ? DB::table('supplier_costing_source_links')->where('supplier_costing_id', $costing)->get()->keyBy('supplier_costing_line_id')
            : collect();

        return view('purchase.supplier-costing.show', [
            'row' => $row, 'lines' => $lines, 'postings' => $postings, 'accountingRows' => $accountingRows,
            'sourceLinks' => $sourceLinks,
            'activities' => DB::table('supplier_costing_activities')->where('supplier_costing_id', $costing)->orderByDesc('id')->get(),
            'canApprove' => $this->service->canApprove(request()->user()),
            'bookingUrl' => $this->drilldowns->bookingUrl($row->booking_id ? (int) $row->booking_id : null),
            'supplierLedgerUrl' => $this->drilldowns->supplierLedgerUrl($row->supplier_id ? (int) $row->supplier_id : null),
            'journalUrl' => $this->drilldowns->journalUrl($costing), 'accountLedgerUrls' => $accountUrls,
            'previewError' => $previewError,
            'layoutMeta' => $this->layout->resolve(),
        ]);
    }

    public function edit(Request $request, int $costing)
    {
        $row = $this->find($costing);
        abort_unless($row->status === 'draft', 409, 'Only Draft supplier costing can be edited.');
        return view('purchase.supplier-costing.form', $this->formData($row, $request));
    }

    public function update(Request $request, int $costing)
    {
        $row = $this->find($costing);
        abort_unless($row->status === 'draft', 409, 'Only Draft supplier costing can be edited.');
        if (! $this->hasSourceLinks($costing)) return $this->updateLegacy($request, $row);

        $header = $this->validateHeader($request, true);
        $submittedLines = $this->validateBookingLines($request);
        DB::transaction(function () use ($costing, $header, $submittedLines, $request): void {
            DB::table('bookings')->where('id', (int) $header['booking_id'])->lockForUpdate()->first();
            $prepared = $this->prepareBookingLines((int) $header['booking_id'], (int) $header['supplier_id'], $submittedLines, $costing);
            DB::table('supplier_costings')->where('id', $costing)->update($this->bookingHeader($header, $prepared) + ['updated_at' => now()]);
            $this->replaceBookingLines($costing, $prepared['lines']);
            $this->service->recalculate($costing);
            $this->service->activity($costing, 'update', 'draft', 'draft', $request->user(), 'Booking-driven source lines refreshed from authoritative vendor obligations.');
        });
        return redirect()->route('purchase.supplier-costing.show', $costing)->with('success', 'Supplier costing draft refreshed from booking obligations.');
    }

    public function workflow(Request $request, int $costing, string $action)
    {
        abort_unless(in_array($action, ['submit', 'approve', 'post'], true), 404);
        try { $this->service->transition($costing, $action, $request->user()); }
        catch (\Throwable $e) { return back()->withErrors(['workflow' => $e->getMessage()]); }
        return back()->with('success', 'Supplier costing workflow updated.');
    }

    private function validateHeader(Request $request, bool $bookingDriven): array
    {
        $data = $request->validate([
            'booking_id' => [$bookingDriven ? 'required' : 'nullable', 'integer', 'min:1'],
            'supplier_id' => [$bookingDriven ? 'required' : 'nullable', 'integer', 'min:1'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'service_type' => [$bookingDriven ? 'nullable' : 'required', 'string', 'max:60'],
            'cost_date' => ['required', 'date'], 'due_date' => ['nullable', 'date', 'after_or_equal:cost_date'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:120'], 'supplier_reference' => ['nullable', 'string', 'max:190'],
            'currency_code' => ['required', 'string', 'max:10'], 'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'payment_terms' => ['nullable', 'string', 'max:80'], 'remarks' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['currency_code'] = strtoupper(trim((string) $data['currency_code']));
        if ($bookingDriven && ($data['currency_code'] !== 'PKR' || abs((float) $data['exchange_rate'] - 1.0) > 0.000000005)) {
            throw ValidationException::withMessages(['currency_code' => 'Booking product cost obligations are persisted in PKR; use PKR with exchange rate 1.']);
        }
        return $data;
    }

    private function validateBookingLines(Request $request): array
    {
        return $request->validate([
            'lines' => ['required', 'array', 'min:1'], 'lines.*.source_key' => ['required', 'string', 'max:190'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'], 'lines.*.other_charges' => ['nullable', 'numeric', 'min:0'],
        ])['lines'];
    }

    private function prepareBookingLines(int $bookingId, int $supplierId, array $submitted, ?int $excludeCostingId = null): array
    {
        $obligations = $this->obligations->resolve($bookingId, $excludeCostingId);
        $available = $this->obligations->availableForSupplier($obligations, $supplierId);
        if ($available === []) throw ValidationException::withMessages(['supplier_id' => 'This supplier has no available booking cost obligations.']);
        $submittedByKey = [];
        foreach ($submitted as $line) $submittedByKey[(string) $line['source_key']] = $line;
        $expectedKeys = array_column($available, 'source_key'); sort($expectedKeys);
        $submittedKeys = array_keys($submittedByKey); sort($submittedKeys);
        if ($expectedKeys !== $submittedKeys) throw ValidationException::withMessages(['lines' => 'Source obligations changed or an authoritative supplier line is missing. Reload the booking before saving.']);

        $products = []; $lines = [];
        foreach ($available as $source) {
            if ((int) $source['supplier_id'] !== $supplierId) throw ValidationException::withMessages(['supplier_id' => 'Different suppliers cannot be mixed in one Supplier Costing.']);
            $adjustment = $submittedByKey[$source['source_key']];
            $tax = round((float) ($adjustment['tax_amount'] ?? 0), 2); $other = round((float) ($adjustment['other_charges'] ?? 0), 2); $base = round((float) $source['source_cost'], 2);
            $products[$source['product_type']] = true;
            $lines[] = $source + ['base_cost' => $base, 'tax_amount' => $tax, 'other_charges' => $other, 'total_cost' => round($base + $tax + $other, 2)];
        }
        return ['supplier_name' => (string) $available[0]['supplier_name'], 'service_type' => count($products) === 1 ? (string) array_key_first($products) : 'Multiple Products', 'lines' => $lines];
    }

    private function bookingHeader(array $header, array $prepared): array
    {
        $header['supplier_name'] = $prepared['supplier_name']; $header['service_type'] = $prepared['service_type']; return $header;
    }

    private function replaceBookingLines(int $costingId, array $lines): void
    {
        DB::table('supplier_costing_source_links')->where('supplier_costing_id', $costingId)->delete();
        DB::table('supplier_costing_lines')->where('supplier_costing_id', $costingId)->delete();
        $now = now();
        foreach (array_values($lines) as $index => $line) {
            $lineId = DB::table('supplier_costing_lines')->insertGetId([
                'supplier_costing_id' => $costingId, 'line_no' => $index + 1,
                'service_type' => $line['product_type'], 'description' => $line['source_reference'],
                'passenger_id' => $line['passenger_id'], 'passenger_name' => $line['detail'] ?: $line['passenger_name'],
                'supplier_service_ref' => $line['source_reference'], 'base_cost' => $line['base_cost'],
                'tax_amount' => $line['tax_amount'], 'other_charges' => $line['other_charges'], 'total_cost' => $line['total_cost'],
                'source_snapshot_json' => json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('supplier_costing_source_links')->insert([
                'supplier_costing_id' => $costingId, 'supplier_costing_line_id' => $lineId,
                'booking_id' => $line['booking_id'], 'booking_service_id' => $line['booking_service_id'],
                'source_type' => $line['source_type'], 'source_id' => $line['source_id'], 'source_key' => $line['source_key'],
                'supplier_id' => $line['supplier_id'], 'product_type' => $line['product_type'], 'source_cost_snapshot' => $line['source_cost'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function updateLegacy(Request $request, object $row)
    {
        $header = $this->validateHeader($request, false);
        $lines = $request->validate([
            'lines' => ['required', 'array', 'min:1'], 'lines.*.service_type' => ['required', 'string', 'max:60'],
            'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.passenger_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.passenger_name' => ['nullable', 'string', 'max:255'], 'lines.*.supplier_service_ref' => ['nullable', 'string', 'max:190'],
            'lines.*.base_cost' => ['required', 'numeric', 'min:0'], 'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'], 'lines.*.other_charges' => ['nullable', 'numeric', 'min:0'],
        ])['lines'];
        DB::transaction(function () use ($row, $header, $lines, $request): void {
            DB::table('supplier_costings')->where('id', $row->id)->update($header + ['updated_at' => now()]);
            DB::table('supplier_costing_lines')->where('supplier_costing_id', $row->id)->delete();
            $now = now(); $insert = [];
            foreach (array_values($lines) as $i => $line) {
                $base = round((float) $line['base_cost'], 2); $tax = round((float) ($line['tax_amount'] ?? 0), 2); $other = round((float) ($line['other_charges'] ?? 0), 2);
                $insert[] = $line + ['supplier_costing_id' => $row->id, 'line_no' => $i + 1, 'base_cost' => $base, 'tax_amount' => $tax, 'other_charges' => $other, 'total_cost' => $base + $tax + $other, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('supplier_costing_lines')->insert($insert); $this->service->recalculate((int) $row->id);
            $this->service->activity((int) $row->id, 'update', 'draft', 'draft', $request->user(), 'Legacy Draft updated without source backfill.');
        });
        return redirect()->route('purchase.supplier-costing.show', $row->id)->with('success', 'Legacy supplier costing draft saved.');
    }

    private function formData(?object $row, Request $request): array
    {
        $legacy = $row !== null && ! $this->hasSourceLinks((int) $row->id);
        $bookingId = (int) ($row->booking_id ?? $request->integer('booking_id')); $supplierId = (int) ($row->supplier_id ?? $request->integer('supplier_id'));
        $obligations = $legacy || $bookingId <= 0 ? [] : $this->obligations->resolve($bookingId, $row ? (int) $row->id : null);
        $lines = $row ? DB::table('supplier_costing_lines')->where('supplier_costing_id', $row->id)->orderBy('line_no')->get() : collect();
        $lineAdjustments = [];
        if ($row && ! $legacy) {
            $links = DB::table('supplier_costing_source_links')->where('supplier_costing_id', $row->id)->get()->keyBy('supplier_costing_line_id');
            foreach ($lines as $line) if ($links->has($line->id)) $lineAdjustments[(string) $links[$line->id]->source_key] = ['tax_amount' => $line->tax_amount, 'other_charges' => $line->other_charges];
        }
        return [
            'row' => $row, 'legacyMode' => $legacy, 'bookings' => $this->service->bookingOptions(), 'obligations' => $obligations,
            'suppliers' => $legacy ? $this->service->supplierOptions() : $this->obligations->suppliers($obligations),
            'selectedBookingId' => $bookingId, 'selectedSupplierId' => $supplierId,
            'selectedObligations' => $legacy ? [] : $this->obligations->availableForSupplier($obligations, $supplierId),
            'lines' => $lines, 'lineAdjustments' => $lineAdjustments, 'layoutMeta' => $this->layout->resolve(),
        ];
    }

    private function hasSourceLinks(int $costingId): bool
    {
        return Schema::hasTable('supplier_costing_source_links') && DB::table('supplier_costing_source_links')->where('supplier_costing_id', $costingId)->exists();
    }

    private function find(int $id): object
    {
        $row = DB::table('supplier_costings')->where('id', $id)->first(); abort_unless($row, 404); return $row;
    }
}
