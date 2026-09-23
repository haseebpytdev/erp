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
        return $this->view('report',TravelReportService::MOVEMENTS[$movement],$movement,$request,true);
    }
    public function export(Request $request,string $report): StreamedResponse {
        abort_unless(isset(TravelReportService::REPORTS[$report]),404); $service=$this->reports; $filters=$request->query();
        return response()->streamDownload(function() use($service,$report,$filters){ $out=fopen('php://output','w'); fputcsv($out,$service->definition($report)['columns']); foreach($service->exportRows($report,$filters) as $row){$values=[]; foreach((array)$row as $value){$v=(string)$value; $values[]=in_array($v[0]??'', ['=','+','-','@'],true)?"'".$v:$v;} fputcsv($out,$values);} fclose($out); },'travel-'.$report.'.csv',['Content-Type'=>'text/csv']);
    }
    private function view(string $name,string $title,?string $report=null,?Request $request=null,bool $movement=false): \Illuminate\View\View {
        $layout=$this->layoutResolver->resolve(); $data=['title'=>$title,'report'=>$report,'definition'=>$report?$this->reports->definition($report):null,'rows'=>$report&&$request?$this->reports->rows($report,$request->query(),(int)$request->query('per_page',50)):collect(),'counts'=>$this->reports->counts(),'reports'=>TravelReportService::REPORTS,'movements'=>TravelReportService::MOVEMENTS,'movement'=>$movement,'erpLayout'=>$layout['layout'],'erpContentSection'=>$layout['content_section'],'erpTitleSection'=>$layout['title_section']];
        return view('reports.travel.'.$name,$data);
    }
}
