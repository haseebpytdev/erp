@extends($erpLayout)

@section($erpTitleSection, 'ERP User Management')

@section($erpContentSection)
@php
    $selectedStaff = null;
    if ($selected && $selected['staff_id']) {
        foreach ($staffRows as $staff) {
            if ((int)$staff['id'] === (int)$selected['staff_id']) {
                $selectedStaff = $staff;
                break;
            }
        }
    }
    $oldRoleIds = old('role_ids', $selected['role_ids'] ?? []);
    $oldBranchIds = old('branch_ids', $selected['branch_ids'] ?? []);
@endphp
<style>
#et-user-management-103175{--blue:#1769d2;--green:#118a55;--red:#bd3d3d;--amber:#8a6412;--text:#17243a;--muted:#6f7d90;--line:#dfe7f0;width:100%;color:var(--text)}
#et-user-management-103175 *{box-sizing:border-box}
#et-user-management-103175 .u-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}
#et-user-management-103175 h1{margin:0;font-size:27px;line-height:1.15}
#et-user-management-103175 .u-kicker{color:var(--blue);font-size:10px;font-weight:850;letter-spacing:.05em;text-transform:uppercase;margin-bottom:5px}
#et-user-management-103175 .u-sub{margin-top:4px;color:var(--muted);font-size:11px;line-height:1.45}
#et-user-management-103175 .u-btn{display:inline-flex;align-items:center;justify-content:center;min-height:35px;padding:7px 11px;border:1px solid #d1dce8;border-radius:6px;background:#fff;color:#2e4059;text-decoration:none;font-size:10px;font-weight:850;cursor:pointer;white-space:nowrap}
#et-user-management-103175 .u-btn.blue{background:var(--blue);border-color:var(--blue);color:#fff}
#et-user-management-103175 .u-btn.green{background:var(--green);border-color:var(--green);color:#fff}
#et-user-management-103175 .u-btn.red{background:#fff1f2;border-color:#efc8cd;color:#aa2937}
#et-user-management-103175 .u-alert{margin-bottom:12px;padding:10px 12px;border:1px solid #d2e1f3;border-radius:8px;background:#f3f8fe;color:#315b8e;font-size:10.5px;line-height:1.45}
#et-user-management-103175 .u-alert.good{border-color:#bfe3cf;background:#edf9f2;color:#17623c}
#et-user-management-103175 .u-alert.bad{border-color:#efc3c8;background:#fff0f1;color:#7e1d28}
#et-user-management-103175 .u-grid{display:grid;grid-template-columns:minmax(330px,.8fr) minmax(0,1.2fr);gap:12px;align-items:start}
#et-user-management-103175 .u-card{background:#fff;border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-bottom:12px}
#et-user-management-103175 .u-head{padding:11px 13px;border-bottom:1px solid #e9eef4;display:flex;justify-content:space-between;gap:10px;align-items:center}
#et-user-management-103175 .u-title{font-size:15px;font-weight:850}
#et-user-management-103175 .u-note{margin-top:2px;color:var(--muted);font-size:9.5px;line-height:1.4}
#et-user-management-103175 .u-body{padding:12px 13px}
#et-user-management-103175 .u-user{padding:10px;border:1px solid #e1e8f0;border-radius:8px;background:#fbfcfe;margin-bottom:7px}
#et-user-management-103175 .u-user:last-child{margin-bottom:0}
#et-user-management-103175 .u-user-top{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}
#et-user-management-103175 .u-name{font-size:12px;font-weight:850}
#et-user-management-103175 .u-meta{margin-top:3px;color:#718096;font-size:9.5px;line-height:1.45}
#et-user-management-103175 .u-actions{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end}
#et-user-management-103175 .u-pill{display:inline-flex;padding:3px 6px;border-radius:999px;background:#edf7f2;color:#118a55;font-size:8px;font-weight:850}
#et-user-management-103175 .u-pill.off{background:#fff1f2;color:#b02c39}
#et-user-management-103175 .u-pill.role{background:#eef5ff;color:#2a63a4}
#et-user-management-103175 .u-pill.warn{background:#fff5dc;color:#866116}
#et-user-management-103175 .u-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px 11px}
#et-user-management-103175 .u-field.full{grid-column:1/-1}
#et-user-management-103175 label{display:block;margin-bottom:4px;font-size:9px;font-weight:850;color:#44556c}
#et-user-management-103175 input[type=text],#et-user-management-103175 input[type=email],#et-user-management-103175 input[type=password],#et-user-management-103175 select{width:100%;height:37px;border:1px solid #d4deea;border-radius:6px;padding:7px 9px;background:#fff;color:#17243a;font:inherit;font-size:10.5px;outline:none}
#et-user-management-103175 input[readonly]{background:#f7f9fc;color:#64758b}
#et-user-management-103175 .u-inline{display:flex;gap:5px;align-items:center}
#et-user-management-103175 .u-inline input{flex:1}
#et-user-management-103175 .u-checks{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}
#et-user-management-103175 .u-check{display:flex;gap:6px;align-items:flex-start;padding:7px;border:1px solid #e0e7ef;border-radius:6px;background:#fbfcfe;font-size:9.5px;line-height:1.35}
#et-user-management-103175 .u-check input{margin-top:1px}
#et-user-management-103175 .u-section{margin-top:10px;padding-top:10px;border-top:1px solid #edf1f5}
#et-user-management-103175 .u-section-title{margin-bottom:7px;font-size:10px;font-weight:850}
#et-user-management-103175 .u-footer{display:flex;justify-content:flex-end;gap:7px;margin-top:12px;flex-wrap:wrap}
#et-user-management-103175 .u-info{margin:7px 0;padding:8px 9px;border:1px solid #dce8f5;border-radius:6px;background:#f5f9fe;color:#536b86;font-size:9.5px;line-height:1.45}
@media(max-width:1050px){#et-user-management-103175 .u-grid{grid-template-columns:1fr}#et-user-management-103175 .u-checks{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){#et-user-management-103175 .u-form-grid{grid-template-columns:1fr}#et-user-management-103175 .u-checks{grid-template-columns:1fr}#et-user-management-103175 .u-top{display:block}#et-user-management-103175 .u-actions{justify-content:flex-start;margin-top:7px}}
</style>

<div id="et-user-management-103175">
    <div class="u-top">
        <div>
            <div class="u-kicker">Administration · Users</div>
            <h1>ERP User Management</h1>
            <div class="u-sub">Edit existing logins, reset passwords, activate/deactivate access, maintain roles/branches and intentionally synchronize Login Email with the linked Staff profile.</div>
        </div>
        <div class="u-actions">
            <a class="u-btn" href="{{ url()->previous() }}">Back</a>
        </div>
    </div>

    @if(session('erp_user_success'))
        <div class="u-alert good">{{ session('erp_user_success') }}</div>
    @endif
    @if(session('erp_user_error'))
        <div class="u-alert bad">{{ session('erp_user_error') }}</div>
    @endif

    <div class="u-alert">
        <strong>Staff Email and Login Email are deliberately separate.</strong>
        Updating the Staff profile does not silently change a person's sign-in credential. When you want both to match, open the user and click <strong>Use Staff Email as Login</strong>, then Save User Account.
    </div>

    <div class="u-grid">
        <div class="u-card">
            <div class="u-head">
                <div>
                    <div class="u-title">Users</div>
                    <div class="u-note">{{ count($users) }} ERP login account(s)</div>
                </div>
            </div>
            <div class="u-body">
                @forelse($users as $user)
                    @php
                        $differentEmail = !empty($user['staff']['email']) && strcasecmp((string)$user['staff']['email'], (string)$user['login_email']) !== 0;
                    @endphp
                    <div class="u-user">
                        <div class="u-user-top">
                            <div>
                                <div class="u-name">{{ $user['username'] ?: ('User #'.$user['id']) }}</div>
                                <div class="u-meta">
                                    Login: {{ $user['login_email'] ?: '—' }}
                                    @if($user['staff'])
                                        · Staff: {{ $user['staff']['code'] ?: '#'.$user['staff']['id'] }} {{ $user['staff']['name'] ? '· '.$user['staff']['name'] : '' }}
                                    @else
                                        · No staff link
                                    @endif
                                </div>
                                @if($differentEmail)
                                    <div class="u-meta" style="color:#866116">Staff email: {{ $user['staff']['email'] }} <span class="u-pill warn">EMAIL DIFFERENT</span></div>
                                @endif
                                @if(!empty($user['role_names']))
                                    <div class="u-meta">
                                        @foreach($user['role_names'] as $role)
                                            <span class="u-pill role">{{ $role }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <div class="u-actions">
                                <span class="u-pill {{ $user['active'] ? '' : 'off' }}">{{ $user['active'] ? 'ACTIVE' : 'INACTIVE' }}</span>
                                <a class="u-btn blue" href="{{ route('administration.erp-user-management.index',['user'=>$user['id']]) }}">Manage</a>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="u-note">No ERP users found.</div>
                @endforelse
            </div>
        </div>

        <div>
            @if($selected)
                <div class="u-card">
                    <div class="u-head">
                        <div>
                            <div class="u-title">Manage {{ $selected['username'] ?: ('User #'.$selected['id']) }}</div>
                            <div class="u-note">Linked staff, login identity, access, password and account status.</div>
                        </div>
                        <span class="u-pill {{ $selected['active'] ? '' : 'off' }}">{{ $selected['active'] ? 'ACTIVE' : 'INACTIVE' }}</span>
                    </div>
                    <div class="u-body">
                        <form method="POST" action="{{ route('administration.erp-user-management.update',['user'=>$selected['id']]) }}">
                            @csrf
                            <div class="u-form-grid">
                                <div class="u-field">
                                    <label>Linked Staff</label>
                                    <select name="staff_id">
                                        <option value="0">No staff link</option>
                                        @foreach($staffRows as $staff)
                                            <option value="{{ $staff['id'] }}" {{ (int)old('staff_id',$selected['staff_id']) === (int)$staff['id'] ? 'selected' : '' }}>
                                                {{ $staff['code'] ?: ('#'.$staff['id']) }} · {{ $staff['name'] ?: 'Unnamed Staff' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="u-field">
                                    <label>User Name</label>
                                    <input type="text" name="username" value="{{ old('username',$selected['username']) }}" autocomplete="off">
                                </div>

                                <div class="u-field">
                                    <label>Email / Login</label>
                                    <div class="u-inline">
                                        <input id="et-login-email-103175" type="email" name="login_email" value="{{ old('login_email',$selected['login_email']) }}" autocomplete="off">
                                        <button class="u-btn" type="button" id="et-use-staff-email-103175" {{ empty($selectedStaff['email']) ? 'disabled' : '' }}>Use Staff Email</button>
                                    </div>
                                </div>
                                <div class="u-field">
                                    <label>Staff Email</label>
                                    <input id="et-staff-email-103175" type="text" readonly value="{{ $selectedStaff['email'] ?? '' }}">
                                </div>

                                @if($schema['access_scope_column'])
                                    <div class="u-field">
                                        <label>Access Scope</label>
                                        <input type="text" name="access_scope" value="{{ old('access_scope',$selected['access_scope']) }}" readonly>
                                        <div class="u-note">Current native access-scope value is preserved. Branch permissions can be changed below.</div>
                                    </div>
                                @endif

                                <div class="u-field">
                                    <label>Account Status</label>
                                    <select name="active" {{ (int)$selected['id']===(int)$currentUserId ? 'disabled' : '' }}>
                                        <option value="1" {{ old('active',$selected['active'] ? '1' : '0')==='1' ? 'selected' : '' }}>ACTIVE</option>
                                        <option value="0" {{ old('active',$selected['active'] ? '1' : '0')==='0' ? 'selected' : '' }}>INACTIVE</option>
                                    </select>
                                    @if((int)$selected['id']===(int)$currentUserId)
                                        <input type="hidden" name="active" value="1">
                                        <div class="u-note">Your currently signed-in account cannot be deactivated here.</div>
                                    @endif
                                </div>

                                @if(!empty($branches))
                                    <div class="u-field full u-section">
                                        <div class="u-section-title">Allowed Branches</div>
                                        <div class="u-checks">
                                            @foreach($branches as $branch)
                                                <label class="u-check">
                                                    <input type="checkbox" name="branch_ids[]" value="{{ $branch['id'] }}" {{ in_array((int)$branch['id'],array_map('intval',$oldBranchIds),true) ? 'checked' : '' }}>
                                                    <span><strong>{{ $branch['name'] ?: ('Branch #'.$branch['id']) }}</strong>{{ $branch['code'] ? ' · '.$branch['code'] : '' }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="u-field">
                                        <label>Primary Branch</label>
                                        <select name="primary_branch_id">
                                            <option value="0">No primary branch</option>
                                            @foreach($branches as $branch)
                                                <option value="{{ $branch['id'] }}" {{ (int)old('primary_branch_id',$selected['primary_branch_id']) === (int)$branch['id'] ? 'selected' : '' }}>{{ $branch['name'] ?: ('Branch #'.$branch['id']) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif

                                @if(!empty($roles))
                                    <div class="u-field full u-section">
                                        <div class="u-section-title">Roles</div>
                                        @if((int)$selected['id']===(int)$currentUserId)
                                            <div class="u-info">Your own roles are shown but locked here to prevent accidental Super Admin lockout.</div>
                                        @endif
                                        <div class="u-checks">
                                            @foreach($roles as $role)
                                                <label class="u-check">
                                                    <input type="checkbox" name="role_ids[]" value="{{ $role['id'] }}" {{ in_array((int)$role['id'],array_map('intval',$oldRoleIds),true) ? 'checked' : '' }} {{ (int)$selected['id']===(int)$currentUserId ? 'disabled' : '' }}>
                                                    <span><strong>{{ $role['name'] ?: ('Role #'.$role['id']) }}</strong></span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <div class="u-field full u-section">
                                    <div class="u-section-title">Reset Password <span style="font-weight:500;color:#718096">(leave blank to keep current password)</span></div>
                                </div>
                                <div class="u-field">
                                    <label>New Password</label>
                                    <input type="password" name="password" minlength="10" autocomplete="new-password">
                                </div>
                                <div class="u-field">
                                    <label>Confirm New Password</label>
                                    <input type="password" name="password_confirmation" minlength="10" autocomplete="new-password">
                                </div>
                            </div>

                            <div class="u-info">
                                Changing <strong>Staff Email</strong> alone does not alter ERP sign-in. Changing <strong>Email / Login</strong> changes the credential used by this ERP user. The one-click button above copies the current linked Staff Email intentionally.
                            </div>

                            <div class="u-footer">
                                <button class="u-btn blue" type="submit">Save User Account</button>
                            </div>
                        </form>

                        @if((int)$selected['id'] !== (int)$currentUserId)
                            <form method="POST" action="{{ route('administration.erp-user-management.status',['user'=>$selected['id']]) }}" style="margin-top:8px;text-align:right">
                                @csrf
                                <input type="hidden" name="action" value="{{ $selected['active'] ? 'deactivate' : 'activate' }}">
                                <button class="u-btn {{ $selected['active'] ? 'red' : 'green' }}" type="submit" onclick="return confirm('{{ $selected['active'] ? 'Deactivate this ERP login?' : 'Activate this ERP login?' }}');">
                                    {{ $selected['active'] ? 'Deactivate User' : 'Activate User' }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @else
                <div class="u-card">
                    <div class="u-body" style="padding:28px;text-align:center">
                        <div class="u-title">Select an ERP user to manage</div>
                        <div class="u-note" style="margin-top:5px">Choose Manage beside a user to edit login email, username, password, roles, branch access or active status.</div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

@if($selected)
<script>
document.addEventListener('DOMContentLoaded',function(){
    const button=document.getElementById('et-use-staff-email-103175');
    const staff=document.getElementById('et-staff-email-103175');
    const login=document.getElementById('et-login-email-103175');
    if(button&&staff&&login){
        button.addEventListener('click',function(){
            const value=String(staff.value||'').trim();
            if(value){login.value=value;login.focus();}
        });
    }
});
</script>
@endif
@endsection
