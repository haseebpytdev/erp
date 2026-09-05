<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\GroupUmrahCommercialAmendmentService;
use App\Services\Operations\GroupUmrahWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class GroupUmrahCommercialAmendmentController extends Controller
{
    public function __construct(
        private readonly GroupUmrahCommercialAmendmentService $amendments,
        private readonly GroupUmrahWorkflowService $workflow,
    ) {}

    public function store(Request $request,int $booking): JsonResponse
    {
        abort_unless(Schema::hasTable('bookings') && DB::table('bookings')->where('id',$booking)->exists(),404);
        $this->workflow->assertEditable($booking);

        $data=$request->validate([
            'additional_adult_pax'=>['nullable','integer','min:0','max:9999'],
            'additional_child_pax'=>['nullable','integer','min:0','max:9999'],
            'additional_infant_pax'=>['nullable','integer','min:0','max:9999'],

            'additional_adult_sale_price'=>['nullable','numeric','min:0'],
            'additional_child_sale_price'=>['nullable','numeric','min:0'],
            'additional_infant_sale_price'=>['nullable','numeric','min:0'],
            'additional_adult_supplier_cost'=>['nullable','numeric','min:0'],
            'additional_child_supplier_cost'=>['nullable','numeric','min:0'],
            'additional_infant_supplier_cost'=>['nullable','numeric','min:0'],

            'discount_type'=>['required',Rule::in(['none','fixed','percent'])],
            'discount_value'=>['nullable','numeric','min:0'],
            'additional_agent_commission'=>['nullable','numeric','min:0'],
            'additional_salesperson_commission'=>['nullable','numeric','min:0'],
            'vendor_reference'=>['nullable','string','max:120'],
            'notes'=>['nullable','string','max:2000'],
        ]);

        $additionalPax =
            max(0,(int)($data['additional_adult_pax'] ?? 0))
            + max(0,(int)($data['additional_child_pax'] ?? 0))
            + max(0,(int)($data['additional_infant_pax'] ?? 0));

        if ($additionalPax < 1) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'additional_pax'=>'Enter at least one additional Adult, Child or Infant pax.',
            ]);
        }

        $result=$this->amendments->create($booking,$data,$request->user()?->id);

        return response()->json([
            'ok'=>true,
            'message'=>sprintf(
                'Pax amendment #%d saved: +%d Adult, +%d Child, +%d Infant. Booked Pax increased from %d to %d. Accounting adjustment is now required.',
                $result['amendment_no'],
                $result['additional_adult_pax'],
                $result['additional_child_pax'],
                $result['additional_infant_pax'],
                $result['previous_booked_pax'],
                $result['resulting_booked_pax']
            ),
            'amendment'=>$result,
        ]);
    }
}
