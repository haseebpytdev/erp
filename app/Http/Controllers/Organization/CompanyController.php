<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Currency;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class CompanyController extends Controller
{
    public function edit(Request $request): View
    {
        return view('organization.company', [
            'company' => Company::query()->firstOrFail(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // ERP-09.6 added these columns. A stale/no-SSH deployment must not fail with a raw 500.
        if (!Schema::hasColumn('companies', 'website') || !Schema::hasColumn('companies', 'logo_data_uri')) {
            return back()->withErrors([
                'company' => 'Report Header database fields are not ready yet. Run the ERP-09.6 Company Profile repair once, then save again.',
            ])->withInput();
        }

        $company = Company::query()->firstOrFail();
        $old = $company->toArray();

        $data = $request->validate([
            'name' => ['required','string','max:150'],
            'legal_name' => ['nullable','string','max:190'],
            'code' => ['required','string','max:20'],
            'tax_registration_no' => ['nullable','string','max:80'],
            'base_currency' => ['required','exists:currencies,code'],
            'timezone' => ['required','string','max:64'],
            'country_code' => ['required','string','size:2'],
            'email' => ['nullable','email','max:150'],
            'phone' => ['nullable','string','max:60'],
            'address' => ['nullable','string','max:1500'],
            'website' => ['nullable','string','max:190'],
            'report_logo' => ['nullable','file','max:1024','mimetypes:image/jpeg,image/png,image/webp'],
            'remove_report_logo' => ['nullable','boolean'],
        ]);

        unset($data['report_logo'], $data['remove_report_logo']);
        $data['code'] = strtoupper($data['code']);
        $data['country_code'] = strtoupper($data['country_code']);
        if (!empty($data['website']) && !preg_match('~^https?://~i', $data['website'])) {
            $data['website'] = 'https://'.$data['website'];
        }

        try {
            if ($request->boolean('remove_report_logo')) {
                $data['logo_data_uri'] = null;
            } elseif ($request->hasFile('report_logo')) {
                $file = $request->file('report_logo');
                $path = $file?->getRealPath();
                $raw = $path ? @file_get_contents($path) : false;
                if ($raw === false || $raw === '') {
                    return back()->withErrors(['report_logo' => 'The uploaded logo could not be read. Please try a JPG or PNG file under 1 MB.'])->withInput();
                }

                $mime = strtolower((string)($file?->getMimeType() ?: ''));
                $gdReady = function_exists('imagecreatefromstring')
                    && function_exists('imagecreatetruecolor')
                    && function_exists('imagecopyresampled')
                    && function_exists('imagejpeg');

                if ($gdReady) {
                    $image = @imagecreatefromstring($raw);
                    if (!$image) {
                        return back()->withErrors(['report_logo' => 'The uploaded image format could not be processed. Please use a standard JPG or PNG.'])->withInput();
                    }

                    $width = imagesx($image);
                    $height = imagesy($image);
                    $maxW = 420;
                    $maxH = 180;
                    $scale = min(1, $maxW / max(1, $width), $maxH / max(1, $height));
                    $newW = max(1, (int) round($width * $scale));
                    $newH = max(1, (int) round($height * $scale));
                    $canvas = imagecreatetruecolor($newW, $newH);
                    $white = imagecolorallocate($canvas, 255, 255, 255);
                    imagefilledrectangle($canvas, 0, 0, $newW, $newH, $white);
                    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
                    ob_start();
                    $ok = imagejpeg($canvas, null, 88);
                    $jpeg = (string) ob_get_clean();
                    imagedestroy($canvas);
                    imagedestroy($image);

                    if (!$ok || $jpeg === '') {
                        return back()->withErrors(['report_logo' => 'The server could not prepare the logo for reports. Please try another JPG image.'])->withInput();
                    }
                    $data['logo_data_uri'] = 'data:image/jpeg;base64,'.base64_encode($jpeg);
                } elseif ($mime === 'image/jpeg') {
                    // Safe fallback for hosts where GD is disabled: direct JPG storage still works in PDF output.
                    $data['logo_data_uri'] = 'data:image/jpeg;base64,'.base64_encode($raw);
                } else {
                    return back()->withErrors([
                        'report_logo' => 'This server cannot convert PNG/WEBP images. Please upload the logo as JPG/JPEG (max 1 MB).',
                    ])->withInput();
                }
            }

            $company->update($data);

            // Do not duplicate the base64 logo into the audit log; preserve the fact that it changed instead.
            $new = $company->fresh()->toArray();
            $oldHadLogo = !empty($old['logo_data_uri']);
            $newHadLogo = !empty($new['logo_data_uri']);
            unset($old['logo_data_uri'], $new['logo_data_uri']);
            $old['report_logo_present'] = $oldHadLogo;
            $new['report_logo_present'] = $newHadLogo;
            AuditService::log($request, 'company.updated', $company, $old, $new);

            return back()->with('status', 'Company profile updated. Printable reports will use the Company Profile and selected Branch contact details.');
        } catch (Throwable $e) {
            report($e);
            return back()->withErrors([
                'company' => 'Company Profile could not be saved: '.$e->getMessage(),
            ])->withInput();
        }
    }
}
