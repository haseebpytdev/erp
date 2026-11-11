@extends('layouts.app')
@section('title','Change Password')
@section('page_title','Change Password')
@section('content')
<div class="page-intro"><div><span class="eyebrow">SECURITY REQUIRED</span><h1>Set your own ERP password</h1><p>An administrator created or reset your login password. You must replace it before accessing the ERP.</p></div></div>
<div class="panel" style="max-width:650px"><div class="callout warn"><strong>Access is temporarily restricted.</strong> Complete this password change to continue.</div><form method="POST" action="{{ route('account.password.update') }}">@csrf @method('PUT')<div class="form-stack"><label><span>Current Password</span><input type="password" name="current_password" required></label><label><span>New Password (10+ characters)</span><input type="password" name="password" minlength="10" required></label><label><span>Confirm New Password</span><input type="password" name="password_confirmation" minlength="10" required></label><button class="btn btn-primary">Change Password & Continue</button></div></form></div>
@endsection
