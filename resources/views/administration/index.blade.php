@extends('layouts.app')

@section('title', 'Administration')
@section('page_title', 'Administration')

@section('content')
<main class="administration-page">
    <section class="administration-stats" aria-label="Administration statistics">
        <article class="administration-stat-card"><span class="administration-stat-label">Active Staff</span><strong>{{ $staffCount }}</strong></article>
        <article class="administration-stat-card"><span class="administration-stat-label">Active ERP Users</span><strong>{{ $userCount }}</strong></article>
        <article class="administration-stat-card"><span class="administration-stat-label">Roles</span><strong>{{ $roleCount }}</strong></article>
        <article class="administration-stat-card"><span class="administration-stat-label">Approval Policies</span><strong>{{ $policyCount }}</strong></article>
    </section>

    <section class="administration-grid" aria-label="Administration tools">
        @if(auth()->user()->hasPermission('staff.view'))
            <a class="administration-card" href="{{ route('administration.staff.index') }}">
                <strong>Staff Directory</strong><span>Manage staff records and employee details.</span>
            </a>
        @endif
        @if(auth()->user()->hasPermission('departments.view'))
            <a class="administration-card" href="{{ route('administration.departments.index') }}">
                <strong>Departments</strong><span>Organize departments and operational ownership.</span>
            </a>
        @endif
        @if(auth()->user()->hasPermission('users.view'))
            <a class="administration-card" href="{{ route('administration.erp-user-management.index') }}">
                <strong>ERP User Management</strong><span>Manage login accounts, linked staff and ERP access.</span>
            </a>
            <a class="administration-card" href="{{ route('administration.staff-access.index') }}">
                <strong>Staff Access</strong><span>Manage user roles, branches and permission overrides.</span>
            </a>
        @endif
        @if(auth()->user()->hasPermission('roles.view'))
            <a class="administration-card" href="{{ route('administration.roles.index') }}">
                <strong>Roles &amp; Permissions</strong><span>Review role templates and permission assignments.</span>
            </a>
        @endif
        @if(auth()->user()->hasPermission('approvals.view'))
            <a class="administration-card" href="{{ route('administration.approvals.index') }}">
                <strong>Approval Authority</strong><span>Manage approval policies and financial authority.</span>
            </a>
        @endif
        @if(auth()->user()->hasPermission('audit.view'))
            <a class="administration-card" href="{{ route('administration.audit.index') }}">
                <strong>Audit &amp; Login History</strong><span>Review administrative activity and login history.</span>
            </a>
        @endif
    </section>
</main>
@endsection
