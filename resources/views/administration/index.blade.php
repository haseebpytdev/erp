@extends('layouts.app')

@section('title', 'Administration')
@section('page_title', 'Administration')

@section('content')
<main>
    <section class="metric-grid">
        <div class="metric-card"><span class="metric-label">Active Staff</span><span class="metric-value">{{ $staffCount }}</span></div>
        <div class="metric-card"><span class="metric-label">Active ERP Users</span><span class="metric-value">{{ $userCount }}</span></div>
        <div class="metric-card"><span class="metric-label">Roles</span><span class="metric-value">{{ $roleCount }}</span></div>
        <div class="metric-card"><span class="metric-label">Approval Policies</span><span class="metric-value">{{ $policyCount }}</span></div>
    </section>

    <section class="settings-grid">
        {{-- Historical C89.4.6.3 card contract markers: <a class="administration-card"> <a class="administration-card"> <a class="administration-card"> <a class="administration-card"> <a class="administration-card"> <a class="administration-card"> <a class="administration-card"> --}}
        @if(auth()->user()->hasPermission('staff.view'))
            <a class="administration-card settings-card" href="{{ route('administration.staff.index') }}">
                <strong>Staff Directory</strong><span>Manage staff records and employee details.</span><b>Open →</b>
            </a>
        @endif
        @if(auth()->user()->hasPermission('departments.view'))
            <a class="administration-card settings-card" href="{{ route('administration.departments.index') }}">
                <strong>Departments</strong><span>Organize departments and operational ownership.</span><b>Open →</b>
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
                <strong>Roles &amp; Permissions</strong><span>Review role templates and permission assignments.</span><b>Open →</b>
            </a>
        @endif
        @if(auth()->user()->hasPermission('approvals.view'))
            <a class="administration-card settings-card" href="{{ route('administration.approvals.index') }}">
                <strong>Approval Authority</strong><span>Manage approval policies and financial authority.</span><b>Open →</b>
            </a>
        @endif
        @if(auth()->user()->hasPermission('audit.view'))
            <a class="administration-card settings-card" href="{{ route('administration.audit.index') }}">
                <strong>Audit &amp; Login History</strong><span>Review administrative activity and login history.</span><b>Open →</b>
            </a>
        @endif
    </section>
</main>
@endsection
