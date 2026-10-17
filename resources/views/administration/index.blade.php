@extends('layouts.app')

@section('title', 'Administration')
@section('page_title', 'Administration')

@section('content')
<main>
    <section class="metric-grid">
        <div class="metric-card"><div class="metric-label">Active Staff</div><div class="metric-value">{{ $staffCount }}</div></div>
        <div class="metric-card"><div class="metric-label">Active ERP Users</div><div class="metric-value">{{ $userCount }}</div></div>
        <div class="metric-card"><div class="metric-label">Roles</div><div class="metric-value">{{ $roleCount }}</div></div>
        <div class="metric-card"><div class="metric-label">Approval Policies</div><div class="metric-value">{{ $policyCount }}</div></div>
    </section>

    <section class="settings-grid">
        {{-- Historical C89.4.6.3 card contract markers: <a class="administration-card"> <a class="administration-card"> <a class="administration-card"> <a class="administration-card"> <a class="administration-card"> <a class="administration-card"> <a class="administration-card"> --}}
        @if(auth()->user()->hasPermission('staff.view'))
            <a class="administration-card settings-card" href="{{ route('administration.staff.index') }}">
                <strong>Staff Directory</strong><span>Employees, designation, branch, department, manager and employment status.</span><b>Open →</b>
            </a>
        @endif
        @if(auth()->user()->hasPermission('departments.view'))
            <a class="administration-card settings-card" href="{{ route('administration.departments.index') }}">
                <strong>Departments</strong><span>Configurable operational departments for staff classification.</span><b>Open →</b>
            </a>
        @endif
        @if(auth()->user()->hasPermission('users.view'))
            <a class="administration-card settings-card" href="{{ route('administration.erp-user-management.index') }}">
                <strong>ERP User Management</strong><span>Manage login accounts, linked staff and ERP access.</span><b>Open →</b>
            </a>
            <a class="administration-card settings-card" href="{{ route('administration.staff-access.index') }}">
                <strong>Staff Access</strong><span>Manage user roles, branches and permission overrides.</span><b>Open →</b>
            </a>
        @endif
        @if(auth()->user()->hasPermission('roles.view'))
            <a class="administration-card settings-card" href="{{ route('administration.roles.index') }}">
                <strong>Roles &amp; Permissions</strong><span>Granular capability matrix for accounting, operations, refunds and approvals.</span><b>Open →</b>
            </a>
        @endif
        @if(auth()->user()->hasPermission('approvals.view'))
            <a class="administration-card settings-card" href="{{ route('administration.approvals.index') }}">
                <strong>Approval Authority</strong><span>Amount limits and override rights for refunds, payments, credit sales and journals.</span><b>Open →</b>
            </a>
        @endif
        @if(auth()->user()->hasPermission('audit.view'))
            <a class="administration-card settings-card" href="{{ route('administration.audit.index') }}">
                <strong>Audit &amp; Login History</strong><span>Who changed access, logged in, approved or altered controlled setup.</span><b>Open →</b>
            </a>
        @endif
    </section>
</main>
@endsection
