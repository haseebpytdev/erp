<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class GroupUmrahCommercialAmendmentService
{
    public function __construct(private readonly NativeSalesInvoiceInspector $salesInvoices) {}

    public function create(int $bookingId, array $data, ?int $userId): array
    {
        if (!Schema::hasTable('booking_group_package_commercial_amendments')) {
            throw ValidationException::withMessages([
                'amendment'=>'Run the current safe database upgrade first.'
            ]);
        }

        return DB::transaction(function () use ($bookingId,$data,$userId): array {
            $package = DB::table('booking_group_package_unified')
                ->where('booking_id',$bookingId)
                ->lockForUpdate()
                ->first();

            if (!$package) {
                throw ValidationException::withMessages([
                    'amendment'=>'Save the Group Umrah commercial package first.'
                ]);
            }

            $summary = $this->salesInvoices->summary($bookingId);

            if (($summary['count'] ?? 0) < 1) {
                throw ValidationException::withMessages([
                    'amendment'=>'No Sales Invoice exists yet. Change Adult / Child / Infant Booked Pax directly before creating the first invoice.'
                ]);
            }

            $adultAdd = max(0,(int)($data['additional_adult_pax'] ?? 0));
            $childAdd = max(0,(int)($data['additional_child_pax'] ?? 0));
            $infantAdd = max(0,(int)($data['additional_infant_pax'] ?? 0));
            $addPax = $adultAdd + $childAdd + $infantAdd;

            if ($addPax < 1) {
                throw ValidationException::withMessages([
                    'additional_pax'=>'Enter at least one additional Adult, Child or Infant pax.'
                ]);
            }

            /*
             * Additional capacity is a NEW commercial tranche. Current package
             * rates are defaults only; staff may enter new Customer/Vendor
             * per-pax rates and the amendment snapshots those exact values.
             */
            $adultSale = max(0,(float)($data['additional_adult_sale_price'] ?? $package->adult_sale_price ?? 0));
            $childSale = max(0,(float)($data['additional_child_sale_price'] ?? $package->child_sale_price ?? 0));
            $infantSale = max(0,(float)($data['additional_infant_sale_price'] ?? $package->infant_sale_price ?? 0));

            $adultCost = max(0,(float)($data['additional_adult_supplier_cost'] ?? $package->adult_supplier_cost ?? 0));
            $childCost = max(0,(float)($data['additional_child_supplier_cost'] ?? $package->child_supplier_cost ?? 0));
            $infantCost = max(0,(float)($data['additional_infant_supplier_cost'] ?? $package->infant_supplier_cost ?? 0));

            foreach ([
                ['Adult',$adultAdd,$adultSale],
                ['Child',$childAdd,$childSale],
                ['Infant',$infantAdd,$infantSale],
            ] as [$label,$count,$price]) {
                if ($count > 0 && $price <= 0) {
                    throw ValidationException::withMessages([
                        'amendment'=>"{$label} Customer Price / Pax is not configured. Correct the Commercial Summary before adding {$label} pax."
                    ]);
                }
            }

            $gross = round(
                ($adultAdd * $adultSale)
                + ($childAdd * $childSale)
                + ($infantAdd * $infantSale),
                2
            );

            $supplier = round(
                ($adultAdd * $adultCost)
                + ($childAdd * $childCost)
                + ($infantAdd * $infantCost),
                2
            );

            $discountType = (string)($data['discount_type'] ?? 'none');
            $discountValue = max(0,(float)($data['discount_value'] ?? 0));

            $discountAmount = match($discountType) {
                'percent'=>round($gross * min($discountValue,100)/100,2),
                'fixed'=>min($discountValue,$gross),
                default=>0,
            };

            $final = round(max(0,$gross-$discountAmount),2);
            $agent = max(0,(float)($data['additional_agent_commission'] ?? 0));
            $salesperson = max(0,(float)($data['additional_salesperson_commission'] ?? 0));
            $margin = round($final-$supplier-$agent-$salesperson,2);

            $previousAdult = max(0,(int)($package->booked_adult_pax ?? 0));
            $previousChild = max(0,(int)($package->booked_child_pax ?? 0));
            $previousInfant = max(0,(int)($package->booked_infant_pax ?? 0));

            $previousPax = max(
                1,
                (int)($package->booked_pax ?? ($previousAdult+$previousChild+$previousInfant))
            );

            $resultingAdult = $previousAdult + $adultAdd;
            $resultingChild = $previousChild + $childAdd;
            $resultingInfant = $previousInfant + $infantAdd;
            $resultingPax = $resultingAdult + $resultingChild + $resultingInfant;

            $latest = $summary['latest'] ?? null;
            $accountingAction = ($summary['has_posted'] ?? false)
                ? 'supplementary_invoice'
                : 'revise_existing_invoice';

            $amendmentNo = (
                (int)DB::table('booking_group_package_commercial_amendments')
                    ->where('booking_id',$bookingId)
                    ->max('amendment_no')
            ) + 1;

            $newGross = round(
                (float)($package->gross_sale_total ?? $package->package_sale_price ?? 0)
                + $gross,
                2
            );
            $newFinal = round(
                (float)($package->final_sale_total ?? $package->final_sale_price ?? 0)
                + $final,
                2
            );
            $newSupplier = round(
                (float)($package->supplier_cost_total ?? $package->supplier_cost ?? 0)
                + $supplier,
                2
            );
            $newDiscount = round(
                (float)($package->discount_amount_total ?? 0)
                + $discountAmount,
                2
            );
            $newAgent = round(
                (float)($package->agent_commission ?? 0)+$agent,
                2
            );
            $newSalesperson = round(
                (float)($package->salesperson_commission ?? 0)+$salesperson,
                2
            );
            $newMargin = round(
                (float)($package->net_margin_total ?? $package->net_margin ?? 0)
                + $margin,
                2
            );

            $now = now();

            $id = (int)DB::table(
                'booking_group_package_commercial_amendments'
            )->insertGetId([
                'booking_id'=>$bookingId,
                'amendment_no'=>$amendmentNo,
                'amendment_type'=>'add_pax',

                'previous_booked_pax'=>$previousPax,
                'additional_pax'=>$addPax,
                'resulting_booked_pax'=>$resultingPax,

                'previous_adult_pax'=>$previousAdult,
                'previous_child_pax'=>$previousChild,
                'previous_infant_pax'=>$previousInfant,
                'additional_adult_pax'=>$adultAdd,
                'additional_child_pax'=>$childAdd,
                'additional_infant_pax'=>$infantAdd,
                'resulting_adult_pax'=>$resultingAdult,
                'resulting_child_pax'=>$resultingChild,
                'resulting_infant_pax'=>$resultingInfant,

                'adult_sale_price_snapshot'=>$adultSale,
                'child_sale_price_snapshot'=>$childSale,
                'infant_sale_price_snapshot'=>$infantSale,
                'adult_supplier_cost_snapshot'=>$adultCost,
                'child_supplier_cost_snapshot'=>$childCost,
                'infant_supplier_cost_snapshot'=>$infantCost,

                // Existing columns remain aggregate amendment totals.
                'additional_sale_price'=>$gross,
                'additional_gross_sale_total'=>$gross,
                'discount_type'=>$discountType,
                'discount_value'=>$discountValue,
                'discount_amount'=>$discountAmount,
                'additional_final_sale'=>$final,
                'additional_supplier_cost'=>$supplier,
                'additional_agent_commission'=>$agent,
                'additional_salesperson_commission'=>$salesperson,
                'additional_net_margin'=>$margin,

                'vendor_reference'=>trim((string)($data['vendor_reference'] ?? '')) ?: null,
                'notes'=>trim((string)($data['notes'] ?? '')) ?: null,
                'accounting_action'=>$accountingAction,
                'accounting_status'=>'pending',
                'invoice_count_before'=>(int)($summary['count'] ?? 0),
                'invoice_total_before'=>$summary['total_amount'],
                'invoice_id_before'=>(int)($latest['id'] ?? 0) ?: null,
                'invoice_number_before'=>trim((string)($latest['number'] ?? '')) ?: null,
                'invoice_status_before'=>trim((string)($latest['status'] ?? '')) ?: null,
                'created_by'=>$userId,
                'created_at'=>$now,
                'updated_at'=>$now,
            ]);

            DB::table('booking_group_package_unified')
                ->where('booking_id',$bookingId)
                ->update([
                    'booked_adult_pax'=>$resultingAdult,
                    'booked_child_pax'=>$resultingChild,
                    'booked_infant_pax'=>$resultingInfant,
                    'booked_pax'=>$resultingPax,

                    'package_sale_price'=>$newGross,
                    'supplier_cost'=>$newSupplier,
                    'final_sale_price'=>$newFinal,
                    'net_margin'=>$newMargin,

                    'gross_sale_total'=>$newGross,
                    'supplier_cost_total'=>$newSupplier,
                    'discount_amount_total'=>$newDiscount,
                    'final_sale_total'=>$newFinal,
                    'net_margin_total'=>$newMargin,

                    'agent_commission'=>$newAgent,
                    'salesperson_commission'=>$newSalesperson,
                    'accounting_status'=>'amendment_pending',
                    'updated_at'=>$now,
                ]);

            $this->syncNativeBookingTotals($bookingId,[
                'booked_pax'=>$resultingPax,
                'final_sale_price'=>$newFinal,
                'supplier_cost'=>$newSupplier,
            ]);

            return [
                'id'=>$id,
                'amendment_no'=>$amendmentNo,
                'previous_booked_pax'=>$previousPax,
                'additional_pax'=>$addPax,
                'resulting_booked_pax'=>$resultingPax,
                'additional_adult_pax'=>$adultAdd,
                'additional_child_pax'=>$childAdd,
                'additional_infant_pax'=>$infantAdd,
                'resulting_adult_pax'=>$resultingAdult,
                'resulting_child_pax'=>$resultingChild,
                'resulting_infant_pax'=>$resultingInfant,
                'additional_gross_sale_total'=>$gross,
                'additional_final_sale'=>$final,
                'additional_supplier_cost'=>$supplier,
                'accounting_action'=>$accountingAction,
            ];
        });
    }

    public function state(int $bookingId, ?float $currentFinalSale=null): array
    {
        if (!Schema::hasTable('booking_group_package_commercial_amendments')) {
            return ['items'=>[],'pending'=>[],'pending_count'=>0,'accounting_current'=>true,'latest_pending'=>null];
        }

        $package = DB::table('booking_group_package_unified')->where('booking_id',$bookingId)->first();
        $target = $currentFinalSale ?? (float)($package->final_sale_price ?? 0);
        $summary = $this->salesInvoices->summary($bookingId);

        $items = DB::table('booking_group_package_commercial_amendments')
            ->where('booking_id',$bookingId)->orderBy('amendment_no')->get()
            ->map(fn($row): array => (array)$row)->all();

        foreach ($items as &$item) {
            $item['resolved'] = $this->isResolved($item,$summary,$target);
            if ($item['resolved'] && ($item['accounting_status'] ?? '') !== 'accounted') {
                try {
                    DB::table('booking_group_package_commercial_amendments')->where('id',$item['id'])->update([
                        'accounting_status'=>'accounted','accounted_at'=>now(),'updated_at'=>now()
                    ]);
                    $item['accounting_status']='accounted';
                } catch (\Throwable) {}
            }
        }
        unset($item);

        $pending = array_values(array_filter($items,fn(array $item): bool => !($item['resolved'] ?? false)));

        return [
            'items'=>$items,
            'pending'=>$pending,
            'pending_count'=>count($pending),
            'accounting_current'=>count($pending)===0,
            'latest_pending'=>$pending ? $pending[array_key_last($pending)] : null,
            'invoice_summary'=>$summary,
        ];
    }

    private function isResolved(array $amendment,array $summary,float $target): bool
    {
        if (($amendment['accounting_status'] ?? '') === 'accounted') return true;

        $count = (int)($summary['count'] ?? 0);
        $before = (int)($amendment['invoice_count_before'] ?? 0);
        $action = (string)($amendment['accounting_action'] ?? '');

        if ($action === 'supplementary_invoice' && $count <= $before) return false;

        if (($summary['amounts_known'] ?? false) && $summary['total_amount'] !== null) {
            return (float)$summary['total_amount'] + 0.01 >= $target;
        }

        if ($action === 'supplementary_invoice') return $count > $before;

        $latest = $summary['latest'] ?? null;
        if (!$latest) return false;
        $created = $amendment['created_at'] ?? null;
        $updated = $latest['updated_at'] ?? $latest['created_at'] ?? null;

        return $created && $updated ? strtotime((string)$updated) > strtotime((string)$created) : false;
    }

    private function syncNativeBookingTotals(int $bookingId,array $totals): void
    {
        if (!Schema::hasTable('bookings')) return;
        try {
            $columns = Schema::getColumnListing('bookings');
            $update=[];
            $this->put($update,$columns,['booked_pax','pax_count','passengers_count','quantity'],$totals['booked_pax'] ?? null);
            $this->put($update,$columns,['booking_value','total_amount','sale_amount','package_sale_amount'],$totals['final_sale_price'] ?? null);
            $this->put($update,$columns,['supplier_cost','forecast_supplier_cost','package_supplier_cost'],$totals['supplier_cost'] ?? null);
            if (in_array('updated_at',$columns,true)) $update['updated_at']=now();
            if ($update) DB::table('bookings')->where('id',$bookingId)->update($update);
        } catch (\Throwable) {}
    }

    private function put(array &$row,array $columns,array $candidates,mixed $value): void
    {
        if ($value===null || $value==='') return;
        foreach ($candidates as $column) {
            if (in_array($column,$columns,true)) { $row[$column]=$value; return; }
        }
    }
}
