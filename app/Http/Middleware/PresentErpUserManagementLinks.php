<?php

namespace App\Http\Middleware;

use App\Services\Administration\ErpUserManagementAuthority;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP-10.31.75
 * Adds management/navigation affordances to the existing native Staff and ERP
 * User Account pages without replacing those screens or their create flow.
 */
class PresentErpUserManagementLinks
{
    public function __construct(
        private readonly ErpUserManagementAuthority $authority,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->authority->canManage($request->user())) {
            return $response;
        }

        if (! method_exists($response, 'getContent')) {
            return $response;
        }

        $content = (string) $response->getContent();

        if (
            $content === ''
            || ! str_contains(strtolower((string) $response->headers->get('content-type')), 'text/html')
        ) {
            return $response;
        }

        $isUsersPage = str_contains($content, 'ERP User Accounts')
            || str_contains($content, 'Login access, branch scope and roles');

        $isStaffPage = str_contains($content, 'Staff identity is independent from ERP login')
            || str_contains($content, 'Staff Directory');

        if (! $isUsersPage && ! $isStaffPage) {
            return $response;
        }

        $url = route('administration.erp-user-management.index');
        $urlJson = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $script = <<<HTML
<style id="et-user-management-links-103175-style">
.et-user-manage-link-103175{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:7px 11px;border:1px solid #1769d2;border-radius:6px;background:#1769d2;color:#fff!important;text-decoration:none!important;font-size:10px;font-weight:800;white-space:nowrap}.et-user-manage-note-103175{margin:7px 0;padding:8px 9px;border:1px solid #d6e5f6;border-radius:6px;background:#f2f7fd;color:#48647f;font-size:9.5px;line-height:1.45}
</style>
<script id="et-user-management-links-103175-script">
(function(){
'use strict';
const manageUrl={$urlJson};
const norm=v=>String(v||'').replace(/\s+/g,' ').trim().toLowerCase();
const leaves=()=>Array.from(document.querySelectorAll('*')).filter(el=>el.children.length===0);
const exact=text=>leaves().find(el=>norm(el.textContent)===norm(text))||null;

function addTopButton(anchorText,label){
    const anchor=exact(anchorText);
    if(!anchor)return;
    let holder=anchor.parentElement;
    for(let i=0;i<5&&holder;i++){
        const r=holder.getBoundingClientRect();
        if(r.width>500){break;}
        holder=holder.parentElement;
    }
    holder=holder||anchor.parentElement;
    if(!holder||holder.querySelector('.et-user-manage-link-103175'))return;
    const link=document.createElement('a');
    link.className='et-user-manage-link-103175';
    link.href=manageUrl;
    link.textContent=label;
    const actions=Array.from(holder.children).find(el=>norm(el.textContent)==='back')?.parentElement;
    if(actions){actions.insertBefore(link,actions.firstChild);}else{holder.appendChild(link);}
}

if(document.body.textContent.includes('ERP User Accounts')||document.body.textContent.includes('Login access, branch scope and roles')){
    addTopButton('Login access, branch scope and roles','Manage Existing Users');
    const label=exact('Linked Staff');
    if(label&&!document.querySelector('.et-user-manage-note-103175')){
        let field=label.parentElement;
        for(let i=0;i<4&&field&&!field.querySelector('select');i++){field=field.parentElement;}
        if(field){
            const note=document.createElement('div');
            note.className='et-user-manage-note-103175';
            note.innerHTML='<strong>Linked Staff:</strong> only staff without an existing ERP login appear here. Existing linked staff are managed from <a href="'+manageUrl+'">Manage Existing Users</a>. Staff email and Login Email are separate credentials; use <strong>Use Staff Email as Login</strong> when you intentionally want them synchronized.';
            field.appendChild(note);
        }
    }
    const usersHeading=exact('Users');
    if(usersHeading&&!usersHeading.parentElement?.querySelector('.et-user-manage-link-103175')){
        const link=document.createElement('a');
        link.className='et-user-manage-link-103175';
        link.href=manageUrl;
        link.textContent='Edit / Activate / Passwords';
        usersHeading.parentElement?.appendChild(link);
    }
}

if(document.body.textContent.includes('Staff identity is independent from ERP login')){
    addTopButton('Staff identity is independent from ERP login.','Manage ERP Logins');
}
})();
</script>
HTML;

        if (str_contains($content, '</body>')) {
            $content = str_replace('</body>', $script.'</body>', $content);
        } else {
            $content .= $script;
        }

        $response->setContent($content);

        return $response;
    }
}
