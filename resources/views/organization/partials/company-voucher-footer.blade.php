<style>
.company-voucher-footer-panel{margin-top:16px}
.company-voucher-footer-panel .cvf-copy{margin:4px 0 0;color:var(--muted);font-size:12px;line-height:1.55}
.company-voucher-footer-panel .cvf-label{display:block;font-size:12px;font-weight:800;color:var(--text);margin-bottom:7px}
.company-voucher-footer-panel textarea{width:100%;min-height:98px;resize:vertical;border:1px solid var(--line);border-radius:10px;padding:11px 12px;background:#fff;color:var(--text);font:inherit;line-height:1.5}
.company-voucher-footer-panel textarea:focus{outline:2px solid rgba(37,99,235,.12);border-color:#7da7ef}
.company-voucher-footer-panel .cvf-help{display:block;margin-top:7px;color:var(--muted);font-size:11px;line-height:1.5}
.company-voucher-footer-panel .cvf-actions{display:flex;justify-content:flex-end;margin-top:12px}
.company-voucher-footer-panel .cvf-priority{margin-top:12px;border:1px solid #dbe9fb;background:#f3f8ff;color:#31577d;border-radius:10px;padding:10px 12px;font-size:11px;line-height:1.5}
.company-voucher-footer-panel .cvf-warning{border:1px solid #efd7a5;background:#fff9ec;color:#80591a;border-radius:10px;padding:11px 12px;font-size:12px}
</style>
<div class="panel company-voucher-footer-panel">
    <div class="panel-head">
        <div>
            <h3>Default Voucher Footer / Company Contacts</h3>
            <p class="cvf-copy">Company-level fallback contact footer for travel vouchers.</p>
        </div>
    </div>

    @if($voucherFooterUpgradeInstalled)
        @if($canManageCompanyVoucherFooter)
            <form method="POST" action="{{ route('organization.company.voucher-footer') }}">
                @csrf
                @method('PUT')
                <label class="cvf-label" for="company-voucher-footer-html">Default Voucher Footer / Company Contacts (HTML)</label>
                <textarea id="company-voucher-footer-html" name="voucher_footer_html" placeholder="Example: <strong>Easy Group Of Travels</strong><br>Pakistan: 03xx... | Emergency: 03xx...">{{ old('voucher_footer_html', $company->voucher_footer_html ?? '') }}</textarea>
                <small class="cvf-help">Safe tags: br, p, div, span, strong, b, em, i, u. Leave blank if you only want IATA-specific footers.</small>
                <div class="cvf-priority"><strong>Voucher footer priority:</strong> Voucher-specific Footer → Selected Pakistan Visa / IATA Footer → Company Default Footer.</div>
                <div class="cvf-actions"><button type="submit" class="btn btn-primary">Save Default Voucher Footer</button></div>
            </form>
        @else
            <div class="cvf-priority"><strong>Voucher footer priority:</strong> Voucher-specific Footer → Selected Pakistan Visa / IATA Footer → Company Default Footer.</div>
            <p class="cvf-copy">You have view access to Company Profile. Company management permission is required to change the default voucher footer.</p>
        @endif
    @else
        <div class="cvf-warning">Run System → Health & Updates → Safe Database Upgrade once to enable the Company default voucher footer field.</div>
    @endif
</div>
