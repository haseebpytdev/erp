<?php

namespace App\Http\Middleware;

use App\Services\Administration\ErpRoleAccessPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP-10.31.79
 *
 * One enforcement layer for permission-driven navigation, dashboard actions,
 * direct native routes and the modernized native ERP-02 RBAC screen.
 */
class EnforceErpRoleScopedAccess
{
    public function __construct(
        private readonly ErpRoleAccessPolicy $policy
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $this->policy->mayAccess($request, $user)) {
            abort(403, 'Your assigned ERP role does not have permission for this action.');
        }

        /** @var Response $response */
        $response = $next($request);

        if (
            strtoupper($request->method()) !== 'GET'
            || ! method_exists($response, 'getContent')
            || ! method_exists($response, 'setContent')
        ) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if (! str_contains($contentType, 'text/html') && $contentType !== '') {
            return $response;
        }

        $html = (string) $response->getContent();
        if ($html === '' || ! str_contains(strtolower($html), '<html')) {
            return $response;
        }

        if ($user && $this->policy->isPermissionControlled($user)) {
            $html = $this->injectPermissionNavigation($html, $user);
        }

        if (
            $user
            && $this->policy->canManageRbac($user)
            && (
                str_contains(strtolower($html), 'granular role-based access control')
                || str_contains(strtolower($html), 'erp-02 · rbac')
                || str_contains(strtolower($html), 'erp-02 &middot; rbac')
            )
        ) {
            $html = $this->injectModernRbacUi($html);
        }

        $response->setContent($html);
        return $response;
    }

    private function injectPermissionNavigation(string $html, mixed $user): string
    {
        if (str_contains($html, 'id="et-permission-nav-103179"')) {
            return $html;
        }

        $hrefFragments = $this->policy->hiddenNavigationHrefFragments($user);
        $labels = $this->policy->hiddenNavigationLabels($user);
        $cssSelectors = [];

        foreach ($hrefFragments as $fragment) {
            $escaped = addslashes($fragment);
            $cssSelectors[] = 'a[href*="'.$escaped.'"]';
            $cssSelectors[] = 'li:has(a[href*="'.$escaped.'"])';
            $cssSelectors[] = '.nav-item:has(a[href*="'.$escaped.'"])';
            $cssSelectors[] = '.menu-item:has(a[href*="'.$escaped.'"])';
        }

        $style = '<style id="et-permission-nav-103179">'
            .($cssSelectors ? implode(',', $cssSelectors).'{display:none!important;}' : '')
            .'</style>';

        $payload = json_encode([
            'labels' => array_values($labels),
            'hrefs' => array_values($hrefFragments),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $script = <<<'HTML'
<script id="et-permission-nav-script-103179">
(function(){
'use strict';
const policy=__POLICY__;
const norm=v=>String(v||'').replace(/\s+/g,' ').trim().toLowerCase();
const blockedLabels=new Set((policy.labels||[]).map(norm));
const blockedHrefs=(policy.hrefs||[]).map(norm);

function rowFor(node){
    if(!node)return null;
    return node.closest('li,.nav-item,.menu-item,[class*="nav-item"],[class*="menu-item"],[class*="sidebar-item"],[class*="sidebar-link"],[class*="menu-link"]')
        || node.closest('a,button') || node;
}
function hide(node){
    const row=rowFor(node);
    if(row)row.style.setProperty('display','none','important');
}
function sweep(){
    Array.from(document.querySelectorAll('a[href]')).forEach(a=>{
        const href=norm(a.getAttribute('href'));
        if(blockedHrefs.some(part=>part&&href.includes(part)))hide(a);
    });
    Array.from(document.querySelectorAll('a,button,span,div,p,strong')).forEach(node=>{
        if(node.children.length>4)return;
        if(blockedLabels.has(norm(node.textContent)))hide(node);
    });
}
sweep();
if(document.documentElement.classList.contains('et-booking-focus-prepaint')){
    return;
}
const observer=new MutationObserver(sweep);
observer.observe(document.documentElement,{childList:true,subtree:true});
setTimeout(()=>observer.disconnect(),5000);
})();
</script>
HTML;
        $script = str_replace('__POLICY__', $payload ?: '{"labels":[],"hrefs":[]}', $script);

        if (str_contains($html, '</head>')) {
            $html = str_replace('</head>', $style.'</head>', $html);
        } else {
            $html = $style.$html;
        }
        if (str_contains($html, '</body>')) {
            $html = str_replace('</body>', $script.'</body>', $html);
        } else {
            $html .= $script;
        }
        return $html;
    }

    private function injectModernRbacUi(string $html): string
    {
        if (str_contains($html, 'id="et-rbac-modern-style-103179"')) {
            return $html;
        }

        $summary = $this->policy->summary();
        $payload = json_encode([
            'roles' => (int) ($summary['roles'] ?? 0),
            'permissions' => (int) ($summary['permissions'] ?? 0),
            'groups' => (int) ($summary['groups'] ?? 0),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $style = <<<'HTML'
<style id="et-rbac-modern-style-103179">
body.et-rbac-modern-103179{background:#f4f7fb!important}
body.et-rbac-modern-103179 main,
body.et-rbac-modern-103179 .page-wrapper,
body.et-rbac-modern-103179 .content-wrapper{--et-rbac-border:#dfe7f1;--et-rbac-muted:#6d7c91;--et-rbac-blue:#1769d2}
.et-rbac-modern-banner-103179{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center;margin:14px 0 16px;padding:16px 18px;border:1px solid #dce7f4;border-radius:12px;background:linear-gradient(135deg,#ffffff 0%,#f4f8ff 100%);box-shadow:0 8px 24px rgba(28,55,90,.05)}
.et-rbac-modern-title-103179{font-size:18px;font-weight:850;color:#17243a}.et-rbac-modern-sub-103179{margin-top:4px;font-size:11px;line-height:1.45;color:#6d7c91}
.et-rbac-modern-metrics-103179{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.et-rbac-modern-metric-103179{min-width:86px;padding:8px 10px;border:1px solid #dce7f4;border-radius:8px;background:#fff;text-align:center}.et-rbac-modern-metric-103179 strong{display:block;font-size:16px;color:#1769d2}.et-rbac-modern-metric-103179 span{display:block;margin-top:2px;font-size:8px;font-weight:800;color:#728198;text-transform:uppercase;letter-spacing:.05em}
.et-rbac-toolbar-103179{position:sticky;top:8px;z-index:20;display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:10px 0 14px;padding:9px 10px;border:1px solid #dfe7f1;border-radius:9px;background:rgba(255,255,255,.97);box-shadow:0 8px 24px rgba(27,48,77,.07);backdrop-filter:blur(8px)}
.et-rbac-search-103179{flex:1 1 320px;min-height:36px;padding:7px 10px;border:1px solid #cfdae8;border-radius:6px;background:#fff;font-size:11px;color:#17243a;outline:none}.et-rbac-search-103179:focus{border-color:#1769d2;box-shadow:0 0 0 3px rgba(23,105,210,.10)}
.et-rbac-tool-btn-103179{min-height:34px;padding:6px 9px;border:1px solid #d5e0ec;border-radius:6px;background:#fff;color:#2f4058;font-size:9.5px;font-weight:800;cursor:pointer}.et-rbac-tool-btn-103179:hover{border-color:#1769d2;color:#1769d2}.et-rbac-selected-103179{padding:6px 9px;border-radius:999px;background:#edf5ff;color:#1769d2;font-size:9px;font-weight:800;white-space:nowrap}
.et-rbac-groups-103179{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:10px!important;align-items:start!important}
.et-rbac-group-103179{position:relative!important;min-width:0!important;margin:0!important;padding:12px!important;border:1px solid #dfe7f1!important;border-radius:9px!important;background:#fff!important;box-shadow:none!important;transition:border-color .15s ease,box-shadow .15s ease}
.et-rbac-group-103179:has(input[type="checkbox"]:checked){border-color:#b8d2f4!important;box-shadow:0 6px 18px rgba(23,105,210,.06)!important}
.et-rbac-group-head-103179{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 8px;padding:0 0 8px;border-bottom:1px solid #edf1f6}.et-rbac-group-name-103179{font-size:11px;font-weight:850;color:#1d2d44}.et-rbac-group-count-103179{font-size:8.5px;font-weight:800;color:#1769d2}.et-rbac-group-actions-103179{display:flex;gap:4px;margin-left:auto}.et-rbac-mini-103179{padding:3px 5px;border:1px solid #dbe4ef;border-radius:5px;background:#f8fafc;font-size:8px;font-weight:800;color:#55677f;cursor:pointer}
.et-rbac-permission-row-103179{display:flex!important;align-items:flex-start!important;gap:7px!important;margin:0!important;padding:7px 4px!important;border-bottom:1px solid #f0f3f7!important;border-radius:5px}.et-rbac-permission-row-103179:last-child{border-bottom:0!important}.et-rbac-permission-row-103179:hover{background:#f8fbff!important}.et-rbac-permission-row-103179 input[type="checkbox"]{width:15px!important;height:15px!important;min-width:15px!important;margin-top:1px!important;accent-color:#1769d2}.et-rbac-permission-row-103179 strong,.et-rbac-permission-row-103179 b{font-size:9.5px!important;line-height:1.3!important;color:#24344b!important}.et-rbac-permission-row-103179 small,.et-rbac-permission-row-103179 p,.et-rbac-permission-row-103179 span{font-size:8.3px!important;line-height:1.35!important;color:#738298!important}
body.et-rbac-modern-103179 input[type="text"],body.et-rbac-modern-103179 textarea,body.et-rbac-modern-103179 select{border-color:#d4dfeb!important;border-radius:6px!important}body.et-rbac-modern-103179 textarea{min-height:64px!important}body.et-rbac-modern-103179 button[type="submit"]{border-radius:6px!important;background:#1769d2!important;border-color:#1769d2!important;color:#fff!important;font-weight:800!important}
.et-rbac-role-card-103179{border:1px solid #dfe7f1!important;border-radius:8px!important;background:#fff!important;box-shadow:none!important}.et-rbac-role-card-103179:hover{border-color:#bed3ee!important;box-shadow:0 6px 18px rgba(29,57,92,.05)!important}
@media(max-width:1200px){.et-rbac-groups-103179{grid-template-columns:repeat(2,minmax(0,1fr))!important}.et-rbac-modern-banner-103179{grid-template-columns:1fr}.et-rbac-modern-metrics-103179{justify-content:flex-start}}
@media(max-width:760px){.et-rbac-groups-103179{grid-template-columns:1fr!important}.et-rbac-toolbar-103179{position:static}.et-rbac-modern-metrics-103179{display:grid;grid-template-columns:repeat(3,1fr);width:100%}}
</style>
HTML;

        $script = <<<'HTML'
<script id="et-rbac-modern-script-103179">
(function(){
'use strict';
const summary=__SUMMARY__;
const norm=v=>String(v||'').replace(/\s+/g,' ').trim().toLowerCase();
const groups=['Accounting','Administration','Cash & Bank','Commercial','Master Data','Operations','Organization','Purchase','Purchase & Costing','Refunds','Reports','Sales','System','Ticketing','Travel Operations'];
const marker=Array.from(document.querySelectorAll('h1,h2,h3,div,strong')).find(el=>norm(el.textContent).includes('granular role-based access control'));
if(!marker)return;
document.body.classList.add('et-rbac-modern-103179');
const root=marker.closest('main')||marker.closest('.page-wrapper')||marker.closest('.content-wrapper')||document.body;
const form=Array.from(root.querySelectorAll('form')).find(f=>norm(f.textContent).includes('create custom role'));
if(!form)return;

const banner=document.createElement('section');
banner.className='et-rbac-modern-banner-103179';
banner.innerHTML='<div><div class="et-rbac-modern-title-103179">Permission-driven ERP access</div><div class="et-rbac-modern-sub-103179">Super Admin remains unrestricted. Every other login inherits effective capability permissions from its assigned role(s); sidebar visibility and direct URL authorization use the same permission matrix.</div></div><div class="et-rbac-modern-metrics-103179"><div class="et-rbac-modern-metric-103179"><strong>'+Number(summary.roles||0)+'</strong><span>Roles</span></div><div class="et-rbac-modern-metric-103179"><strong>'+Number(summary.permissions||0)+'</strong><span>Permissions</span></div><div class="et-rbac-modern-metric-103179"><strong>'+Number(summary.groups||0)+'</strong><span>Groups</span></div></div>';
const intro=marker.parentElement||marker;
intro.insertAdjacentElement('afterend',banner);

const permissionInputs=Array.from(form.querySelectorAll('input[type="checkbox"]')).filter(input=>{
    const n=norm(input.name||'');
    return n.includes('permission');
});

function leafExact(text){
    return Array.from(form.querySelectorAll('h2,h3,h4,h5,h6,strong,b,div,span,p')).find(el=>el.children.length<3&&norm(el.textContent)===norm(text));
}
function groupCardFor(head){
    if(!head)return null;
    let node=head.parentElement;
    for(let i=0;i<6&&node&&node!==form;i++){
        const count=node.querySelectorAll('input[type="checkbox"]').length;
        if(count>0&&count<=40)return node;
        node=node.parentElement;
    }
    return null;
}
const cards=[];
groups.forEach(name=>{
    const head=leafExact(name);
    const card=groupCardFor(head);
    if(!card||cards.includes(card))return;
    cards.push(card);
    card.classList.add('et-rbac-group-103179');
    const inputs=Array.from(card.querySelectorAll('input[type="checkbox"]')).filter(i=>permissionInputs.includes(i));
    const header=document.createElement('div');
    header.className='et-rbac-group-head-103179';
    header.innerHTML='<span class="et-rbac-group-name-103179">'+name+'</span><span class="et-rbac-group-count-103179"></span><span class="et-rbac-group-actions-103179"><button type="button" class="et-rbac-mini-103179" data-mode="all">All</button><button type="button" class="et-rbac-mini-103179" data-mode="none">None</button></span>';
    if(head&&head!==card)head.style.display='none';
    card.insertBefore(header,card.firstChild);
    const count=header.querySelector('.et-rbac-group-count-103179');
    const refresh=()=>{count.textContent=inputs.filter(i=>i.checked).length+'/'+inputs.length;};
    header.querySelector('[data-mode="all"]').addEventListener('click',()=>{inputs.filter(i=>!i.disabled&&i.closest('.et-rbac-permission-row-103179')?.style.display!=='none').forEach(i=>i.checked=true);refresh();updateSelected();});
    header.querySelector('[data-mode="none"]').addEventListener('click',()=>{inputs.filter(i=>!i.disabled&&i.closest('.et-rbac-permission-row-103179')?.style.display!=='none').forEach(i=>i.checked=false);refresh();updateSelected();});
    inputs.forEach(i=>i.addEventListener('change',refresh));
    refresh();
});

if(cards.length>1){
    const parents=cards.map(c=>c.parentElement);
    const common=parents.find(p=>p&&parents.filter(x=>x===p).length>=Math.ceil(cards.length*.6));
    if(common)common.classList.add('et-rbac-groups-103179');
}

permissionInputs.forEach(input=>{
    const row=input.closest('label')||input.parentElement;
    if(row)row.classList.add('et-rbac-permission-row-103179');
});

const toolbar=document.createElement('div');
toolbar.className='et-rbac-toolbar-103179';
toolbar.innerHTML='<input type="search" class="et-rbac-search-103179" placeholder="Search permissions… e.g. invoice, booking, supplier costing"><span class="et-rbac-selected-103179">0 selected</span><button type="button" class="et-rbac-tool-btn-103179" data-action="select">Select visible</button><button type="button" class="et-rbac-tool-btn-103179" data-action="clear">Clear visible</button>';
const firstGroup=cards[0]||permissionInputs[0]?.closest('div');
if(firstGroup)firstGroup.parentElement?.insertBefore(toolbar,firstGroup);
const search=toolbar.querySelector('input');
const selected=toolbar.querySelector('.et-rbac-selected-103179');
function updateSelected(){selected.textContent=permissionInputs.filter(i=>i.checked).length+' selected';}
function visibleRows(){return permissionInputs.filter(i=>{const row=i.closest('.et-rbac-permission-row-103179');return row&&row.style.display!=='none';});}
search.addEventListener('input',()=>{
    const q=norm(search.value);
    permissionInputs.forEach(i=>{
        const row=i.closest('.et-rbac-permission-row-103179');
        if(!row)return;
        row.style.display=(!q||norm(row.textContent).includes(q))?'':'none';
    });
    cards.forEach(card=>{
        const rows=Array.from(card.querySelectorAll('.et-rbac-permission-row-103179'));
        card.style.display=rows.some(r=>r.style.display!=='none')?'':'none';
    });
});
toolbar.querySelector('[data-action="select"]').addEventListener('click',()=>{visibleRows().forEach(i=>{if(!i.disabled)i.checked=true;});updateSelected();permissionInputs.forEach(i=>i.dispatchEvent(new Event('change')));});
toolbar.querySelector('[data-action="clear"]').addEventListener('click',()=>{visibleRows().forEach(i=>{if(!i.disabled)i.checked=false;});updateSelected();permissionInputs.forEach(i=>i.dispatchEvent(new Event('change')));});
permissionInputs.forEach(i=>i.addEventListener('change',updateSelected));
updateSelected();

/* Compact native role cards without altering their forms/actions. */
Array.from(root.querySelectorAll('div,section,article')).forEach(el=>{
    if(el.children.length>20)return;
    const t=norm(el.textContent);
    if(t.includes('system')&&t.includes('active')&&el.querySelector('button,a')){
        const r=el.getBoundingClientRect();
        if(r.width>250&&r.height>45&&r.height<260)el.classList.add('et-rbac-role-card-103179');
    }
});
})();
</script>
HTML;
        $script = str_replace('__SUMMARY__', $payload ?: '{"roles":0,"permissions":0,"groups":0}', $script);

        if (str_contains($html, '</head>')) {
            $html = str_replace('</head>', $style.'</head>', $html);
        } else {
            $html = $style.$html;
        }
        if (str_contains($html, '</body>')) {
            $html = str_replace('</body>', $script.'</body>', $html);
        } else {
            $html .= $script;
        }
        return $html;
    }
}
