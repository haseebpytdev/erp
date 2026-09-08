<?php

namespace App\Services\Operations;

use Illuminate\Database\Eloquent\Model;

final class BookingInvoiceEligibilityResolver
{
    public function resolve(array $booking): array
    {
        $approval = $this->value($booking, ['approval_status']);
        $approvalKey = $this->key($approval);
        if ($approval !== '') {
            return [
                'eligible'=>in_array($approvalKey, ['approved','confirmed'], true),
                'workflow'=>'approval',
                'status'=>$approval,
            ];
        }

        $legacy = $this->value($booking, ['status','booking_status','workflow_status','confirmation_status']);
        $confirmed = in_array($this->key($legacy), ['confirmed','approved','booked','final','finalized'], true)
            || (int)($booking['is_confirmed'] ?? 0) === 1
            || (int)($booking['confirmed'] ?? 0) === 1
            || !empty($booking['confirmed_at']);
        return ['eligible'=>$confirmed,'workflow'=>'legacy_confirmation','status'=>$legacy];
    }

    /** The native service must receive the persisted workflow state; this adapter does not alter the model in memory. */
    public function forNativeService(Model $booking): Model
    {
        return $booking;
    }

    private function value(array $row,array $fields):string{foreach($fields as $field){$value=trim((string)($row[$field]??''));if($value!=='')return $value;}return '';}
    private function key(string $value):string{return trim(preg_replace('/\s+/',' ',str_replace(['_','-'],' ',strtolower($value)))??'');}
}
