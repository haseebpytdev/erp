<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Operations\BookingProfitabilityAuthority;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class GroupUmrahProfitabilityController extends Controller
{
    public function __construct(
        private readonly BookingProfitabilityAuthority $authority,
        private readonly NativeErpLayoutResolver $layoutResolver,
    ) {}

    public function index(Request $request): View
    {
        $this->authority->authorize(
            $request->user()
        );

        $q=trim(
            (string) $request->query('q','')
        );

        $rows=$this->profitabilityRows(
            $q
        );

        $layout=$this->layoutResolver->resolve();

        return view(
            'reports.group-umrah-profitability-v103146',
            [
                'rows'=>$rows,
                'selected'=>null,
                'search'=>$q,
                'permissionKey'=>BookingProfitabilityAuthority::PERMISSION,
                'erpLayout'=>$layout['layout'],
                'erpContentSection'=>$layout['content_section'],
                'erpTitleSection'=>$layout['title_section'],
            ]
        );
    }

    public function show(
        Request $request,
        int $booking
    ): View {
        $this->authority->authorize(
            $request->user()
        );

        abort_unless(
            Schema::hasTable(
                'booking_group_package_unified'
            )
            && DB::table(
                'booking_group_package_unified'
            )
                ->where('booking_id',$booking)
                ->exists(),
            404
        );

        $rows=$this->profitabilityRows(
            '',
            100
        );

        $selected=$this->bookingProfitability(
            $booking
        );

        abort_unless(
            $selected,
            404
        );

        $layout=$this->layoutResolver->resolve();

        return view(
            'reports.group-umrah-profitability-v103146',
            [
                'rows'=>$rows,
                'selected'=>$selected,
                'search'=>'',
                'permissionKey'=>BookingProfitabilityAuthority::PERMISSION,
                'erpLayout'=>$layout['layout'],
                'erpContentSection'=>$layout['content_section'],
                'erpTitleSection'=>$layout['title_section'],
            ]
        );
    }

    private function profitabilityRows(
        string $q='',
        int $limit=150
    ): Collection {
        if (
            !Schema::hasTable(
                'booking_group_package_unified'
            )
        ) {
            return collect();
        }

        $query=DB::table(
            'booking_group_package_unified as g'
        );

        if (Schema::hasTable('bookings')) {
            $query->leftJoin(
                'bookings as b',
                'b.id',
                '=',
                'g.booking_id'
            );
        }

        if (
            $q!==''
            && Schema::hasTable('bookings')
        ) {
            $query->where(
                function ($builder) use ($q): void {
                    $builder->where(
                        'g.package_code',
                        'like',
                        '%'.$q.'%'
                    );

                    if (
                        Schema::hasColumn(
                            'g',
                            'package_name'
                        )
                    ) {
                        $builder->orWhere(
                            'g.package_name',
                            'like',
                            '%'.$q.'%'
                        );
                    }

                    foreach ([
                        'booking_reference',
                        'booking_no',
                        'booking_number',
                        'reference',
                        'code',
                    ] as $column) {
                        try {
                            if (
                                Schema::hasColumn(
                                    'bookings',
                                    $column
                                )
                            ) {
                                $builder->orWhere(
                                    'b.'.$column,
                                    'like',
                                    '%'.$q.'%'
                                );
                            }
                        } catch (\Throwable) {
                        }
                    }
                }
            );
        }

        try {
            $rows=$query
                ->select('g.*')
                ->orderByDesc('g.booking_id')
                ->limit($limit)
                ->get();
        } catch (\Throwable) {
            return collect();
        }

        return $rows
            ->map(
                fn ($row): ?array =>
                    $this->bookingProfitability(
                        (int) ($row->booking_id ?? 0),
                        (array) $row
                    )
            )
            ->filter()
            ->values();
    }

    private function bookingProfitability(
        int $bookingId,
        ?array $commercial=null
    ): ?array {
        if ($bookingId<=0) {
            return null;
        }

        if ($commercial===null) {
            try {
                $row=DB::table(
                    'booking_group_package_unified'
                )
                    ->where(
                        'booking_id',
                        $bookingId
                    )
                    ->first();
            } catch (\Throwable) {
                return null;
            }

            if (!$row) {
                return null;
            }

            $commercial=(array)$row;
        }

        $booking=$this->bookingRow(
            $bookingId
        );

        $finalSale=$this->moneyFrom(
            $commercial,
            [
                'final_sale_total',
                'final_sale_price',
                'package_sale_price',
                'gross_sale_total',
            ]
        );

        $grossSale=$this->moneyFrom(
            $commercial,
            [
                'gross_sale_total',
                'package_sale_price',
                'final_sale_total',
                'final_sale_price',
            ]
        );

        $supplierCost=$this->moneyFrom(
            $commercial,
            [
                'supplier_cost_total',
                'supplier_cost',
            ]
        );

        $discount=$this->moneyFrom(
            $commercial,
            [
                'discount_amount_total',
                'discount_amount',
            ]
        );

        $agentCommission=$this->moneyFrom(
            $commercial,
            [
                'agent_commission',
            ]
        );

        $salespersonCommission=$this->moneyFrom(
            $commercial,
            [
                'salesperson_commission',
            ]
        );

        $forecastNet=round(
            $finalSale
            - $supplierCost
            - $agentCommission
            - $salespersonCommission,
            2
        );

        $grossMargin=round(
            $finalSale-$supplierCost,
            2
        );

        $marginPct=$finalSale>0
            ? round(
                ($forecastNet/$finalSale)*100,
                2
            )
            : 0;

        $customerId=$this->firstPositive(
            $booking,
            [
                'customer_id',
                'party_id',
                'client_id',
                'customer_party_id',
            ]
        );

        $vendorId=$this->firstPositive(
            $commercial,
            [
                'vendor_id',
                'supplier_id',
            ]
        );

        $invoice=$this->invoiceSummary(
            $bookingId
        );

        return [
            'booking_id'=>$bookingId,
            'booking_reference'=>$this->bookingReference(
                $bookingId,
                $booking
            ),
            'package_code'=>trim(
                (string) (
                    $commercial['package_code']
                    ?? ''
                )
            ),
            'package_name'=>trim(
                (string) (
                    $commercial['package_name']
                    ?? ''
                )
            ),
            'currency'=>strtoupper(
                trim(
                    (string) (
                        $commercial['currency_code']
                        ?? $booking['currency_code']
                        ?? $booking['currency']
                        ?? 'PKR'
                    )
                )
            ) ?: 'PKR',
            'customer_id'=>$customerId,
            'customer_name'=>$this->customerName(
                $bookingId,
                $customerId
            ),
            'vendor_id'=>$vendorId,
            'vendor_name'=>$this->partyName(
                $vendorId,
                'Vendor'
            ),
            'booked_pax'=>max(
                0,
                (int) (
                    $commercial['booked_pax']
                    ?? 0
                )
            ),
            'gross_sale'=>$grossSale,
            'discount'=>$discount,
            'final_sale'=>$finalSale,
            'supplier_cost'=>$supplierCost,
            'gross_margin'=>$grossMargin,
            'agent_commission'=>$agentCommission,
            'salesperson_commission'=>$salespersonCommission,
            'forecast_net_profit'=>$forecastNet,
            'margin_pct'=>$marginPct,
            'profit_status'=>'forecast',
            'profit_status_label'=>'Forecast',
            'invoice'=>$invoice,
            'commercial_status'=>trim(
                (string) (
                    $commercial['accounting_status']
                    ?? ''
                )
            ),
        ];
    }

    private function bookingRow(
        int $bookingId
    ): array {
        if (!Schema::hasTable('bookings')) {
            return [];
        }

        try {
            return (array) (
                DB::table('bookings')
                    ->where('id',$bookingId)
                    ->first()
                ?? []
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function bookingReference(
        int $bookingId,
        array $booking
    ): string {
        foreach ([
            'booking_reference',
            'booking_no',
            'booking_number',
            'reference',
            'code',
        ] as $field) {
            $value=trim(
                (string) (
                    $booking[$field]
                    ?? ''
                )
            );

            if ($value!=='') {
                return $value;
            }
        }

        return 'BK-'.str_pad(
            (string) $bookingId,
            6,
            '0',
            STR_PAD_LEFT
        );
    }

    private function customerName(
        int $bookingId,
        int $customerId
    ): string {
        if (
            Schema::hasTable(
                'booking_group_umrah_contexts'
            )
        ) {
            try {
                $context=DB::table(
                    'booking_group_umrah_contexts'
                )
                    ->where(
                        'booking_id',
                        $bookingId
                    )
                    ->first();

                $name=trim(
                    (string) (
                        $context->customer_name
                        ?? ''
                    )
                );

                if ($name!=='') {
                    return $name;
                }
            } catch (\Throwable) {
            }
        }

        return $this->partyName(
            $customerId,
            'Customer'
        );
    }

    private function partyName(
        int $partyId,
        string $fallback
    ): string {
        if ($partyId<=0) {
            return $fallback.' not resolved';
        }

        foreach ([
            'parties',
            'party_masters',
            'customers',
            'vendors',
            'suppliers',
        ] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns=Schema::getColumnListing(
                    $table
                );
            } catch (\Throwable) {
                continue;
            }

            $idColumn=$this->firstColumn(
                $columns,
                [
                    'id',
                    'party_id',
                ]
            );

            $nameColumn=$this->firstColumn(
                $columns,
                [
                    'display_name',
                    'name',
                    'legal_name',
                    'company_name',
                    'title',
                ]
            );

            if (!$idColumn || !$nameColumn) {
                continue;
            }

            try {
                $row=DB::table($table)
                    ->where(
                        $idColumn,
                        $partyId
                    )
                    ->first();

                $name=trim(
                    (string) (
                        $row->{$nameColumn}
                        ?? ''
                    )
                );

                if ($name!=='') {
                    return $name;
                }
            } catch (\Throwable) {
            }
        }

        return $fallback.' #'.$partyId;
    }

    private function invoiceSummary(
        int $bookingId
    ): array {
        if (
            !Schema::hasTable(
                'booking_group_umrah_invoice_links'
            )
        ) {
            return [
                'number'=>null,
                'status'=>null,
                'id'=>null,
            ];
        }

        try {
            $link=DB::table(
                'booking_group_umrah_invoice_links'
            )
                ->where(
                    'booking_id',
                    $bookingId
                )
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable) {
            return [
                'number'=>null,
                'status'=>null,
                'id'=>null,
            ];
        }

        if (!$link) {
            return [
                'number'=>null,
                'status'=>null,
                'id'=>null,
            ];
        }

        $invoiceId=(int) (
            $link->invoice_id
            ?? 0
        );

        $number=trim(
            (string) (
                $link->invoice_number
                ?? ''
            )
        );

        $status='';

        if (
            $invoiceId>0
            && Schema::hasTable(
                'sales_invoices'
            )
        ) {
            try {
                $columns=Schema::getColumnListing(
                    'sales_invoices'
                );

                $row=DB::table(
                    'sales_invoices'
                )
                    ->where('id',$invoiceId)
                    ->first();

                if ($row) {
                    $a=(array)$row;

                    if ($number==='') {
                        foreach ([
                            'invoice_number',
                            'invoice_no',
                            'number',
                            'document_no',
                        ] as $field) {
                            if (
                                in_array(
                                    $field,
                                    $columns,
                                    true
                                )
                            ) {
                                $number=trim(
                                    (string) (
                                        $a[$field]
                                        ?? ''
                                    )
                                );

                                if ($number!=='') {
                                    break;
                                }
                            }
                        }
                    }

                    foreach ([
                        'status',
                        'invoice_status',
                        'document_status',
                    ] as $field) {
                        if (
                            in_array(
                                $field,
                                $columns,
                                true
                            )
                        ) {
                            $status=trim(
                                (string) (
                                    $a[$field]
                                    ?? ''
                                )
                            );
                            break;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        return [
            'id'=>$invoiceId ?: null,
            'number'=>$number ?: null,
            'status'=>$status ?: null,
        ];
    }

    private function moneyFrom(
        array $row,
        array $fields
    ): float {
        foreach ($fields as $field) {
            if (
                array_key_exists(
                    $field,
                    $row
                )
                && $row[$field]!==null
                && $row[$field]!==''
            ) {
                return round(
                    (float) $row[$field],
                    2
                );
            }
        }

        return 0.0;
    }

    private function firstPositive(
        array $row,
        array $fields
    ): int {
        foreach ($fields as $field) {
            $value=(int) (
                $row[$field]
                ?? 0
            );

            if ($value>0) {
                return $value;
            }
        }

        return 0;
    }

    private function firstColumn(
        array $columns,
        array $candidates
    ): ?string {
        foreach ($candidates as $candidate) {
            if (
                in_array(
                    $candidate,
                    $columns,
                    true
                )
            ) {
                return $candidate;
            }
        }

        return null;
    }
}
