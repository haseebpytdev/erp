@extends('layouts.app')
@section('title','Travel Voucher Diagnostic · '.$booking->booking_no)
@section('page_title','Travel Voucher Runtime Diagnostic')
@section('content')
<style>
.diag-wrap{max-width:1050px;margin:0 auto}.diag-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}
.diag-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:15px;margin-bottom:12px}
.diag-card h2,.diag-card h3{margin:0 0 7px}.diag-card p{color:var(--muted);font-size:10px}
.diag-table{width:100%;border-collapse:collapse;font-size:10px}.diag-table th,.diag-table td{border:1px solid var(--line);padding:8px;text-align:left}
.diag-table th{background:#f4f7fb}.ok{font-weight:900;color:#167148}.bad{font-weight:900;color:#b4232d}
.error-box{background:#fff4f4;border:1px solid #efc6c8;border-radius:10px;padding:10px;margin-top:8px}
.error-box code{white-space:pre-wrap;word-break:break-word;font-size:10px;color:#761d24}
</style>
<div class="diag-wrap">
    <div class="diag-head">
        <div>
            <h1 style="margin:0">Travel Voucher Runtime Diagnostic</h1>
            <p style="margin:4px 0 0;color:var(--muted)">Booking {{ $booking->booking_no }} · ERP-10.20.2</p>
        </div>
        <div style="display:flex;gap:7px">
            <a class="btn btn-secondary" href="{{ route('operations.bookings.show',$booking) }}">Booking</a>
            <a class="btn btn-primary" href="{{ route('operations.bookings.travel-voucher.diagnostic',$booking) }}">Run Diagnostic</a>
        </div>
    </div>

    <div class="diag-card">
        <h3>Runtime Stages</h3>
        <p>This page replaces the generic production 500 so the exact failed stage can be identified without SSH.</p>
        <table class="diag-table">
            <thead><tr><th>Stage</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody>
            @foreach($stages as $stage)
                <tr>
                    <td>{{ $stage['stage'] }}</td>
                    <td class="{{ $stage['ok']?'ok':'bad' }}">{{ $stage['ok']?'PASS':'FAIL' }}</td>
                    <td>{{ $stage['detail'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if($error)
    <div class="diag-card">
        <h3>Exact Exception</h3>
        <p>Send a screenshot of this box if the voucher still does not render. Full server paths and stack traces are intentionally not exposed.</p>
        @foreach($error as $index=>$e)
            <div class="error-box">
                <strong>{{ $index===0?'Primary':'Previous' }} Exception</strong><br>
                <code>{{ $e['class'] }}
{{ $e['message'] }}
{{ $e['file'] }} : line {{ $e['line'] }}</code>
            </div>
        @endforeach
    </div>
    @else
    <div class="diag-card">
        <h3 class="ok">All voucher stages passed.</h3>
        <a class="btn btn-success" href="{{ route('operations.bookings.travel-voucher.show',$booking) }}">Open Travel Voucher</a>
    </div>
    @endif
</div>
@endsection
