<?php
namespace App\Support\Release;
use Illuminate\Support\Facades\File;
final class Erp11330StabilizationCleaner
{
    public function run(): void
    {
        try {
            $required=[resource_path('views/accounting/cash-vouchers/index.blade.php'),resource_path('views/accounting/cash-vouchers/form.blade.php'),resource_path('views/accounting/cash-vouchers/show.blade.php'),resource_path('views/accounting/cash-vouchers/print.blade.php'),resource_path('views/purchase/supplier-costing/index.blade.php'),resource_path('views/administration/erp-user-management.blade.php')];foreach($required as $file)if(!is_file($file))return;
            $marker=storage_path('app/erp11330-stabilization-cleanup.done');if(is_file($marker))return;
            $obsolete=[
                resource_path('views/accounting/cash-vouchers/index-v11316.blade.php'),resource_path('views/accounting/cash-vouchers/index-v11330.blade.php'),resource_path('views/accounting/cash-vouchers/form-v11317.blade.php'),resource_path('views/accounting/cash-vouchers/form-v11327.blade.php'),resource_path('views/accounting/cash-vouchers/show-v11317.blade.php'),resource_path('views/accounting/cash-vouchers/show-v11327.blade.php'),resource_path('views/accounting/cash-vouchers/print-v11317.blade.php'),resource_path('views/accounting/cash-vouchers/print-v11327.blade.php'),resource_path('views/accounting/advance-adjustments/form-v11330.blade.php'),resource_path('views/accounting/advance-adjustments/show-v11330.blade.php'),resource_path('views/purchase/supplier-costing/index-v11320.blade.php'),resource_path('views/purchase/supplier-costing/form-v11320.blade.php'),resource_path('views/purchase/supplier-costing/show-v11320.blade.php'),resource_path('views/administration/erp-user-management-v103173.blade.php'),resource_path('views/administration/erp-user-management-v103175.blade.php')
            ];foreach($obsolete as $file)if(is_file($file))File::delete($file);
            foreach([public_path('erp11326'),public_path('erp11327'),public_path('erp11328')] as $dir)if(is_dir($dir))File::deleteDirectory($dir);
            File::ensureDirectoryExists(dirname($marker));File::put($marker,'ERP-11.3.30 stabilization cleanup '.now()->toDateTimeString().PHP_EOL);
        } catch (\Throwable $e) { report($e); }
    }
}
