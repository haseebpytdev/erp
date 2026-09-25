<?php
namespace App\Http\Controllers\Reports;
use App\Http\Controllers\Controller;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Reports\TravelReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TravelReportsController extends Controller
{
    public function __construct(private readonly TravelReportService $reports, private readonly NativeErpLayoutResolver $layoutResolver) {}
    public function index(): \Illuminate\View\View { return $this->view('index', 'Travel Reports'); }
    public function show(Request $request,string $report): \Illuminate\View\View {
        abort_unless(isset(TravelReportService::REPORTS[$report]),404);
        return $this->view('report',TravelReportService::REPORTS[$report],$report,$request);
    }
    public function movement(Request $request,string $movement): \Illuminate\View\View {
        abort_unless(isset(TravelReportService::MOVEMENTS[$movement]),404);
        // Arrival uses the dedicated reports.travel.movements.arrival view;
        // Departure now uses the matching dedicated movement presentation.
        if ($movement === 'arrival' || $movement === 'departure') {
            $layoutMeta=$this->layoutResolver->resolve();
            return view('reports.travel.movements.'.($movement==='arrival'?'arrival':'departure'),['layoutMeta'=>$layoutMeta,'title'=>$movement==='arrival'?'Arrival Report':'Departure Intimation','report'=>$movement,'definition'=>$this->reports->definition($movement),'rows'=>$this->reports->rows($movement,$request->query(),(int)$request->query('per_page',50)),'movements'=>TravelReportService::MOVEMENTS]);
        }
        return $this->view('report',TravelReportService::MOVEMENTS[$movement],$movement,$request,true);
    }
    public function export(Request $request,string $report): StreamedResponse {
        abort_unless(isset(TravelReportService::REPORTS[$report]),404); $service=$this->reports; $filters=$request->query();
        return response()->streamDownload(function() use($service,$report,$filters){ $out=fopen('php://output','w'); $columns=$service->definition($report)['columns']; fputcsv($out,array_column($columns,'label')); foreach($service->streamRows($report,$filters) as $row){$values=[]; foreach($columns as $column){$v=(string)($row[$column['key']]??'—');$values[]=in_array($v[0]??'', ['=','+','-','@'],true)?"'".$v:$v;} fputcsv($out,$values);} fclose($out); },'travel-'.$report.'.csv',['Content-Type'=>'text/csv']);
    }
    public function exportMovement(Request $request,string $movement): StreamedResponse {
        abort_unless(isset(TravelReportService::MOVEMENTS[$movement]),404); $service=$this->reports; $filters=$request->query();
        return response()->streamDownload(function() use($service,$movement,$filters){ $out=fopen('php://output','w'); $columns=$service->definition($movement)['columns']; fputcsv($out,array_column($columns,'label')); foreach($service->streamRows($movement,$filters) as $row){$values=[]; foreach($columns as $column){$v=(string)($row[$column['key']]??'—');$values[]=in_array($v[0]??'', ['=','+','-','@'],true)?"'".$v:$v;} fputcsv($out,$values);} fclose($out); },'travel-'.$movement.'.csv',['Content-Type'=>'text/csv']);
    }
    private function view(string $name,string $title,?string $report=null,?Request $request=null,bool $movement=false): \Illuminate\View\View {
        $layoutMeta=$this->layoutResolver->resolve(); $data=['title'=>$title,'report'=>$report,'definition'=>$report?$this->reports->definition($report):null,'rows'=>$report&&$request?$this->reports->rows($report,$request->query(),(int)$request->query('per_page',50)):collect(),'counts'=>$this->reports->counts(),'reports'=>TravelReportService::REPORTS,'movements'=>TravelReportService::MOVEMENTS,'movement'=>$movement,'layoutMeta'=>$layoutMeta];
        return view('reports.travel.'.$name,$data);
    }
}
