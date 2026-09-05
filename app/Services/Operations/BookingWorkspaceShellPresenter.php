<?php

namespace App\Services\Operations;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookingWorkspaceShellPresenter
{
    public function transform(Request $request, Response $response): Response
    {
        if (
            ! method_exists($response, 'getContent')
            || ! method_exists($response, 'setContent')
        ) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('content-type', ''));

        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $html = (string) $response->getContent();

        if (
            $html === ''
            || str_contains($html, 'data-et-booking-focus-shell="ERP-11.3.75"')
        ) {
            return $response;
        }

        $path = trim(strtolower($request->path()), '/');

        $isGroupWorkspace = (
            str_contains($html, 'id="gp-booking"')
            || str_contains($html, "id='gp-booking'")
            || str_contains($html, 'Create Group Umrah Booking')
            || str_contains($html, 'Edit Group Umrah Booking')
        );

        $isAirWorkspace = (
            str_contains($html, 'Air Ticket Batch Entry')
            && str_contains($html, 'Booking Workspace')
        );

        $isNativeBookingWorkspacePath = (
            preg_match('#^operations/bookings/\d+(?:/edit)?$#', $path) === 1
        );

        /*
         * ERP-11.3.46: native booking wizard steps are actual booking
         * workspaces too. They must not fall back to the permanent ERP sidebar
         * simply because their URL is nested below /operations/bookings/{id}.
         *
         * Confirmed current flow:
         *   Booking -> Passengers -> Group Package -> Services -> Review -> Confirm
         */
        $isNestedBookingWorkflowPath = (
            preg_match(
                '#^operations/bookings/\d+/.+$#',
                $path
            ) === 1
            && ! str_ends_with(
                $path,
                '/sales-invoice'
            )
        );

        $isDirectGroupWorkspacePath = (
            $path === 'operations/group-package-bookings/create'
            || preg_match('#^operations/group-package-bookings/\d+/edit$#', $path) === 1
        );

        /*
         * Keep the normal New Booking product selector on the standard ERP shell.
         * The same /operations/bookings/create URL becomes focused only when the
         * Group Umrah unified workspace has actually been rendered.
         */
        $shouldFocus = (
            $isGroupWorkspace
            || $isAirWorkspace
            || $isNativeBookingWorkspacePath
            || $isNestedBookingWorkflowPath
            || $isDirectGroupWorkspacePath
        );

        if (! $shouldFocus) {
            return $response;
        }

        /*
         * ERP-11.3.50: the standard/native Booking Workspace is the only
         * focused product still using a wider legacy outer canvas. Air and
         * unified Group Umrah already render at the desired focused width, so
         * do not alter those products again.
         */
        if (
            $isNativeBookingWorkspacePath
            && ! $isAirWorkspace
            && ! $isGroupWorkspace
        ) {
            $html = $this->addHtmlClass(
                $html,
                'et-booking-native-standard-canvas'
            );
        }

        $html = $this->addHtmlClass($html, 'et-booking-focus-prepaint');
        $html = $this->addHtmlClass($html, 'et-booking-unified-canvas-11375');

        /*
         * ERP-11.3.75: GENERAL and every native product Booking Workspace share
         * the same shell. Product type no longer changes outer content width.
         */
        if (
            str_contains(strtoupper($html), '>GENERAL<')
            || str_contains(strtoupper($html), '· GENERAL ·')
        ) {
            $html = $this->addHtmlClass(
                $html,
                'et-booking-type-general-11375'
            );
            $html = $this->addHtmlClass(
                $html,
                'et-general-progressive-step1-11390'
            );
        }

        /*
         * Old from-booking links are intentionally rewritten to the stable
         * booking-scoped Sales Invoice entry. The backward-compatible old URL
         * is also overridden in routes, but new rendered pages should stop
         * generating it entirely.
         */
        $html = preg_replace(
            '#(["\'])/sales/invoices/from-booking/(\d+)\1#i',
            '$1/operations/bookings/$2/sales-invoice$1',
            $html
        ) ?? $html;
        $html = preg_replace_callback(
            '#(["\'])https?://[^"\']+/sales/invoices/from-booking/(\d+)\1#i',
            static fn (array $match): string =>
                $match[1]
                .url(
                    '/operations/bookings/'
                    .(int) $match[2]
                    .'/sales-invoice'
                )
                .$match[1],
            $html
        ) ?? $html;

        $style = <<<'HTML'
<style data-et-booking-focus-shell="ERP-11.3.75">
/*
 * ERP-11.3.55 booking workspace shell.
 *
 * The sidebar is suppressed by server-rendered CSS before the browser paints.
 * Existing Air / Group Umrah Menu buttons remain authoritative; generic booking
 * workspaces receive the fallback Menu drawer from the app-routed script.
 */
html.et-booking-focus-prepaint,
html.et-booking-focus-prepaint body{
    overflow-x:hidden!important;
    max-width:100%!important;
}
html.et-booking-focus-prepaint .app-shell{
    grid-template-columns:minmax(0,1fr)!important;
    width:100%!important;
    max-width:100%!important;
}

/*
 * ERP-11.3.49 — focused booking visual contract.
 *
 * Every page that intentionally hides the permanent ERP sidebar uses the same
 * outer canvas. Native booking pages previously kept their old narrow
 * container-xl max-width while Group Package / Group Umrah expanded almost
 * edge-to-edge, so Step cards and headers visibly changed size between routes.
 */
html.et-booking-focus-prepaint{
    --et-booking-canvas-max:1280px;
    --et-booking-canvas-gutter:24px;
    --et-booking-section-gap:16px;
}
html.et-booking-focus-prepaint .page-wrapper > .page-header > .container,
html.et-booking-focus-prepaint .page-wrapper > .page-header > .container-xl,
html.et-booking-focus-prepaint .page-wrapper > .page-body > .container,
html.et-booking-focus-prepaint .page-wrapper > .page-body > .container-xl,
html.et-booking-focus-prepaint main > .container,
html.et-booking-focus-prepaint main > .container-xl,
html.et-booking-focus-prepaint .main-content > .container,
html.et-booking-focus-prepaint .main-content > .container-xl,
html.et-booking-focus-prepaint .page-content > .container,
html.et-booking-focus-prepaint .page-content > .container-xl,
html.et-booking-focus-prepaint [data-booking-workspace],
html.et-booking-focus-prepaint #gp-booking{
    width:calc(100% - (var(--et-booking-canvas-gutter) * 2))!important;
    max-width:var(--et-booking-canvas-max)!important;
    margin-left:auto!important;
    margin-right:auto!important;
    box-sizing:border-box!important;
}
/*
 * ERP-11.3.50 — standard/native Booking Workspace width alignment.
 *
 * The live BK-2026-000043 comparison proved its native shell uses a different
 * outer container shape than Air Booking, so the generic .container-xl rule
 * did not catch the real canvas. Scope a stronger first-paint rule ONLY to the
 * non-Air/non-unified native Booking Workspace. Its direct header/body canvas
 * now uses the same 1280px contract as the already-correct Air workspace.
 */
html.et-booking-focus-prepaint.et-booking-native-standard-canvas
    .page-wrapper > .page-header > [class*="container"],
html.et-booking-focus-prepaint.et-booking-native-standard-canvas
    .page-wrapper > .page-body > [class*="container"],
html.et-booking-focus-prepaint.et-booking-native-standard-canvas
    .page-header > [class*="container"],
html.et-booking-focus-prepaint.et-booking-native-standard-canvas
    .page-body > [class*="container"],
html.et-booking-focus-prepaint.et-booking-native-standard-canvas
    main > [class*="container"],
html.et-booking-focus-prepaint.et-booking-native-standard-canvas
    .main-content > [class*="container"],
html.et-booking-focus-prepaint.et-booking-native-standard-canvas
    .page-content > [class*="container"]{
    width:calc(100% - (var(--et-booking-canvas-gutter) * 2))!important;
    max-width:var(--et-booking-canvas-max)!important;
    margin-left:auto!important;
    margin-right:auto!important;
    box-sizing:border-box!important;
}

/*
 * Fallback for the native layout variant where the page body itself contains
 * the booking sections directly instead of a Bootstrap/Tabler container.
 */
html.et-booking-focus-prepaint.et-booking-native-standard-canvas
    .page-wrapper > .page-body > :not(script):not(style),
html.et-booking-focus-prepaint.et-booking-native-standard-canvas
    .page-body > :not(script):not(style){
    max-width:var(--et-booking-canvas-max)!important;
    margin-left:auto!important;
    margin-right:auto!important;
    box-sizing:border-box!important;
}

html.et-booking-focus-prepaint .page-wrapper > .page-header,
html.et-booking-focus-prepaint .page-header{
    min-height:68px;
}
html.et-booking-focus-prepaint .page-wrapper > .page-header > .container,
html.et-booking-focus-prepaint .page-wrapper > .page-header > .container-xl{
    min-height:68px;
    display:flex;
    align-items:center;
}
html.et-booking-focus-prepaint .page-body,
html.et-booking-focus-prepaint main,
html.et-booking-focus-prepaint .main-content,
html.et-booking-focus-prepaint .page-content{
    min-width:0!important;
}
html.et-booking-focus-prepaint .app-shell > .sidebar:not(.gp-focus-sidebar-open):not(.et-air-focus-sidebar-open-103172):not(.et-booking-focus-sidebar-open),
html.et-booking-focus-prepaint body > .sidebar:not(.gp-focus-sidebar-open):not(.et-air-focus-sidebar-open-103172):not(.et-booking-focus-sidebar-open),
html.et-booking-focus-prepaint .sidebar:not(.gp-focus-sidebar-open):not(.et-air-focus-sidebar-open-103172):not(.et-booking-focus-sidebar-open),
html.et-booking-focus-prepaint .navbar-vertical:not(.gp-focus-sidebar-open):not(.et-air-focus-sidebar-open-103172):not(.et-booking-focus-sidebar-open),
html.et-booking-focus-prepaint .side-nav:not(.gp-focus-sidebar-open):not(.et-air-focus-sidebar-open-103172):not(.et-booking-focus-sidebar-open){
    display:none!important;
}
html.et-booking-focus-prepaint .app-shell > main,
html.et-booking-focus-prepaint .app-shell > .main,
html.et-booking-focus-prepaint .app-shell > .content,
html.et-booking-focus-prepaint .app-shell > .main-content,
html.et-booking-focus-prepaint .app-shell > .page-wrapper{
    margin-left:0!important;
    width:100%!important;
    max-width:none!important;
    min-width:0!important;
}
html.et-booking-focus-prepaint [data-et-booking-focus-sidebar].et-booking-focus-sidebar-open{
    display:block!important;
    position:fixed!important;
    left:0!important;
    top:0!important;
    bottom:0!important;
    z-index:10020!important;
    overflow-y:auto!important;
    overflow-x:hidden!important;
    box-shadow:0 12px 40px rgba(0,0,0,.24)!important;
    transform:none!important;
}
.et-booking-focus-overlay{
    display:none;
    position:fixed;
    inset:0;
    z-index:10010;
    background:rgba(9,21,38,.38);
}
.et-booking-focus-overlay.open{display:block}
.et-booking-focus-fallback{
    display:inline-flex;
    align-items:center;
    gap:6px;
    margin:0;
    flex-wrap:nowrap;
    width:auto;
}
.et-booking-focus-page-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:10px;
    flex-wrap:wrap;
    width:auto;
}
.et-booking-focus-page-actions .et-booking-focus-fallback{
    flex:0 0 auto;
}
.et-booking-focus-fallback-inline{
    display:inline-flex;
    vertical-align:middle;
    margin-right:8px;
}
.et-booking-focus-page-actions > a{
    white-space:nowrap;
}
.et-booking-focus-fallback-standalone{
    display:flex;
    width:min(100% - 32px,1220px);
    margin:12px auto 0;
    justify-content:flex-start;
}
.et-booking-focus-btn{
    min-height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:6px 10px;
    border:1px solid #cbd7e5;
    border-radius:7px;
    background:#fff;
    color:#26384e!important;
    text-decoration:none!important;
    font-size:10px;
    line-height:1;
    font-weight:850;
    cursor:pointer;
    box-shadow:0 1px 2px rgba(21,39,64,.04);
}
.et-booking-focus-btn:hover{
    background:#f7f9fc;
    border-color:#b9c8da;
}
/*
 * Shared compact action geometry across native Booking Workspace and nested
 * product steps. Product-specific buttons keep their colors; only dimensions
 * and wrapping behavior are normalized.
 */
html.et-booking-focus-prepaint .et-booking-focus-page-actions,
html.et-booking-focus-prepaint .et-booking-focus-fallback{
    min-height:34px;
}
html.et-booking-focus-prepaint .et-booking-focus-page-actions{
    gap:7px;
}
html.et-booking-focus-prepaint .et-booking-focus-page-actions > a,
html.et-booking-focus-prepaint .et-booking-focus-page-actions > button,
html.et-booking-focus-prepaint .et-booking-focus-fallback > a,
html.et-booking-focus-prepaint .et-booking-focus-fallback > button{
    min-height:34px!important;
}
html.et-booking-focus-prepaint #gp-booking{
    padding-left:0!important;
    padding-right:0!important;
}
html.et-booking-focus-prepaint #gp-booking .gp-top{
    min-height:52px;
    align-items:center;
}
/*
 * ERP-11.3.51 — Air Booking uses the SAME native content box as the other
 * booking products. The Air workspace is already a child of section.content;
 * therefore it must fill that parent instead of creating another inset canvas.
 *
 * This rule also overrides stale inline width/max-width/negative-margin values
 * written by the pre-11.3.51 Air focus script.
 */
html.et-booking-focus-prepaint .et-air-workspace-103172{
    width:100%!important;
    max-width:none!important;
    min-width:0!important;
    margin-left:0!important;
    margin-right:0!important;
    padding-left:0!important;
    padding-right:0!important;
    box-sizing:border-box!important;
}

/*
 * ERP-11.3.55 — nested Group Package uses standard Booking Workspace hero.
 */
html[data-et-group-package-shell="ERP-11.3.55"]
    [data-et-group-package-top-title="ERP-11.3.55"]{
    color:#17243a!important;
    font-size:17px!important;
    line-height:1.2!important;
    font-weight:850!important;
    letter-spacing:-.01em!important;
}
html[data-et-group-package-shell="ERP-11.3.55"]
    [data-et-group-package-hero="ERP-11.3.55"]{
    min-width:0!important;
}
html[data-et-group-package-shell="ERP-11.3.55"]
    [data-et-group-package-kicker="ERP-11.3.55"]{
    margin:0 0 5px!important;
    color:#1266c3!important;
    font-size:10px!important;
    line-height:1.2!important;
    font-weight:900!important;
    letter-spacing:.12em!important;
    text-transform:uppercase!important;
}
html[data-et-group-package-shell="ERP-11.3.55"]
    [data-et-group-package-booking-title="ERP-11.3.55"]{
    margin:0!important;
    color:#17243a!important;
    font-size:26px!important;
    line-height:1.08!important;
    font-weight:850!important;
    letter-spacing:-.025em!important;
}
html[data-et-group-package-shell="ERP-11.3.55"]
    [data-et-group-package-booking-subtitle="ERP-11.3.55"]{
    display:block!important;
    margin-top:6px!important;
    color:#6b7a90!important;
    font-size:11px!important;
    line-height:1.4!important;
}
html[data-et-group-package-shell="ERP-11.3.55"]
    .et-booking-focus-page-actions{
    align-self:flex-start!important;
    margin-top:0!important;
}

/*
 * ERP-11.3.54 — Standard booking Transport / Other Services layout.
 *
 * The native page rendered Transport as the left column of a two-column block
 * and left "Other Services / Products" floating in the empty right column.
 * JavaScript marks only the exact operational-services DOM after matching the
 * live section labels. These rules then give both services the same full-width
 * card language used throughout Booking Workspace.
 */
html.et-booking-focus-prepaint [data-et-operational-services-layout="ERP-11.3.54"]{
    display:flex!important;
    flex-direction:column!important;
    align-items:stretch!important;
    justify-content:flex-start!important;
    gap:14px!important;
    width:100%!important;
    max-width:none!important;
    margin:0!important;
    padding:0!important;
    background:transparent!important;
    border:0!important;
    box-shadow:none!important;
    overflow:visible!important;
}
html.et-booking-focus-prepaint [data-et-operational-services-layout="ERP-11.3.54"]
    > [data-et-transport-service-panel="ERP-11.3.54"],
html.et-booking-focus-prepaint [data-et-operational-services-layout="ERP-11.3.54"]
    > [data-et-other-services-panel="ERP-11.3.54"]{
    display:block!important;
    width:100%!important;
    max-width:none!important;
    min-width:0!important;
    margin:0!important;
    padding:16px!important;
    box-sizing:border-box!important;
    background:#fff!important;
    border:1px solid #dbe4ef!important;
    border-radius:12px!important;
    box-shadow:0 8px 24px rgba(26,45,72,.045)!important;
}
html.et-booking-focus-prepaint [data-et-transport-service-panel="ERP-11.3.54"]{
    overflow:visible!important;
}
html.et-booking-focus-prepaint [data-et-transport-service-panel="ERP-11.3.54"]
    [data-et-operational-service-heading="transport"],
html.et-booking-focus-prepaint [data-et-other-services-panel="ERP-11.3.54"]
    [data-et-operational-service-heading="other-services"]{
    margin:0!important;
    padding:0!important;
    color:#17243a!important;
    font-size:16px!important;
    line-height:1.25!important;
    font-weight:850!important;
}
html.et-booking-focus-prepaint [data-et-transport-service-panel="ERP-11.3.54"]
    [data-et-operational-service-note="transport"],
html.et-booking-focus-prepaint [data-et-other-services-panel="ERP-11.3.54"]
    [data-et-operational-service-note="other-services"]{
    display:block!important;
    max-width:900px!important;
    margin:4px 0 12px!important;
    color:#6b7a90!important;
    font-size:11px!important;
    line-height:1.45!important;
}
html.et-booking-focus-prepaint [data-et-transport-service-panel="ERP-11.3.54"]
    form{
    width:100%!important;
    max-width:none!important;
    margin:0!important;
}
html.et-booking-focus-prepaint [data-et-transport-service-panel="ERP-11.3.54"]
    input,
html.et-booking-focus-prepaint [data-et-transport-service-panel="ERP-11.3.54"]
    select,
html.et-booking-focus-prepaint [data-et-transport-service-panel="ERP-11.3.54"]
    textarea{
    max-width:100%!important;
    box-sizing:border-box!important;
}
html.et-booking-focus-prepaint [data-et-other-services-panel="ERP-11.3.54"]{
    min-height:96px!important;
}
html.et-booking-focus-prepaint [data-et-other-services-panel="ERP-11.3.54"]
    [data-et-add-service-action]{
    margin-top:8px!important;
}
@media(max-width:760px){
    html.et-booking-focus-prepaint [data-et-operational-services-layout="ERP-11.3.54"]
        > [data-et-transport-service-panel="ERP-11.3.54"],
    html.et-booking-focus-prepaint [data-et-operational-services-layout="ERP-11.3.54"]
        > [data-et-other-services-panel="ERP-11.3.54"]{
        padding:12px!important;
        border-radius:10px!important;
    }
}
@media(max-width:760px){
    html.et-booking-focus-prepaint{
        --et-booking-canvas-gutter:10px;
    }
    .et-booking-focus-page-actions{
        justify-content:flex-start;
        gap:7px;
    }
    .et-booking-focus-fallback{
        flex-wrap:wrap;
    }
    .et-booking-focus-fallback-standalone{
        width:calc(100% - 20px);
        margin:8px 10px 0;
    }
}

/* ================================================================
 * ERP-11.3.75 — FULL BOOKING PRODUCT UI CONTRACT
 * ================================================================ */
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375{
    --et-booking-canvas-max:1280px;
    --et-booking-canvas-gutter:24px;
    --et-booking-card-radius:12px;
    --et-booking-card-padding:18px;
    --et-booking-border:#dbe4ef;
}
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375 section.content,
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375 main.main > section.content,
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375 .main > section.content{
    width:calc(100% - (var(--et-booking-canvas-gutter) * 2))!important;
    max-width:var(--et-booking-canvas-max)!important;
    min-width:0!important;
    margin-left:auto!important;
    margin-right:auto!important;
    padding-left:0!important;
    padding-right:0!important;
    box-sizing:border-box!important;
}
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    section.content > :not(style):not(script){
    max-width:none!important;
    min-width:0!important;
    box-sizing:border-box!important;
}
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375]{
    width:100%!important;
    max-width:none!important;
    min-width:0!important;
    margin-left:0!important;
    margin-right:0!important;
    box-sizing:border-box!important;
}
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="booking-header"],
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="passengers"],
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="air-ticket"],
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="saved-tickets"],
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="transport"],
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="other-services"]{
    border-radius:var(--et-booking-card-radius)!important;
}
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375] form,
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375] table,
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375] .table-responsive,
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375] [class*="tablewrap"]{
    width:100%!important;
    max-width:none!important;
    min-width:0!important;
    box-sizing:border-box!important;
}
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="transport"]
    input,
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="transport"]
    select,
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="transport"]
    textarea,
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="other-services"]
    input,
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="other-services"]
    select,
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="other-services"]
    textarea{
    max-width:100%!important;
    box-sizing:border-box!important;
}
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-operational-stack-11375]{
    display:flex!important;
    flex-direction:column!important;
    align-items:stretch!important;
    grid-template-columns:none!important;
    gap:16px!important;
    width:100%!important;
    max-width:none!important;
    min-width:0!important;
}
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-operational-stack-11375]
    > *{
    width:100%!important;
    max-width:none!important;
    min-width:0!important;
    grid-column:1 / -1!important;
    flex:0 0 auto!important;
}
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="passengers"]
    .passenger-table,
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    [data-et-booking-panel-11375="saved-tickets"]
    table{
    table-layout:auto!important;
}
html.et-booking-focus-prepaint.et-booking-unified-canvas-11375
    .et-booking-live-saving-11375{
    opacity:.72;
    pointer-events:none;
}
.et-booking-live-state-11375{
    position:fixed;
    top:16px;
    right:16px;
    z-index:13050;
    max-width:min(420px,calc(100vw - 32px));
    padding:10px 12px;
    border:1px solid #cfe0f2;
    border-radius:9px;
    background:#fff;
    color:#24364d;
    box-shadow:0 12px 36px rgba(15,23,42,.14);
    font-size:11px;
    font-weight:800;
    line-height:1.4;
}
.et-booking-live-state-11375.ok{
    border-color:#c9ead7;
    background:#edf9f2;
    color:#137749;
}
.et-booking-live-state-11375.error{
    border-color:#f1caca;
    background:#fff2f2;
    color:#a61b1b;
}
@media(max-width:760px){
    html.et-booking-focus-prepaint.et-booking-unified-canvas-11375{
        --et-booking-canvas-gutter:10px;
    }
}


/*
 * ERP-11.3.90 — GENERAL Step-1 no-layout-shift prepaint contract.
 *
 * ERP-11.3.87 final Step-1 canvas used 16px side gutters (32px total), while
 * the shared focused-booking prepaint still started with 24px gutters
 * (48px total). When the Step-1 renderer removed the shared canvas class the
 * page visibly widened by 16px. These rules make the FIRST paint geometry
 * identical to the final Step-1 geometry.
 *
 * This is deliberately inline/server-rendered so it does not wait for the
 * external Step-1 stylesheet to download.
 */
html.et-booking-focus-prepaint.et-general-progressive-step1-11390{
    --et-booking-canvas-max:1280px;
    --et-booking-canvas-gutter:16px;
}
html.et-booking-focus-prepaint.et-general-progressive-step1-11390
    section.content,
html.et-booking-focus-prepaint.et-general-progressive-step1-11390
    main.main > section.content,
html.et-booking-focus-prepaint.et-general-progressive-step1-11390
    .main > section.content{
    width:calc(100% - 32px)!important;
    max-width:1280px!important;
    min-width:0!important;
    margin-left:auto!important;
    margin-right:auto!important;
    padding:10px 0 24px!important;
    box-sizing:border-box!important;
    transition:none!important;
}
html.et-booking-focus-prepaint.et-general-progressive-step1-11390
    .page-wrapper > .page-header,
html.et-booking-focus-prepaint.et-general-progressive-step1-11390
    .page-header{
    min-height:50px!important;
    transition:none!important;
}
html.et-booking-focus-prepaint.et-general-progressive-step1-11390
    .page-wrapper > .page-header > .container,
html.et-booking-focus-prepaint.et-general-progressive-step1-11390
    .page-wrapper > .page-header > .container-xl,
html.et-booking-focus-prepaint.et-general-progressive-step1-11390
    .page-header > [class*="container"]{
    min-height:50px!important;
    transition:none!important;
}

/*
 * ERP-11.3.90 — prevent native GENERAL workspace flash.
 *
 * The recording proved the remaining jump was not width math. The native
 * GENERAL body was painted first, then replaced by Step 1 JavaScript.
 * Keep only section.content invisible while Step 1 is building. The global
 * ERP/user header remains visible normally.
 *
 * A CSS fallback animation reveals the native body after 6 seconds if the
 * Step-1 JavaScript asset cannot complete, so the page can never remain
 * permanently blank.
 */
html.et-booking-focus-prepaint.et-general-progressive-step1-11390{
    scrollbar-gutter:stable;
}
html.et-booking-focus-prepaint.et-general-progressive-step1-11390:not(.etgp-step1-ready-11390):not(.etgp-step1-fallback-11390)
    section.content > *{
    display:none!important;
}

</style>
HTML;

        if (stripos($html, '</head>') !== false) {
            $html = preg_replace(
                '/<\/head>/i',
                $style."\n</head>",
                $html,
                1
            ) ?? $html;
        } else {
            $html = $style.$html;
        }

        $script = '<script src="'
            .e(route('system.erp-assets.booking-focus'))
            .'?v=11.3.98" defer data-et-booking-focus-js="ERP-11.3.98"></script>';

        $stepOneStyle = '<link rel="stylesheet" href="'
            .e(route('system.erp-assets.general-progressive-step1-css'))
            .'?v=11.3.138" data-et-general-progressive-css="ERP-11.3.138">';

        $stepOneScript = '<script src="'
            .e(route('system.erp-assets.general-progressive-step1-js'))
            .'?v=11.3.138" defer data-et-general-progressive-js="ERP-11.3.138"></script>';

        if (
            str_contains($html, 'et-general-progressive-step1-11390')
            && ! str_contains($html, 'data-et-general-progressive-css="ERP-11.3.138"')
            && stripos($html, '</head>') !== false
        ) {
            $html = preg_replace(
                '/<\/head>/i',
                $stepOneStyle."\n</head>",
                $html,
                1
            ) ?? $html;
        }

        if (
            ! str_contains($html, 'data-et-booking-focus-js="ERP-11.3.98"')
            && stripos($html, '</body>') !== false
        ) {
            $scripts = $script;

            if (str_contains($html, 'et-general-progressive-step1-11390')) {
                $scripts .= "\n".$stepOneScript;
            }

            $html = preg_replace(
                '/<\/body>/i',
                $scripts."\n</body>",
                $html,
                1
            ) ?? $html;
        }

        $response->setContent($html);

        return $response;
    }

    private function addHtmlClass(string $html, string $class): string
    {
        return preg_replace_callback(
            '/<html\b([^>]*)>/i',
            static function (array $match) use ($class): string {
                $attrs = $match[1];

                if (preg_match('/\bclass=(["\'])(.*?)\1/i', $attrs, $classMatch)) {
                    $classes = trim($classMatch[2].' '.$class);
                    $replacement = 'class='.$classMatch[1].$classes.$classMatch[1];

                    return '<html'.preg_replace(
                        '/\bclass=(["\'])(.*?)\1/i',
                        $replacement,
                        $attrs,
                        1
                    ).'>';
                }

                return '<html'.$attrs.' class="'.$class.'">';
            },
            $html,
            1
        ) ?? $html;
    }
}
