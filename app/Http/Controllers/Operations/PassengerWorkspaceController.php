<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\AdaptivePassengerMasterWriter;
use App\Services\Operations\UnifiedGroupPackageDataSource;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        })->take(100)->values()->map(function (array $row): array {
            $expiry = trim((string) ($row['passport_expiry'] ?? ''));
            $row['passport_status'] = '—';
            if ($expiry !== '') {
                try { $date = Carbon::parse($expiry); $row['passport_status'] = $date->isPast() ? 'Expired' : ($date->lte(now()->addDays(180)) ? 'Expiring Soon' : 'Valid'); } catch (\Throwable) {}
            }
            return $row;
        });
        $editPassenger = null;
        if ($request->filled('edit_source') && $request->filled('edit_id')) {
            $table = $this->safeSource((string) $request->query('edit_source'));
            if ($table && Schema::hasTable($table)) $editPassenger = $this->source->passengers()->first(fn (array $p) => $p['source_table'] === $table && (int) $p['id'] === (int) $request->query('edit_id'));
        }
        return view('operations.passengers.index', [
            'layoutMeta' => $this->layout->resolve(),
            'passengers' => $passengers,
            'query' => $query,
            'editPassenger' => $editPassenger,
        ]);
    }

    public function edit(string $source, int $passenger)
    {
        return $this->index(request()->merge(['edit_source' => $source, 'edit_id' => $passenger]));
    }

    public function update(Request $request, string $source, int $passenger)
    {
        $table = $this->safeSource($source);
        abort_unless($table && Schema::hasTable($table), 404);
        $data = $request->validate(['title'=>['nullable','string','max:20'],'sex'=>['nullable','string','max:20'],'first_name'=>['required','string','max:100'],'last_name'=>['required','string','max:100'],'passport_no'=>['required','string','max:100'],'nationality'=>['required','string','max:100'],'date_of_birth'=>['required','date'],'passport_expiry'=>['required','date'],'issuing_country'=>['nullable','string','max:100']]);
        $data['passport_no'] = strtoupper(preg_replace('/\s+/', '', trim($data['passport_no'])) ?? '');
        $columns = Schema::getColumnListing($table);
        $duplicate = DB::table($table)->where('id','<>',$passenger)->where(function($q) use ($columns,$data) { foreach (['passport_no','passport_number'] as $c) if(in_array($c,$columns,true)) $q->orWhereRaw('LOWER(`'.$c.'`) = ?', [strtolower($data['passport_no'])]); })->exists();
        if ($duplicate) return back()->withErrors(['passport_no'=>'Another Passenger Master record already uses this passport number.'])->withInput();
        $updates=[]; $fullName=trim($data['first_name'].' '.$data['last_name']); foreach ([['title','salutation','title'],['sex','gender','sex'],['first_name','given_name','first_name'],['last_name','surname','last_name'],['name','passenger_name','full_name'],['passport_no','passport_number','passport_no'],['date_of_birth','dob','date_of_birth'],['passport_expiry','passport_expiry_date','passport_expiry'],['nationality','nationality_name','nationality'],['issuing_country','passport_issuing_country','issuing_country']] as [$a,$b,$k]) { foreach([$a,$b] as $c) if(in_array($c,$columns,true)){ $updates[$c]=$k==='full_name'?$fullName:($k==='nationality'?$this->fitNationality($table,$c,$data[$k]):($data[$k]??null)); break; } }
        if (in_array('updated_at',$columns,true)) $updates['updated_at']=now(); DB::table($table)->where('id',$passenger)->update($updates);
        return redirect()->route('passengers.index')->with('passenger_success','Passenger updated.');
    }

    private function safeSource(string $source): ?string { return in_array($source, $this->source->passengerMasterTables(), true) ? $source : null; }
    private function fitNationality(string $table, string $column, string $value): string { $value=trim($value); try { $col=DB::selectOne('SHOW COLUMNS FROM `'.$table.'` LIKE ?',[$column]); if(preg_match('/(?:var)?char\((\d+)\)/i',(string)($col->Type??''),$m) && (int)$m[1]<=3){ $map=['PAKISTAN'=>'PK','PAKISTANI'=>'PK','SAUDI ARABIA'=>'SA','UNITED STATES'=>'US','UNITED KINGDOM'=>'GB']; return substr($map[strtoupper($value)]??strtoupper($value),0,(int)$m[1]); } } catch(\Throwable) {} return $value; }

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
