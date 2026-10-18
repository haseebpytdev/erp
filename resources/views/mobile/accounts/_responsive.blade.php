<style>
    .mobile-accounts-page, .mobile-accounts-page * { box-sizing: border-box; }
    body:has(.mobile-accounts-page) { overflow-x: hidden; }
    .mobile-accounts-page { width: 100%; max-width: 760px; min-width: 0; margin: 0 auto; padding: 16px; overflow-x: hidden; }
    .mobile-accounts-page h1 { margin: 0; color: #17243a; font-size: clamp(1.35rem, 5vw, 2rem); line-height: 1.2; }
    .mobile-accounts-page .mobile-eyebrow { margin: 0 0 3px; color: #66758a; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
    .mobile-accounts-page .mobile-document-hero { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 10px; }
    .mobile-accounts-page .mobile-document-hero strong { color: #17243a; font-size: clamp(1.25rem, 5vw, 1.8rem); font-variant-numeric: tabular-nums; }
    .mobile-accounts-page .mobile-sub { color: #66758a; margin: 6px 0 18px; overflow-wrap: anywhere; }
    .mobile-accounts-page .mobile-brand { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; color: #17243a; font-weight: 800; }
    .mobile-accounts-page .mobile-brand a { color: #315273; font-weight: 700; white-space: nowrap; }
    .mobile-accounts-page a:not(.mobile-card) { color: #1769d2; text-decoration: underline; text-decoration-thickness: 1px; text-underline-offset: 2px; }
    .mobile-accounts-page a:not(.mobile-card):visited { color: #5b3f8c; }
    .mobile-accounts-page a:not(.mobile-card):hover { color: #0b4ea2; text-decoration-thickness: 2px; }
    .mobile-accounts-page a:not(.mobile-card):focus-visible { outline: 3px solid #f59e0b; outline-offset: 3px; border-radius: 3px; }
    .mobile-accounts-page a:not(.mobile-card):active { color: #0a3470; }
    .mobile-accounts-page .mobile-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .mobile-accounts-page .mobile-card { display: block; width: 100%; min-height: 86px; padding: 18px; border: 1px solid #dce7f4; border-radius: 12px; background: #fff; color: #17243a; text-decoration: none; box-shadow: 0 5px 18px rgba(28,55,90,.06); overflow-wrap: anywhere; }
    .mobile-accounts-page .mobile-card strong { display: block; font-size: 15px; }
    .mobile-accounts-page .mobile-card span { display: block; margin-top: 5px; color: #66758a; font-size: 12px; }
    .mobile-accounts-page .mobile-section { margin-top: 20px; padding: 14px; border: 1px solid #dce7f4; border-radius: 9px; background: #fff; }
    .mobile-accounts-page .mobile-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .mobile-accounts-page .mobile-table th, .mobile-accounts-page .mobile-table td { padding: 8px 5px; border-bottom: 1px solid #edf1f6; text-align: left; }
    .mobile-accounts-page .mobile-muted { color: #66758a; }
    .mobile-accounts-page .mobile-fields { display: grid; grid-template-columns: 1fr; gap: 12px; }
    .mobile-accounts-page .mobile-fields label { display: grid; gap: 5px; min-width: 0; font-weight: 700; font-size: 12px; color: #2f4058; }
    .mobile-accounts-page .mobile-fields input, .mobile-accounts-page .mobile-fields select, .mobile-accounts-page .mobile-fields textarea { width: 100%; max-width: 100%; min-width: 0; min-height: 42px; padding: 9px; border: 1px solid #cfdae8; border-radius: 7px; font-size: 14px; }
    .mobile-accounts-page .mobile-more { width: 100%; max-width: 100%; margin-top: 15px; padding: 12px; border: 1px solid #dce7f4; border-radius: 9px; overflow: hidden; }
    .mobile-accounts-page .mobile-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
    .mobile-accounts-page .mobile-actions button { min-width: 0; min-height: 44px; padding: 10px 16px; border: 0; border-radius: 7px; background: #1769d2; color: #fff; font-weight: 800; }
    .mobile-accounts-page .mobile-actions button.secondary { background: #eef3f8; color: #24344b; }
    .mobile-accounts-page form[data-mobile-submit-guard] button[disabled] { cursor: wait; opacity: .72; }
    .mobile-accounts-page .mobile-validation-error { margin: 12px 0; padding: 10px 12px; border: 1px solid #f0b6b6; border-radius: 7px; background: #fff5f5; color: #a12626; font-weight: 700; }
    .mobile-accounts-page .mobile-success { margin: 12px 0; padding: 10px 12px; border: 1px solid #b7dfc4; border-radius: 7px; background: #f0fff4; color: #176b36; font-weight: 700; }
    .mobile-document-page .mobile-readonly-value { display: block; font-weight: 500; color: #17243a; overflow-wrap: anywhere; }
    .mobile-document-page .mobile-detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 10px; }
    .mobile-document-page .mobile-detail-grid > div { min-width: 0; }
    .mobile-document-page .mobile-detail-label { display: block; color: #66758a; font-size: 11px; font-weight: 800; margin-bottom: 3px; }
    .mobile-document-page .mobile-secondary-detail { padding-top: 4px; }
    .mobile-document-page .mobile-activity-list { display: grid; gap: 10px; margin-top: 10px; }
    .mobile-document-page .mobile-activity-list div { display: grid; gap: 3px; padding-bottom: 8px; border-bottom: 1px solid #edf1f6; }
    @media (max-width: 760px) {
        body:has(.mobile-accounts-page) .sidebar, body:has(.mobile-accounts-page) .sidebar-menu,
        body:has(.mobile-accounts-page) .side-nav, body:has(.mobile-accounts-page) .navbar-vertical { display: none !important; }
        body:has(.mobile-accounts-page) header,
        body:has(.mobile-accounts-page) .topbar,
        body:has(.mobile-accounts-page) [class*="topbar"],
        body:has(.mobile-accounts-page) [class*="top-bar"],
        body:has(.mobile-accounts-page) .app-header,
        body:has(.mobile-accounts-page) .desktop-header,
        body:has(.mobile-accounts-page) .navbar:not(.navbar-vertical) { display: none !important; }
        body:has(.mobile-accounts-page) .app-shell, body:has(.mobile-accounts-page) .app-shell > main,
        body:has(.mobile-accounts-page) main.main, body:has(.mobile-accounts-page) .main-content { width: 100% !important; max-width: 100% !important; min-width: 0 !important; margin-left: 0 !important; margin-top: 0 !important; padding-left: 0 !important; padding-right: 0 !important; padding-top: 0 !important; grid-template-columns: 1fr !important; }
        .mobile-accounts-page { width: 100%; max-width: 100%; padding: 12px; }
        .mobile-accounts-page .mobile-grid { grid-template-columns: 1fr; }
        .mobile-accounts-page .mobile-card { min-height: 92px; }
        .mobile-accounts-page .mobile-title { font-size: clamp(1.35rem, 7vw, 1.85rem); }
        .mobile-accounts-page .mobile-table { display: block; width: 100%; font-size: 12px; }
        .mobile-accounts-page .mobile-table thead { display: none; }
        /* Historical mobile table cells retain data-label semantics. */
        /* data-label */
        .mobile-accounts-page .mobile-table tbody { display: block; width: 100%; }
        .mobile-accounts-page .mobile-table tr.mobile-posting-row { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; align-items: center; gap: 8px; width: 100%; min-height: 52px; padding: 7px 0; border-bottom: 1px solid #edf1f6; }
        .mobile-accounts-page .mobile-table tr.mobile-posting-row td { display: block; width: auto; min-width: 0; padding: 0; border: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .mobile-accounts-page .mobile-table tr.mobile-posting-row .mobile-posting-voucher { grid-column: 1; min-width: 0; }
        .mobile-accounts-page .mobile-table tr.mobile-posting-row .mobile-posting-amount { grid-column: 2; text-align: right; }
        .mobile-accounts-page .mobile-table tr.mobile-posting-row .mobile-posting-status { grid-column: 3; text-align: right; overflow: visible; }
        .mobile-accounts-page .mobile-table tr.mobile-posting-row .mobile-posting-summary { display: block; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #17243a; font-weight: 700; }
        .mobile-accounts-page .mobile-table tr.mobile-posting-row .mobile-posting-party, .mobile-accounts-page .mobile-table tr.mobile-posting-row .mobile-posting-number { overflow: hidden; text-overflow: ellipsis; }
        .mobile-accounts-page .mobile-table tr.mobile-posting-row .mobile-posting-party-desktop, .mobile-accounts-page .mobile-table tr.mobile-posting-row .mobile-posting-type, .mobile-accounts-page .mobile-table tr.mobile-posting-row .mobile-posting-date, .mobile-accounts-page .mobile-table tr.mobile-posting-row .desktop-posting-number { display: none; }
        .mobile-accounts-page .mobile-table tr.mobile-posting-row .mobile-status-badge { display: inline-flex; align-items: center; min-height: 28px; padding: 4px 7px; border-radius: 999px; background: #eef5ff; color: #1769d2; font-size: 11px; font-weight: 700; }
        .mobile-accounts-page .mobile-actions { display: grid; grid-template-columns: 1fr; }
        .mobile-accounts-page .mobile-actions button { width: 100%; }
        .mobile-accounts-page input[type=file] { max-width: 100%; }
        .mobile-document-page .mobile-detail-grid { grid-template-columns: 1fr; }
    }
</style>
<script>
    (() => {
        const normalize = value => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
        const resolveBackToErp = () => {
            const target = document.querySelector('[data-mobile-back-to-erp]');
            if (!target) return;
            const candidates = Array.from(document.querySelectorAll(
                'aside a, nav a, [class*="sidebar"] a, [class*="side-nav"] a, [class*="navbar-vertical"] a'
            ));
            const dashboard = candidates.find(anchor =>
                normalize(anchor.textContent) === 'dashboard'
                && anchor.href
                && !anchor.href.endsWith('#')
            );
            if (!dashboard) {
                target.removeAttribute('href');
                target.setAttribute('aria-disabled', 'true');
                return;
            }
            target.href = dashboard.href;
            target.dataset.mobileBackToErpResolved = 'true';
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', resolveBackToErp, { once: true });
        } else {
            resolveBackToErp();
        }
        document.querySelectorAll('form[data-mobile-submit-guard]').forEach(form => {
            form.addEventListener('submit', event => {
                if (form.dataset.mobileSubmitting === 'true') {
                    event.preventDefault();
                    return;
                }
                form.dataset.mobileSubmitting = 'true';
                const submitter = event.submitter;
                if (submitter && submitter.name === 'after_save') {
                    const selected = document.createElement('input');
                    selected.type = 'hidden';
                    selected.name = 'after_save';
                    selected.value = submitter.value;
                    form.appendChild(selected);
                    submitter.removeAttribute('name');
                }
                form.querySelectorAll('button[type="submit"]').forEach(button => {
                    button.disabled = true;
                    button.textContent = button === submitter && button.value === 'submit' ? 'Submitting…' : 'Saving…';
                });
            });
        });
    })();
</script>
