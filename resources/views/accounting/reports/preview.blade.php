@extends('layouts.app')
@section('title',$report['title'].' Preview')
@section('page_title','Report Preview')
@section('content')
<div class="page-intro"><div><span class="eyebrow">PRINTABLE PREVIEW</span><h1>{{ $report['title'] }}</h1><p>Web references are clickable and open the original ERP source document or journal.</p></div><div class="action-row"><a class="btn btn-ghost" href="{{ route('accounting.reports.index',request()->query()) }}">Back to Reports</a><a class="btn btn-ghost" target="_blank" href="{{ route('accounting.reports.print',request()->query()) }}">Print</a><a class="btn btn-ghost" href="{{ route('accounting.reports.pdf',request()->query()) }}">Export PDF</a><a class="btn btn-ghost" href="{{ route('accounting.reports.excel',request()->query()) }}">Export Excel</a></div></div>
@include('accounting.reports.partials.report-table',['report'=>$report])
@endsection
