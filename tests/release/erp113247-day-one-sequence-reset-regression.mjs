import fs from 'node:fs';

const read = (path) => fs.readFileSync(path, 'utf8');

const service = read('app/Services/System/DayOneSequenceResetService.php');
const controller = read('app/Http/Controllers/System/ProductionDataResetController.php');
const view = read('resources/views/system/day-one-sequence-reset-v113247.blade.php');
const cash = read('app/Services/Accounting/CashVoucherService.php');
const supplier = read('app/Services/Purchase/SupplierCostingService.php');
const groupNumbers = read('app/Services/Operations/GroupUmrahDocumentNumberService.php');
const adaptiveBooking = read('app/Services/Operations/AdaptiveBookingWriter.php');
const generalVoucher = read('app/Http/Controllers/Operations/GeneralBookingVoucherPreviewController.php');
const unifiedGroup = read('app/Http/Controllers/Operations/UnifiedGroupPackageBookingController.php');
const profitability = read('app/Http/Controllers/Reports/GroupUmrahProfitabilityController.php');
const groupInvoice = read('app/Services/Operations/NativeSalesInvoiceDraftCreator.php');
const nativeNormalizer = read('app/Services/Sales/NativeSalesInvoiceNumberNormalizer.php');
const stableInvoice = read('app/Http/Controllers/Sales/StableBookingSalesInvoiceController.php');
const dayZero = read('app/Services/System/DayZeroExecutionService.php');
const version = read('VERSION.txt').trim();

function assert(name, condition) {
  if (!condition) {
    console.error(`FAIL ${name}`);
    process.exitCode = 1;
  } else {
    console.log(`PASS ${name}`);
  }
}

assert(
  'VERSION_STAYS_246_DURING_247_FUNCTIONAL_CHECKPOINT',
  version === 'v1.1.33.246-ERP11.3.246'
);

assert('DAY_ZERO_EXECUTION_AUTHORITY_PRESERVED', dayZero.includes('public const EXECUTION_ENABLED = true;'));
assert('DAY_ONE_REQUIRES_DAY_ZERO_COMPLETION', service.includes('$dayZeroCompleted = $this->planner->completedRecord();'));
assert('DAY_ONE_BLOCKS_NEW_PRODUCTION_ROWS', service.includes('CLEAR table now contains production rows and sequence reset is blocked'));

assert('FIRST_NUMBER_IS_1000', service.includes('public const FIRST_NUMBER = 1000;'));
assert('LAST_USED_BASELINE_IS_999', service.includes('public const LAST_USED_BASELINE = 999;'));
assert('MYSQL_NEXT_ID_1000', service.includes("AUTO_INCREMENT = '.self::FIRST_NUMBER"));
assert('POSTGRES_NEXT_ID_1000', service.includes("setval(?::regclass, '.self::FIRST_NUMBER"));
assert('SQLSERVER_BASELINE_999', service.includes("RESEED, '.self::LAST_USED_BASELINE"));
assert('SQLITE_BASELINE_999', service.includes("['seq' => self::LAST_USED_BASELINE]"));

assert('CASH_FIRST_SEQUENCE_1000', cash.includes(': 1000;'));
assert('CASH_NUMBER_NO_SIX_DIGIT_PAD', !cash.includes("str_pad((string) $seq, 6, '0', STR_PAD_LEFT)"));
assert('CASH_POSTING_NO_SIX_DIGIT_PAD', !cash.includes("str_pad((string) $voucherId, 6, '0', STR_PAD_LEFT)"));
assert('ADJUSTMENT_POSTING_NO_SIX_DIGIT_PAD', !cash.includes("str_pad((string) $adjustmentId, 6, '0', STR_PAD_LEFT)"));

assert('SUPPLIER_FIRST_SEQUENCE_1000', supplier.includes(':1000;return $prefix.(string)$seq;'));
assert('SUPPLIER_POSTING_NO_SIX_DIGIT_PAD', !supplier.includes("str_pad((string)$id,6,'0',STR_PAD_LEFT)"));

assert('GROUP_VOUCHER_PLAIN_ID', groupNumbers.includes("ET-UV-%04d-%d"));
assert('GROUP_BOOKING_PLAIN_ID', groupNumbers.includes("BK-%04d-%d"));

assert('BOOKING_SEQUENCE_FLOOR_1000', adaptiveBooking.includes('max(') && adaptiveBooking.includes('1000,'));
assert('BOOKING_REFERENCE_NO_SIX_DIGIT_PAD', !adaptiveBooking.includes("str_pad((string) ((int) DB::table('bookings')->max('id') + 1), 6"));

assert('GENERAL_VOUCHER_NO_SIX_DIGIT_PAD', !generalVoucher.includes("str_pad((string) $booking, 6, '0', STR_PAD_LEFT)"));
assert('UNIFIED_GROUP_NO_SIX_DIGIT_PAD', !unifiedGroup.includes("str_pad((string) $bookingId, 6, '0', STR_PAD_LEFT)"));
assert('PROFITABILITY_NO_SIX_DIGIT_PAD', !profitability.includes("str_pad("));

assert('GROUP_INVOICE_PLAIN_BOOKING_ID', groupInvoice.includes("$number = 'ET-SI-'.$year.'-'.(string) $bookingId;"));
assert('GROUP_INVOICE_NO_BOOKING_PAD', !groupInvoice.includes("str_pad(\n                (string) $bookingId"));
assert('GROUP_AMENDMENT_NO_ZERO_PAD', groupInvoice.includes("$number .= '-A'.(string) $amendmentId;"));

assert('NATIVE_SI_NORMALIZER_EXISTS', nativeNormalizer.includes("'/^(SI-[0-9]{4}-)([0-9]+)$/i'"));
assert('NATIVE_SI_NORMALIZER_MINIMUM_1000', nativeNormalizer.includes('$sequence < 1000'));
assert('NATIVE_SI_NORMALIZER_PLAIN_SEQUENCE', nativeNormalizer.includes('$matches[1].(string) $sequence'));
assert('STABLE_NATIVE_SI_USES_NORMALIZER', stableInvoice.includes('$this->invoiceNumbers->normalize($invoiceId);'));

assert('VIEW_EXPECTS_BOOKING_1000', view.includes('BK-{{ $year }}-1000'));
assert('VIEW_EXPECTS_NATIVE_INVOICE_1000', view.includes('SI-{{ $year }}-1000'));
assert('VIEW_EXPECTS_GROUP_INVOICE_1000', view.includes('ET-SI-{{ $year }}-1000'));
assert('VIEW_EXPECTS_GROUP_VOUCHER_1000', view.includes('ET-UV-{{ $year }}-1000'));
assert('VIEW_EXPECTS_RECEIPT_1000', view.includes('RV-{{ $year }}-1000'));
assert('VIEW_EXPECTS_PAYMENT_1000', view.includes('PV-{{ $year }}-1000'));
assert('VIEW_EXPECTS_SUPPLIER_COSTING_1000', view.includes('SC-{{ $year }}-1000'));
assert('VIEW_HAS_NO_000001', !view.includes('000001'));
assert('VIEW_HAS_NO_001000', !view.includes('001000'));

assert('EXACT_DAY_ONE_CONFIRMATION_PRESERVED', service.includes("public const CONFIRMATION = 'RESET DAY ONE SEQUENCES';"));
assert('DAY_ONE_CONTROLLER_PATH_PRESERVED', controller.includes('DayOneSequenceResetService'));

if (process.exitCode) {
  process.exit(process.exitCode);
}
