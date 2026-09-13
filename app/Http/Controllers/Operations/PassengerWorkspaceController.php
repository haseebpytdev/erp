<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\AdaptivePassengerMasterWriter;
use App\Services\Operations\UnifiedGroupPackageDataSource;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PassengerWorkspaceController extends Controller
{
    public function __construct(
        private readonly UnifiedGroupPackageDataSource $source,
        private readonly AdaptivePassengerMasterWriter $writer,
        private readonly NativeErpLayoutResolver $layout,
    ) {}

    public function index(Request $request)
    {
        $query = trim((string) $request->query('q', ''));
        $needle = Str::lower($query);
        $passengers = $this->source->passengers()->filter(function (array $row) use ($needle): bool {
            if ($needle === '') return true;
            return str_contains(Str::lower((string) ($row['name'] ?? '')), $needle)
                || str_contains(Str::lower((string) ($row['passport_no'] ?? '')), $needle);
        })->take(100)->values();
        return view('operations.passengers.index', [
            'layoutMeta' => $this->layout->resolve(),
            'passengers' => $passengers,
            'query' => $query,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:20'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'sex' => ['nullable', 'in:Male,Female,X,Unspecified'],
            'passport_no' => ['required', 'string', 'max:100'],
            'nationality' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date'],
            'passport_expiry' => ['required', 'date'],
            'issuing_country' => ['nullable', 'string', 'max:100'],
        ]);
        $data['passport_no'] = strtoupper(preg_replace('/\s+/', '', trim($data['passport_no'])) ?? '');
        if (($data['title'] ?? '') === '' && ($data['sex'] ?? '') === 'Male') $data['title'] = 'Mr';
        if (($data['title'] ?? '') === '' && ($data['sex'] ?? '') === 'Female') $data['title'] = 'Ms';
        $result = $this->writer->resolveStandalone($data);
        if (! empty($result['duplicate'])) {
            return back()->with('passenger_success', 'Passenger already exists. Existing saved passenger has been selected.')->withInput();
        }
        if (! $result['id']) return back()->withErrors(['passenger' => $result['warning'] ?? 'Passenger could not be saved.'])->withInput();
        return redirect()->route('passengers.index')->with('passenger_success', 'Passenger saved.');
    }
}
