@php
    $etAirBookingId=(int)data_get($booking ?? null,'id',0);
    $etAirSegments=collect();

    if(
        $etAirBookingId>0
        && \Illuminate\Support\Facades\Schema::hasTable('booking_itinerary_segments')
        && class_exists(\App\Models\BookingItinerarySegment::class)
    ){
        $etAirSegments=\App\Models\BookingItinerarySegment::query()
            ->where('booking_id',$etAirBookingId)
            ->orderBy('sort_order')
            ->orderBy('departure_at')
            ->get();
    }

    $etAirFirst=$etAirSegments->first();
    $etAirLast=$etAirSegments->last();
    $etAirPrimary=$etAirSegments->firstWhere('segment_type','outbound') ?: $etAirFirst;

    $etAirPnr=trim((string)($etAirSegments->pluck('pnr')->filter()->first() ?? ''));
    $etAirOrigin=strtoupper(trim((string)($etAirPrimary?->from_code ?? $etAirFirst?->from_code ?? '')));
    $etAirDestination=strtoupper(trim((string)($etAirPrimary?->to_code ?? $etAirLast?->to_code ?? '')));
    $etAirAirlineCode=strtoupper(trim((string)($etAirPrimary?->airline_code ?? '')));
    $etAirAirlineName=trim((string)($etAirPrimary?->airline_name ?? ''));
    $etAirDeparture=$etAirFirst?->departure_at?->format('Y-m-d\TH:i') ?? '';

    /*
     * ERP-10.31.72:
     * Native ticket validation requires origin_id / destination_id.
     * Resolve real IDs from existing ERP masters rather than submitting IATA
     * strings such as LHE / JED into an ID field.
     */
    $etAirResolveMasterId=static function(
        string $kind,
        string $code,
        string $name=''
    ): int {
        static $allTables=null;

        $code=strtoupper(trim($code));
        $name=trim($name);

        if($code==='' && $name===''){
            return 0;
        }

        if($allTables===null){
            $allTables=[];

            try{
                foreach(\Illuminate\Support\Facades\Schema::getTables() as $meta){
                    $table=is_array($meta)
                        ? ($meta['name'] ?? null)
                        : data_get($meta,'name');

                    if($table){
                        $allTables[]=(string)$table;
                    }
                }
            }catch(\Throwable){
                $allTables=[];
            }
        }

        $preferred=$kind==='airline'
            ? [
                'airlines',
                'travel_airlines',
                'airline_master',
                'airline_masters',
                'travel_airline_master',
                'travel_airline_masters',
            ]
            : [
                'airports',
                'travel_airports',
                'airport_master',
                'airport_masters',
                'airports_master',
                'travel_airport_master',
                'travel_airport_masters',
                'travel_locations',
                'locations',
                'destinations',
                'travel_destinations',
            ];

        $tables=array_values(
            array_unique(
                array_merge(
                    $preferred,
                    $allTables
                )
            )
        );

        $bestId=0;
        $bestScore=-1;

        foreach($tables as $table){
            try{
                if(
                    !\Illuminate\Support\Facades\Schema::hasTable($table)
                    || str_starts_with(strtolower($table),'booking_')
                ){
                    continue;
                }

                $columns=\Illuminate\Support\Facades\Schema::getColumnListing($table);

                $firstColumn=static function(array $candidates) use ($columns): ?string {
                    foreach($candidates as $candidate){
                        if(in_array($candidate,$columns,true)){
                            return $candidate;
                        }
                    }

                    return null;
                };

                $idColumn=$firstColumn([
                    'id',
                    $kind.'_id',
                    'airport_id',
                    'location_id',
                    'destination_id',
                    'airline_id',
                ]);

                if(!$idColumn){
                    continue;
                }

                $codeColumn=$firstColumn(
                    $kind==='airline'
                        ? ['iata_code','airline_code','code','iata','short_code']
                        : ['iata_code','airport_code','code','iata','short_code','location_code']
                );

                $nameColumn=$firstColumn(
                    $kind==='airline'
                        ? ['name','airline_name','title','display_name']
                        : ['name','airport_name','location_name','destination_name','title','display_name']
                );

                if(!$codeColumn && !$nameColumn){
                    continue;
                }

                $row=null;

                if($code!=='' && $codeColumn){
                    $row=\Illuminate\Support\Facades\DB::table($table)
                        ->whereIn(
                            $codeColumn,
                            array_values(
                                array_unique([
                                    $code,
                                    strtoupper($code),
                                    strtolower($code),
                                ])
                            )
                        )
                        ->first();
                }

                if(!$row && $name!=='' && $nameColumn){
                    $row=\Illuminate\Support\Facades\DB::table($table)
                        ->where($nameColumn,$name)
                        ->first();
                }

                if(!$row){
                    continue;
                }

                $resolvedId=(int)data_get($row,$idColumn,0);

                if($resolvedId<=0){
                    continue;
                }

                $tableName=strtolower($table);
                $score=0;

                if($kind==='airline'){
                    if(str_contains($tableName,'airline'))$score+=120;
                }else{
                    if(str_contains($tableName,'airport'))$score+=140;
                    if(str_contains($tableName,'location'))$score+=50;
                    if(str_contains($tableName,'destination'))$score+=45;
                    if(str_contains($tableName,'city'))$score+=20;
                }

                if(str_contains($tableName,'travel'))$score+=10;
                if($codeColumn)$score+=10;

                if($score>$bestScore){
                    $bestScore=$score;
                    $bestId=$resolvedId;
                }
            }catch(\Throwable){
                // Continue through available master tables.
            }
        }

        return $bestId;
    };

    $etAirOriginId=$etAirResolveMasterId(
        'airport',
        $etAirOrigin
    );

    $etAirDestinationId=$etAirResolveMasterId(
        'airport',
        $etAirDestination
    );

    $etAirAirlineId=$etAirResolveMasterId(
        'airline',
        $etAirAirlineCode,
        $etAirAirlineName
    );

    $etAirCanViewProfitability=app(
        \App\Services\Operations\BookingProfitabilityAuthority::class
    )->canView(auth()->user());

    $etAirStoreUrl=$etAirBookingId>0
        ? route('operations.bookings.itinerary-segments.store',$etAirBookingId)
        : '';

    $etAirUpdateTemplate=$etAirBookingId>0
        ? route('operations.bookings.itinerary-segments.update',[$etAirBookingId,'__SEGMENT_ID__'])
        : '';

    $etAirDestroyTemplate=$etAirBookingId>0
        ? route('operations.bookings.itinerary-segments.destroy',[$etAirBookingId,'__SEGMENT_ID__'])
        : '';
@endphp

<style id="et-air-fresh-style-103172">
/*
 * ERP-10.31.72 — AIR TICKET VISUAL SYSTEM
 * Exact sizing follows the current Group Umrah workspace:
 * titles 16px, notes 11.5px, labels 10px, fields 36px / 13px,
 * buttons 36px / 11.5px, radius 10px and 12px section rhythm.
 */
#et-air-fresh-103172,
#et-air-fresh-103172 *,
.et-air-workspace-103172,
.et-air-workspace-103172 *{box-sizing:border-box}

.et-air-workspace-103172,
#et-air-fresh-103172{
    --et-blue:#1769d2;
    --et-green:#15945b;
    --et-red:#c83b3b;
    --et-text:#17243a;
    --et-muted:#6f7d90;
    --et-line:#dfe7f0;
    --et-soft:#eef5ff;
    --et-success:#eaf8ef;
    color:var(--et-text);
    font-family:inherit;
}

#et-air-fresh-103172{
    display:none;
    width:100%;
    max-width:none;
    min-width:0;
    margin:0 0 12px;
}
#et-air-fresh-103172 .cardx{
    width:100%;
    background:#fff;
    border:1px solid var(--et-line);
    border-radius:10px;
    overflow:hidden;
    box-shadow:0 1px 2px rgba(20,35,55,.02);
}
#et-air-fresh-103172 .headx{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    padding:11px 14px;
    border-bottom:1px solid #e8edf3;
}
#et-air-fresh-103172 .kicker{
    font-size:11px;
    font-weight:800;
    letter-spacing:.06em;
    color:var(--et-blue);
    text-transform:uppercase;
    margin-bottom:3px;
}
#et-air-fresh-103172 .title{
    font-size:16px;
    line-height:1.2;
    font-weight:800;
    color:var(--et-text);
}
#et-air-fresh-103172 .sub{
    margin-top:2px;
    font-size:11.5px;
    line-height:1.35;
    color:var(--et-muted);
}
#et-air-fresh-103172 .meta{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:5px;
    flex-wrap:wrap;
}
#et-air-fresh-103172 .chip{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:3px 6px;
    font-size:9px;
    font-weight:800;
    background:#e9f7ef;
    color:#147849;
    border:0;
    white-space:nowrap;
}
#et-air-fresh-103172 .chip.blue{
    background:#e9f2ff;
    color:var(--et-blue);
}
#et-air-fresh-103172 .tablewrap{
    width:100%;
    overflow-x:auto;
    overflow-y:hidden;
    padding:0 14px 2px;
    scrollbar-width:thin;
}
#et-air-fresh-103172 table{
    width:100%;
    min-width:965px;
    border-collapse:collapse;
}
#et-air-fresh-103172 th,
#et-air-fresh-103172 td{
    padding:6px 5px;
    border-bottom:1px solid #edf1f5;
    text-align:left;
    vertical-align:middle;
    white-space:nowrap;
}
#et-air-fresh-103172 th{
    color:#536176;
    font-size:9px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.02em;
}
#et-air-fresh-103172 td{
    color:var(--et-text);
    font-size:11.5px;
}
#et-air-fresh-103172 tbody tr:last-child td{border-bottom:0}
#et-air-fresh-103172 .pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    text-align:center;
    padding:4px 5px;
    font-size:9px;
    font-weight:800;
    text-transform:capitalize;
    background:#e9f2ff;
    color:var(--et-blue);
}
#et-air-fresh-103172 .pill.return{background:#e8f7ee;color:#137a49}
#et-air-fresh-103172 .pill.connection{background:#fff3da;color:#8c6100}
#et-air-fresh-103172 .pill.other{background:#eff2f6;color:#536176}
#et-air-fresh-103172 .status{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:3px 6px;
    font-size:9px;
    font-weight:800;
    background:#e9f7ef;
    color:#147849;
}
#et-air-fresh-103172 .toolbar{
    display:flex;
    align-items:center;
    gap:5px;
    flex-wrap:wrap;
    padding:8px 14px 9px;
}
#et-air-fresh-103172 .btnx,
.et-air-workspace-103172 .et-air-ui-btn-103172,
.et-air-focus-btn-103172{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:36px;
    padding:7px 12px;
    border:1px solid transparent;
    border-radius:5px;
    background:#eef2f7;
    color:#2b3a50;
    font:inherit;
    font-size:11.5px;
    font-weight:800;
    cursor:pointer;
    text-decoration:none;
    white-space:nowrap;
}
#et-air-fresh-103172 .btnx.primary{background:var(--et-blue);color:#fff}
#et-air-fresh-103172 .btnx.green{background:var(--et-green);color:#fff}
#et-air-fresh-103172 .btnx.danger{
    background:#fff1f1;
    color:var(--et-red);
    border-color:#f2cece;
}
#et-air-fresh-103172 .editor{
    display:none;
    margin:0 14px 9px;
    padding:11px 14px;
    border:1px solid var(--et-line);
    border-radius:7px;
    background:#fafcff;
}
#et-air-fresh-103172 .editor.open{display:block}
#et-air-fresh-103172 .editorhead{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    margin-bottom:8px;
}
#et-air-fresh-103172 .editorhead strong{
    font-size:16px;
    font-weight:800;
}
#et-air-fresh-103172 .grid{
    display:grid;
    grid-template-columns:repeat(12,minmax(0,1fr));
    gap:7px;
    align-items:end;
}
#et-air-fresh-103172 .field{grid-column:span 2;min-width:0}
#et-air-fresh-103172 .field.w3{grid-column:span 3}
#et-air-fresh-103172 label,
.et-air-workspace-103172 .et-air-native-label-103172{
    display:block;
    margin:0 0 3px;
    color:#4b5a70;
    font-size:10px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.02em;
}
#et-air-fresh-103172 .input{
    display:block;
    width:100%;
    min-width:0;
    height:36px;
    border:1px solid #d3deea;
    border-radius:5px;
    background:#fff;
    padding:7px 9px;
    font:inherit;
    font-size:13px;
    color:var(--et-text);
    outline:none;
}
#et-air-fresh-103172 .input:focus{
    border-color:#70a8e5;
    box-shadow:0 0 0 2px rgba(23,105,210,.07);
}

#et-air-fresh-103172 .et-air-live-itinerary-103169{
    min-width:1180px;
}
#et-air-fresh-103172 .et-air-row-input-103169{
    width:100%;
    min-width:72px;
    height:32px;
    border:1px solid #d6e0eb;
    border-radius:5px;
    background:#fff;
    padding:5px 6px;
    font:inherit;
    font-size:11px;
    color:var(--et-text);
    outline:none;
}
#et-air-fresh-103172 .et-air-row-input-103169:focus{
    border-color:#70a8e5;
    box-shadow:0 0 0 2px rgba(23,105,210,.07);
}
#et-air-fresh-103172 tr[data-et-segment-new="1"]{
    background:#f8fbff;
}
#et-air-fresh-103172 .et-air-save-state-103169{
    display:inline-flex;
    align-items:center;
    min-width:58px;
    justify-content:center;
    border-radius:999px;
    padding:3px 6px;
    font-size:8.5px;
    font-weight:900;
    white-space:nowrap;
    background:#eef2f7;
    color:#65758a;
}
#et-air-fresh-103172 .et-air-save-state-103169.is-saving{
    background:#fff3da;
    color:#8c6100;
}
#et-air-fresh-103172 .et-air-save-state-103169.is-saved{
    background:#e9f7ef;
    color:#147849;
}
#et-air-fresh-103172 .et-air-save-state-103169.is-error{
    background:#fff1f1;
    color:#b42318;
}
#et-air-fresh-103172 .et-air-live-help-103169{
    margin-left:auto;
    color:#64748b;
    font-size:9.5px;
    font-weight:700;
}
.et-booking-live-notice-103169{
    position:fixed;
    right:18px;
    top:18px;
    z-index:12050;
    max-width:min(440px,calc(100vw - 36px));
    border-radius:8px;
    padding:10px 12px;
    box-shadow:0 12px 35px rgba(15,23,42,.18);
    font-size:11px;
    font-weight:800;
    line-height:1.4;
    background:#eaf8ef;
    color:#137a49;
    border:1px solid #c8ecd7;
}
.et-booking-live-notice-103169.is-error{
    background:#fff1f1;
    color:#b42318;
    border-color:#efcaca;
}
.et-air-ticket-defaults-103172 [data-et-derived-ticket-status]{
    min-height:36px;
    display:flex;
    align-items:center;
    border:1px solid #d3deea;
    border-radius:5px;
    background:#f7fafc;
    padding:7px 9px;
    color:#24364d;
    font-size:12px;
    font-weight:800;
}

#et-air-fresh-103172 .policy{
    margin:0 14px 11px;
    padding:7px 9px;
    border-radius:6px;
    background:var(--et-soft);
    color:#2b5f9d;
    font-size:10.5px;
    line-height:1.35;
}

/* Entire native Air booking workspace uses the same ERP type/field scale. */
.et-air-workspace-103172 label{
    font-size:10px !important;
    font-weight:800 !important;
    color:#4b5a70 !important;
}
.et-air-workspace-103172 input:not([type="checkbox"]):not([type="radio"]),
.et-air-workspace-103172 select,
.et-air-workspace-103172 textarea{
    font-size:13px !important;
    border-radius:5px !important;
}
.et-air-workspace-103172 input:not([type="checkbox"]):not([type="radio"]),
.et-air-workspace-103172 select{
    min-height:36px;
}
.et-air-workspace-103172 button,
.et-air-workspace-103172 a.btn,
.et-air-workspace-103172 a[class*="btn"]{
    font-size:11.5px;
}
.et-air-workspace-103172 table th{
    font-size:9px !important;
    font-weight:800 !important;
    text-transform:uppercase;
    letter-spacing:.02em;
}
.et-air-workspace-103172 table td{
    font-size:11.5px;
}

/* Major native page/card titles aligned to gp-card-title/card-note. */
.et-air-workspace-103172 .et-air-major-title-103172{
    font-size:16px !important;
    line-height:1.2 !important;
    font-weight:800 !important;
    color:var(--et-text) !important;
}
.et-air-workspace-103172 .et-air-major-note-103172{
    font-size:11.5px !important;
    line-height:1.35 !important;
    color:var(--et-muted) !important;
}

/* Air Batch section cards. */
.et-air-batch-103172{
    width:100%;
    max-width:none;
}
.et-air-batch-103172 .et-air-section-103172{
    width:100% !important;
    max-width:100% !important;
    min-width:0 !important;
    height:auto !important;
    min-height:0 !important;
    margin:0 0 12px !important;
    border:1px solid var(--et-line) !important;
    border-radius:10px !important;
    background:#fff !important;
    overflow:hidden !important;
    box-shadow:0 1px 2px rgba(20,35,55,.02) !important;
}
.et-air-batch-103172 .et-air-section-title-103172{
    font-size:16px !important;
    line-height:1.2 !important;
    font-weight:800 !important;
    color:var(--et-text) !important;
}
.et-air-batch-103172 .et-air-section-note-103172{
    font-size:11.5px !important;
    line-height:1.35 !important;
    color:var(--et-muted) !important;
}
.et-air-batch-103172 .et-air-ticket-row-103172{
    display:block !important;
    width:100% !important;
    max-width:100% !important;
    height:auto !important;
    min-height:0 !important;
}
.et-air-batch-103172 .et-air-ticket-branch-103172{
    display:block !important;
    width:100% !important;
    max-width:100% !important;
    flex:1 1 100% !important;
    grid-column:1/-1 !important;
    margin:0 !important;
}
.et-air-batch-103172 .et-air-hidden-common-branch-103172{
    display:none !important;
}

/* Native batch KPI/review boxes copied from Group Umrah review boxes. */
.et-air-batch-103172 .et-air-kpi-grid-103172,
.et-air-batch-103172 .et-air-summary-grid-103172{
    display:grid !important;
    grid-template-columns:repeat(4,minmax(0,1fr)) !important;
    gap:6px !important;
}
.et-air-batch-103172 .et-air-summary-grid-103172{
    grid-template-columns:repeat(7,minmax(0,1fr)) !important;
}
.et-air-batch-103172 .et-air-kpi-card-103172,
.et-air-batch-103172 .et-air-summary-card-103172{
    min-width:0 !important;
    border:1px solid #e0e7ef !important;
    border-radius:6px !important;
    padding:7px !important;
    background:#fbfcfe !important;
}
.et-air-batch-103172 .et-air-kpi-card-103172 .et-air-metric-label-103172,
.et-air-batch-103172 .et-air-summary-card-103172 .et-air-metric-label-103172{
    font-size:10px !important;
    color:#6b798c !important;
    line-height:1.1 !important;
}
.et-air-batch-103172 .et-air-kpi-card-103172 .et-air-metric-value-103172,
.et-air-batch-103172 .et-air-summary-card-103172 .et-air-metric-value-103172{
    font-size:16px !important;
    font-weight:800 !important;
    margin-top:1px !important;
    color:var(--et-text) !important;
}

/* Ticket defaults follow gp-info + gp-input sizing. */
.et-air-batch-103172 .et-air-ticket-defaults-103172{
    display:grid;
    grid-template-columns:180px minmax(180px,.8fr) minmax(180px,.8fr) minmax(300px,1.4fr);
    gap:7px;
    align-items:end;
    margin:0 0 12px;
    padding:9px;
    border:1px solid #d8e5f5;
    border-radius:6px;
    background:#f6faff;
}
.et-air-batch-103172 .et-air-ticket-defaults-103172 label{
    font-size:10px !important;
    margin-bottom:3px;
}
.et-air-batch-103172 .et-air-ticket-defaults-103172 select{
    height:36px !important;
    padding:7px 9px !important;
    font-size:13px !important;
    border:1px solid #d3deea !important;
    border-radius:5px !important;
    background:#fff;
}
.et-air-batch-103172 .et-air-derived-103172{
    min-height:36px;
    display:flex;
    align-items:center;
    padding:7px 9px;
    border:1px solid #d3deea;
    border-radius:5px;
    background:#fff;
    color:#526176;
    font-size:11.5px;
}

/* Native ticket rows and fare panels. */
.et-air-batch-103172 .et-air-ticket-section-103172{
    width:100% !important;
}
.et-air-batch-103172 .et-air-ticket-section-103172 table{
    width:100% !important;
}
.et-air-batch-103172 .et-air-unsaved-103172{
    display:inline-flex;
    align-items:center;
    margin-left:6px;
    padding:3px 6px;
    border-radius:999px;
    border:1px solid #edcf83;
    background:#fff8e6;
    color:#775513;
    font-size:9px;
    font-weight:850;
}
.et-air-batch-103172 .et-air-formula-audit-103172{
    display:flex;
    align-items:center;
    gap:6px;
    flex-wrap:wrap;
    margin:8px 0 0;
    padding:7px 9px;
    border-radius:6px;
    background:var(--et-soft);
    color:#2b5f9d;
    font-size:10.5px;
}
.et-air-batch-103172 .auditpass,
.et-air-batch-103172 .auditreview{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:3px 6px;
    font-size:9px;
    font-weight:800;
}
.et-air-batch-103172 .auditpass{background:#e9f7ef;color:#147849}
.et-air-batch-103172 .auditreview{background:#fff8e6;color:#775513}
.et-air-batch-103172 .et-air-sensitive-hidden-103172{display:none !important}

/* Pricing and commercial native sub-panels get the same subtle ERP surface. */
.et-air-batch-103172 .et-air-subpanel-103172{
    min-width:0 !important;
    border:1px solid #e0e7ef !important;
    border-radius:6px !important;
    padding:7px !important;
    background:#fbfcfe !important;
}
.et-air-batch-103172 .et-air-subpanel-title-103172{
    font-size:11.5px !important;
    font-weight:800 !important;
    color:#32445d !important;
}

/* Focus shell mirrors Group Umrah compact toolbar. */
.et-air-focus-toolbar-103172{
    /* ERP-11.3.52: legacy Air-only shell retired. */
    display:none !important;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin:0 0 12px;
    padding:9px 11px;
    border:1px solid var(--et-line);
    border-radius:10px;
    background:#fff;
}
.et-air-focus-toolbar-103172 .et-air-focus-kicker-103172{
    font-size:11px;
    font-weight:800;
    letter-spacing:.06em;
    color:var(--et-blue);
    text-transform:uppercase;
}
.et-air-focus-toolbar-103172 .et-air-focus-title-103172{
    margin-top:3px;
    font-size:16px;
    font-weight:800;
    color:var(--et-text);
}
.et-air-focus-actions-103172{display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.et-air-focus-overlay-103172{
    display:none;
    position:fixed;
    inset:0;
    z-index:9997;
    background:rgba(10,22,40,.35);
}
.et-air-focus-overlay-103172.open{display:block}
[data-et-air-focus-sidebar].et-air-focus-sidebar-open-103172{
    display:block !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    bottom:0 !important;
    z-index:9998 !important;
    overflow:auto !important;
    box-shadow:0 10px 36px rgba(0,0,0,.22) !important;
}
/* ERP-11.3.52: native booking/user header remains visible on Air. */
[data-et-air-focus-native-header]{display:revert !important}
body.et-air-focus-mode-103172{overflow-x:hidden !important}

/* Laravel/session flash messages moved into focused Air workspace. */
.et-air-focus-notices-103172{
    display:flex;
    flex-direction:column;
    gap:7px;
    width:100%;
    margin:0 0 12px;
}
.et-air-focus-notice-103172{
    display:block !important;
    width:100% !important;
    max-width:none !important;
    min-width:0 !important;
    margin:0 !important;
    padding:9px 11px !important;
    border:1px solid #d8e5f5 !important;
    border-radius:6px !important;
    box-shadow:none !important;
    font-size:11.5px !important;
    line-height:1.4 !important;
    font-weight:700 !important;
    text-align:left !important;
}
.et-air-focus-notice-103172.et-success{
    background:#eaf8ef !important;
    border-color:#cce9d7 !important;
    color:#17643f !important;
}
.et-air-focus-notice-103172.et-error{
    background:#fff1f1 !important;
    border-color:#f2cece !important;
    color:#9e3030 !important;
}
.et-air-focus-notice-103172.et-warning{
    background:#fff8e6 !important;
    border-color:#ecd9a6 !important;
    color:#775513 !important;
}
.et-air-focus-notice-103172.et-info{
    background:#eef5ff !important;
    border-color:#d8e5f5 !important;
    color:#2b5f9d !important;
}
.et-air-focus-notice-103172 ul{
    margin:4px 0 0 18px !important;
    padding:0 !important;
}
.et-air-focus-notice-103172 li{
    margin:2px 0 !important;
}

@media(max-width:1250px){
    .et-air-batch-103172 .et-air-summary-grid-103172{
        grid-template-columns:repeat(4,minmax(120px,1fr)) !important;
    }
    .et-air-batch-103172 .et-air-ticket-defaults-103172{
        grid-template-columns:1fr 1fr;
    }
}
@media(max-width:1050px){
    #et-air-fresh-103172 .field,
    #et-air-fresh-103172 .field.w3{grid-column:span 4}
    .et-air-batch-103172 .et-air-kpi-grid-103172{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }
}
@media(max-width:700px){
    #et-air-fresh-103172 .grid{grid-template-columns:1fr}
    #et-air-fresh-103172 .field,
    #et-air-fresh-103172 .field.w3{grid-column:1}
    .et-air-batch-103172 .et-air-kpi-grid-103172,
    .et-air-batch-103172 .et-air-summary-grid-103172,
    .et-air-batch-103172 .et-air-ticket-defaults-103172{
        grid-template-columns:1fr !important;
    }
    .et-air-focus-toolbar-103172{align-items:flex-start}
}

[data-et-air-focus-sidebar]:not(.et-air-focus-sidebar-open-103172){display:none!important}
</style>

<section id="et-air-fresh-103172" data-et-air-live-entry="ERP-11.3.71"
    data-booking-id="{{ $etAirBookingId }}"
    data-store-url="{{ $etAirStoreUrl }}"
    data-update-template="{{ $etAirUpdateTemplate }}"
    data-destroy-template="{{ $etAirDestroyTemplate }}"
    data-csrf="{{ csrf_token() }}"
    data-pnr="{{ $etAirPnr }}"
    data-origin="{{ $etAirOrigin }}"
    data-origin-id="{{ $etAirOriginId > 0 ? $etAirOriginId : '' }}"
    data-destination="{{ $etAirDestination }}"
    data-destination-id="{{ $etAirDestinationId > 0 ? $etAirDestinationId : '' }}"
    data-airline-code="{{ $etAirAirlineCode }}"
    data-airline-name="{{ $etAirAirlineName }}"
    data-airline-id="{{ $etAirAirlineId > 0 ? $etAirAirlineId : '' }}"
    data-departure="{{ $etAirDeparture }}"
    data-can-view-profitability="{{ $etAirCanViewProfitability ? '1' : '0' }}">

<div class="cardx">
    <div class="headx">
        <div>
            <div class="kicker">Air Ticketing · Step 1</div>
            <div class="title">1. Common Flight Itinerary</div>
            <div class="sub">Enter every sector once. Passenger ticket numbers and fare details remain passenger-specific.</div>
        </div>
        <div class="meta">
            <span class="chip blue">{{ $etAirSegments->count() }} segment(s)</span>
            @if($etAirPnr !== '')<span class="chip">PNR {{ $etAirPnr }}</span>@endif
            @if($etAirOrigin !== '' || $etAirDestination !== '')<span class="chip">{{ $etAirOrigin ?: '—' }} → {{ $etAirDestination ?: '—' }}</span>@endif
        </div>
    </div>

    <div class="tablewrap">
        <table class="et-air-live-itinerary-103169">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Type</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Airline</th>
                    <th>Flight</th>
                    <th>Departure</th>
                    <th>Arrival</th>
                    <th>PNR</th>
                    <th>Status</th>
                    <th>Save</th>
                    <th></th>
                </tr>
            </thead>
            <tbody data-et-segment-body>
            @forelse($etAirSegments as $i=>$segment)
                @php
                    $st=strtolower(trim((string)$segment->segment_type));
                    $sc=in_array($st,['outbound','return','connection'],true)?$st:'other';
                @endphp
                <tr
                    data-et-segment-row
                    data-et-segment-id="{{ (int)$segment->id }}"
                    data-et-segment-type="{{ $st ?: 'outbound' }}"
                >
                    <td data-et-segment-index>{{ $i+1 }}</td>
                    <td>
                        <span class="pill {{ $sc }}">{{ ucfirst($st ?: 'segment') }}</span>
                        <input type="hidden" data-et-segment-field="segment_type" value="{{ $st ?: 'outbound' }}">
                    </td>
                    <td><input class="et-air-row-input-103169" data-et-segment-field="from_code" value="{{ strtoupper((string)$segment->from_code) }}" placeholder="LHE"></td>
                    <td><input class="et-air-row-input-103169" data-et-segment-field="to_code" value="{{ strtoupper((string)$segment->to_code) }}" placeholder="JED"></td>
                    <td><input class="et-air-row-input-103169" data-et-segment-field="airline_name" value="{{ $segment->airline_name ?: $segment->airline_code }}" placeholder="Saudia / SV"></td>
                    <td><input class="et-air-row-input-103169" data-et-segment-field="flight_number" value="{{ (string)$segment->flight_number }}" placeholder="SV739"></td>
                    <td><input type="datetime-local" class="et-air-row-input-103169" data-et-segment-field="departure_at" value="{{ $segment->departure_at?->format('Y-m-d\TH:i') }}"></td>
                    <td><input type="datetime-local" class="et-air-row-input-103169" data-et-segment-field="arrival_at" value="{{ $segment->arrival_at?->format('Y-m-d\TH:i') }}"></td>
                    <td><input class="et-air-row-input-103169" data-et-segment-field="pnr" value="{{ (string)$segment->pnr }}" placeholder="ABC123"></td>
                    <td>
                        <select class="et-air-row-input-103169" data-et-segment-field="status">
                            @foreach(['requested'=>'Requested','booked'=>'Booked','confirmed'=>'Confirmed','issued'=>'Issued'] as $value=>$label)
                                <option value="{{ $value }}" {{ strtolower((string)$segment->status)===$value?'selected':'' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td><span class="et-air-save-state-103169 is-saved" data-et-segment-save-state>Saved</span></td>
                    <td><button type="button" class="btnx danger" data-et-remove-segment="{{ (int)$segment->id }}">Remove</button></td>
                </tr>
            @empty
                <tr data-et-segment-empty><td colspan="12" style="padding:14px;color:#748197">No flight sector entered yet. Click Outbound / Return / Connection and enter the row directly.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="toolbar">
        <button type="button" class="btnx primary" data-et-add-flight="outbound">+ Add Outbound</button>
        <button type="button" class="btnx green" data-et-add-flight="return">+ Add Return Flight</button>
        <button type="button" class="btnx" data-et-add-flight="connection">+ Add Connection</button>
        <span class="et-air-live-help-103169">Rows save silently when required flight fields are complete. No page reload.</span>
    </div>

    <div class="policy">Shared itinerary prevents duplicate sector entry for each passenger. Existing native ticket/fare/accounting logic remains authoritative.</div>
</div>
</section>

<script id="et-air-fresh-script-103172">
(function(){
'use strict';

const root=document.getElementById('et-air-fresh-103172');
if(!root||root.dataset.initialized==='1')return;
root.dataset.initialized='1';

const norm=v=>String(v||'').replace(/\s+/g,' ').trim().toLowerCase();
const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');

function visible(el){
    try{
        const s=getComputedStyle(el),r=el.getBoundingClientRect();
        return s.display!=='none'&&s.visibility!=='hidden'&&r.width>0&&r.height>0;
    }catch(_){return false}
}

function leaf(scope,labels){
    const wanted=(Array.isArray(labels)?labels:[labels]).map(norm);
    return Array.from(scope.querySelectorAll('*')).find(el=>{
        if(el===root||root.contains(el)||el.children.length>5)return false;
        const t=norm(el.textContent);
        return wanted.some(x=>t===x||t.startsWith(x+' '));
    })||null;
}

function findBatch(){
    const heading=Array.from(
        document.querySelectorAll('*')
    ).find(el=>
        el.children.length<5
        && norm(el.textContent)==='air ticket batch entry'
    );

    if(!heading)return null;

    /*
     * ERP-10.31.72:
     * Resolve the Air Ticket Batch Entry card itself, never the whole Booking
     * Workspace. The previous broad content test could climb to the workspace,
     * which is why Step 1 appeared above BK-... and the KPI/header cards.
     */
    let best=null;
    let c=heading.parentElement;

    for(let i=0;i<10&&c&&c!==document.body;i++){
        const t=norm(c.textContent);

        const hasTicketing=
            t.includes('air ticket batch entry')
            && t.includes('ticket numbers')
            && t.includes('passenger-type fare entry')
            && t.includes('commercial parties')
            && t.includes('saved passenger tickets');

        const escapedIntoWorkspace=
            t.includes('booking workspace')
            || t.includes('booking header')
            || (
                t.includes('booking workflow')
                && t.includes('client voucher')
            );

        if(hasTicketing&&!escapedIntoWorkspace){
            best=c;
        }

        if(
            escapedIntoWorkspace
            && best
        ){
            break;
        }

        c=c.parentElement;
    }

    if(best)return best;

    /*
     * Native shells can split the Air Ticket card into nested wrappers.
     * In that case use the nearest card/panel around the Air Ticket heading,
     * provided it does not contain the Booking Workspace title.
     */
    c=heading.parentElement;

    for(let i=0;i<8&&c&&c!==document.body;i++){
        const t=norm(c.textContent);

        if(
            !t.includes('booking workspace')
            && !t.includes('booking header')
            && (
                c.matches?.(
                    '.card,section,[class*="card"],[class*="panel"]'
                )
                || c.getBoundingClientRect().width>500
            )
        ){
            return c;
        }

        c=c.parentElement;
    }

    return null;
}

function section(batch,labels){
    const h=leaf(batch,labels);
    if(!h)return null;
    let c=h;
    for(let i=0;i<7&&c&&c!==batch;i++){
        const p=c.parentElement;
        if(!p||p===batch)return c;
        if(p.matches?.('.card,section,[class*="card"],[class*="panel"]')&&p.getBoundingClientRect().width>180)return p;
        c=p;
    }
    return c;
}

function commonBlock(batch){
    const h=leaf(batch,['1. Common PNR & Flight Details','Common PNR & Flight Details','1. Common PNR and Flight Details','Common PNR and Flight Details']);
    if(!h)return null;
    let c=h;
    for(let i=0;i<7&&c&&c!==batch;i++){
        const t=norm(c.textContent);
        if(t.includes('common pnr')&&!t.includes('passenger-type fare entry')&&!t.includes('commercial parties')){
            const p=c.parentElement;
            if(p&&norm(p.textContent).includes('ticket numbers'))return c;
        }
        c=c.parentElement;
    }
    return h.parentElement;
}

function commonTicketContainer(batch,common,tickets){
    if(!common||!tickets)return null;
    let c=common.parentElement;
    for(let i=0;i<6&&c&&c!==batch;i++){
        if(c.contains(tickets))return c;
        c=c.parentElement;
    }
    return null;
}

const batch=findBatch();
if(!batch){
    document.documentElement.classList.remove('et-air-preload-103172');
    return;
}
batch.classList.add('et-air-batch-103172');

const commonHeading=leaf(batch,[
    '1. Common PNR & Flight Details',
    'Common PNR & Flight Details',
    '1. Common PNR and Flight Details',
    'Common PNR and Flight Details'
]);
const ticketHeading=leaf(batch,[
    '2. Ticket Numbers',
    '2. Passenger Ticket Numbers',
    'Ticket Numbers',
    'Passenger Ticket Numbers'
]);
const fareHeading=leaf(batch,[
    '3. Passenger-Type Fare Entry',
    'Passenger-Type Fare Entry',
    'Passenger Type Fare Entry'
]);
const commercialHeading=leaf(batch,[
    '4. Commercial Parties & Commissions',
    'Commercial Parties & Commissions',
    'Commercial Parties and Commissions'
]);
const savedHeading=leaf(batch,['Saved Passenger Tickets']);
const manageHeading=leaf(batch,['Manage service passenger links']);

function nearestCommonAncestor(a,b,stop){
    if(!a||!b)return null;
    const ancestors=new Set();
    let c=a;
    while(c&&c!==stop){
        ancestors.add(c);
        c=c.parentElement;
    }
    c=b;
    while(c&&c!==stop){
        if(ancestors.has(c))return c;
        c=c.parentElement;
    }
    return null;
}

function childUnder(ancestor,node){
    if(!ancestor||!node||!ancestor.contains(node))return null;
    let c=node;
    while(c.parentElement&&c.parentElement!==ancestor){
        c=c.parentElement;
    }
    return c.parentElement===ancestor?c:null;
}

function sectionUntil(currentHeading,nextHeading,stop){
    if(!currentHeading)return null;
    let c=currentHeading;
    while(c.parentElement&&c.parentElement!==stop){
        const p=c.parentElement;
        if(nextHeading&&p.contains(nextHeading)){
            return c;
        }
        c=p;
    }
    return c;
}

const commonTicketRow=nearestCommonAncestor(
    commonHeading,
    ticketHeading,
    batch
);

let common=null;
let tickets=null;

if(commonTicketRow&&commonTicketRow!==batch){
    const commonBranch=childUnder(
        commonTicketRow,
        commonHeading
    );
    const ticketBranch=childUnder(
        commonTicketRow,
        ticketHeading
    );

    if(commonBranch){
        common=commonBranch;
        commonBranch.classList.add(
            'et-air-hidden-common-branch-103172'
        );
        commonBranch.setAttribute(
            'data-et-air-native-common-fields',
            '1'
        );
    }

    if(ticketBranch){
        tickets=ticketBranch;
        ticketBranch.classList.add(
            'et-air-ticket-branch-103172'
        );
    }

    commonTicketRow.classList.add(
        'et-air-ticket-row-103172'
    );
}else{
    common=commonHeading
        ? sectionUntil(
            commonHeading,
            ticketHeading,
            batch
        )
        : null;

    tickets=ticketHeading
        ? sectionUntil(
            ticketHeading,
            fareHeading,
            batch
        )
        : null;

    if(common){
        common.classList.add(
            'et-air-hidden-common-branch-103172'
        );
        common.setAttribute(
            'data-et-air-native-common-fields',
            '1'
        );
    }
}

const fares=sectionUntil(
    fareHeading,
    commercialHeading,
    batch
);

const commercial=sectionUntil(
    commercialHeading,
    savedHeading,
    batch
);

const saved=sectionUntil(
    savedHeading,
    manageHeading,
    batch
);

/*
 * ERP-10.31.72 STEP ORDER
 *
 * Booking page order must remain:
 * Booking Workspace -> KPIs -> Booking Header -> Passengers ->
 * Air Ticket Batch Entry
 *
 * Only INSIDE Air Ticket Batch Entry:
 * Step 1 Itinerary -> Step 2 Tickets -> Step 3 Fare ->
 * Step 4 Commercial -> Saved Passenger Tickets
 */
let insertAnchor=null;

if(ticketHeading&&batch.contains(ticketHeading)){
    /*
     * Find the direct branch under the Air Ticket Batch card that contains
     * Step 2. Insert Step 1 immediately before it, but still INSIDE batch.
     */
    insertAnchor=ticketHeading;

    while(
        insertAnchor.parentElement
        && insertAnchor.parentElement!==batch
    ){
        insertAnchor=
            insertAnchor.parentElement;
    }
}

if(
    insertAnchor
    && insertAnchor.parentElement===batch
){
    batch.insertBefore(
        root,
        insertAnchor
    );
}else if(
    ticketHeading
    && ticketHeading.parentElement
){
    /*
     * Conservative fallback: stay beside Step 2 rather than ever moving to
     * the Booking Workspace top.
     */
    ticketHeading.parentElement.insertBefore(
        root,
        ticketHeading
    );
}else{
    batch.appendChild(root);
}

root.style.display='block';

[tickets,fares,commercial,saved]
    .filter(Boolean)
    .forEach(sectionNode=>{
        sectionNode.classList.add(
            'et-air-section-103172'
        );
    });

tickets?.classList.add(
    'et-air-ticket-section-103172'
);

/* Style native section headings exactly like Group Umrah card titles. */
[
    ticketHeading,
    fareHeading,
    commercialHeading,
    savedHeading
].filter(Boolean).forEach(heading=>{
    heading.classList.add(
        'et-air-section-title-103172'
    );
});

/* Add the common ticketing defaults immediately after itinerary. */
/* native common fields */
function named(scope,name){
    if(!scope)return null;
    return scope.querySelector('[name="'+name+'"],[name$="['+name+']"],[name*="['+name+']"]');
}
function setValue(field,value){
    if(!field)return false;
    const next=String(value??'').trim();
    if(field.tagName==='SELECT'){
        const w=norm(next);
        const o=Array.from(field.options||[]).find(x=>norm(x.value)===w||norm(x.textContent)===w||(w&&norm(x.textContent).includes(w)));
        if(!o)return false;
        field.value=o.value;
    }else field.value=next;
    field.dispatchEvent(new Event('input',{bubbles:true}));
    field.dispatchEvent(new Event('change',{bubbles:true}));
    return String(field.value||'').trim()!=='';
}
function choose(select,candidates){
    if(!select||select.tagName!=='SELECT')return false;
    const ws=candidates.map(norm).filter(Boolean),opts=Array.from(select.options||[]);
    const o=opts.find(x=>{
        const v=norm(x.value),t=norm(x.textContent);
        return ws.some(w=>v===w||t===w||t.startsWith(w+' ')||t.endsWith(' '+w)||t.includes('('+w+')')||t.includes('['+w+']'));
    })||opts.find(x=>ws.some(w=>w.length>=2&&norm(x.textContent).includes(w)));
    if(!o)return false;
    select.value=o.value;
    select.dispatchEvent(new Event('change',{bubbles:true}));
    return true;
}

const nativePnr=named(common,'pnr');
const nativeStatus=named(common,'ticket_status');
const nativeAirline=named(common,'airline_id');
const nativeSource=named(common,'booking_source_id');
const nativeOrigin=named(common,'origin_id');
const nativeDestination=named(common,'destination_id');
const nativeDeparture=named(common,'departure_at');

function defaultSource(){
    if(!nativeSource||nativeSource.tagName!=='SELECT'||String(nativeSource.value||'').trim()!=='')return;
    const real=Array.from(nativeSource.options||[]).filter(o=>String(o.value||'').trim()!=='');
    const words=['manual','direct','offline','back office','backoffice','internal'];
    let p=real.find(o=>words.some(w=>norm(o.textContent).includes(w)));
    if(!p&&real.length===1)p=real[0];
    if(p){nativeSource.value=p.value;nativeSource.dispatchEvent(new Event('change',{bubbles:true}))}
}
function nativeDisplayField(names){
    for(const name of names){
        const field=named(common,name);

        if(field)return field;
    }

    return null;
}

const nativeOriginDisplay=nativeDisplayField([
    'origin',
    'origin_code',
    'from',
    'from_code'
]);

const nativeDestinationDisplay=nativeDisplayField([
    'destination',
    'destination_code',
    'to',
    'to_code'
]);

const nativeAirlineDisplay=nativeDisplayField([
    'airline',
    'airline_code',
    'airline_name'
]);

function setNativeMaster(field,resolvedId,candidates){
    if(!field)return false;

    const id=String(resolvedId||'').trim();

    if(field.tagName==='SELECT'){
        if(id&&setValue(field,id)){
            return true;
        }

        return choose(
            field,
            candidates
        );
    }

    /*
     * Hidden *_id controls must receive actual ERP master IDs.
     */
    if(id){
        return setValue(
            field,
            id
        );
    }

    /*
     * Legacy schema compatibility only when no numeric master ID was found.
     */
    const fallback=String(
        candidates.find(Boolean)
        || ''
    ).trim();

    return fallback
        ? setValue(field,fallback)
        : false;
}

function etAirDerivedTicketStatus103169(){
    const statuses=Array.from(
        root.querySelectorAll(
            '[data-et-segment-row] [data-et-segment-field="status"]'
        )
    ).map(field=>norm(field.value));

    if(
        statuses.length>0
        && statuses.every(status=>status==='issued')
    ){
        return 'ISSUED';
    }

    return 'BOOKED';
}

function etAirRefreshDerivedDefaults103169(){
    const status=etAirDerivedTicketStatus103169();

    if(nativeStatus){
        setValue(
            nativeStatus,
            status
        );
    }

    const statusHost=batch.querySelector(
        '[data-et-derived-ticket-status]'
    );

    if(statusHost){
        statusHost.textContent='AUTO · '+status;
    }

    const flightHost=batch.querySelector(
        '[data-et-derived-flight]'
    );

    if(flightHost){
        flightHost.textContent=[
            root.dataset.pnr
                ? 'PNR '+root.dataset.pnr
                : '',
            root.dataset.origin
                && root.dataset.destination
                ? root.dataset.origin
                    +' → '
                    +root.dataset.destination
                : '',
            root.dataset.airlineCode
                || root.dataset.airlineName
                || '',
            root.dataset.departure
                ? root.dataset.departure.replace(
                    'T',
                    ' '
                )
                : ''
        ].filter(Boolean).join(' · ')
        || 'Complete itinerary first.';
    }
}

function syncNative(){
    const originCode=
        root.dataset.origin||'';

    const destinationCode=
        root.dataset.destination||'';

    const airlineCode=
        root.dataset.airlineCode||'';

    const airlineName=
        root.dataset.airlineName||'';

    /*
     * Populate old visible code/autocomplete controls first and fire their
     * native listeners.
     */
    if(nativeOriginDisplay){
        setValue(
            nativeOriginDisplay,
            originCode
        );
    }

    if(nativeDestinationDisplay){
        setValue(
            nativeDestinationDisplay,
            destinationCode
        );
    }

    if(nativeAirlineDisplay){
        setValue(
            nativeAirlineDisplay,
            airlineCode||airlineName
        );
    }

    setValue(
        nativePnr,
        root.dataset.pnr||''
    );

    setNativeMaster(
        nativeOrigin,
        root.dataset.originId||'',
        [originCode]
    );

    setNativeMaster(
        nativeDestination,
        root.dataset.destinationId||'',
        [destinationCode]
    );

    setNativeMaster(
        nativeAirline,
        root.dataset.airlineId||'',
        [airlineCode,airlineName]
    );

    setValue(
        nativeDeparture,
        root.dataset.departure||''
    );

    defaultSource();
    etAirRefreshDerivedDefaults103169();
}
syncNative();

if(common&&!batch.querySelector('.et-air-ticket-defaults-103172')){
    const d=document.createElement('div');
    d.className='et-air-ticket-defaults-103172';

    const intro=document.createElement('div');
    intro.innerHTML='<div style="font-size:7.8px;font-weight:900;color:#1769d2;text-transform:uppercase">Ticketing Defaults</div><div style="font-size:8px;color:#68788f;margin-top:3px">Common backend fields stay attached to the native ticket save.</div>';
    d.appendChild(intro);

    function cloneSelect(original,label){
        if(!original||original.tagName!=='SELECT')return;
        const h=document.createElement('div'),l=document.createElement('label'),c=original.cloneNode(true);
        l.textContent=label;c.removeAttribute('name');c.removeAttribute('id');c.removeAttribute('required');c.value=original.value||'';
        c.addEventListener('change',()=>{original.value=c.value;original.dispatchEvent(new Event('change',{bubbles:true}))});
        original.addEventListener('change',()=>{c.value=original.value||''});
        h.appendChild(l);h.appendChild(c);d.appendChild(h);
    }
    cloneSelect(nativeSource,'Booking Source');

    const sh=document.createElement('div'),sl=document.createElement('label'),sv=document.createElement('div');
    sl.textContent='Ticket Status';sv.className='et-air-derived-103172';sv.setAttribute('data-et-derived-ticket-status','1');
    sh.appendChild(sl);sh.appendChild(sv);d.appendChild(sh);

    const dh=document.createElement('div'),dl=document.createElement('label'),dv=document.createElement('div');
    dl.textContent='Derived Flight';dv.className='et-air-derived-103172';dv.setAttribute('data-et-derived-flight','1');
    dv.textContent=[root.dataset.pnr?'PNR '+root.dataset.pnr:'',root.dataset.origin&&root.dataset.destination?root.dataset.origin+' → '+root.dataset.destination:'',root.dataset.airlineCode||root.dataset.airlineName||'',root.dataset.departure?root.dataset.departure.replace('T',' '):''].filter(Boolean).join(' · ')||'Complete itinerary first.';
    dh.appendChild(dl);dh.appendChild(dv);d.appendChild(dh);
    root.insertAdjacentElement('afterend',d);
    etAirRefreshDerivedDefaults103169();
}


/* =========================================================
   GROUP UMRAH STYLE DECORATION FOR NATIVE AIR ELEMENTS
   ========================================================= */
function firstLeafContaining(scope,textValue){
    const wanted=norm(textValue);
    return Array.from(scope?.querySelectorAll('*')||[])
        .find(el=>el.children.length<=4&&norm(el.textContent).includes(wanted))
        || null;
}

function closestMetricCard(node,limitScope){
    if(!node)return null;
    let c=node;
    for(let i=0;i<4&&c&&c!==limitScope;i++){
        if(
            c.parentElement
            && c.parentElement.children.length>=2
            && c.getBoundingClientRect().width>70
        ){
            return c;
        }
        c=c.parentElement;
    }
    return node.parentElement;
}

function decorateMetricGroup(scope,labels,gridClass,cardClass){
    const cards=labels.map(label=>{
        const leafNode=firstLeafContaining(scope,label);
        return closestMetricCard(leafNode,scope);
    }).filter(Boolean);

    const unique=Array.from(new Set(cards));

    if(
        unique.length>=2
        && unique.every(card=>card.parentElement===unique[0].parentElement)
    ){
        unique[0].parentElement.classList.add(gridClass);

        unique.forEach(card=>{
            card.classList.add(cardClass);

            const labelLeaf=Array.from(card.querySelectorAll('*'))
                .find(el=>el.children.length<=2&&labels.some(label=>norm(el.textContent).includes(norm(label))));

            labelLeaf?.classList.add(
                'et-air-metric-label-103172'
            );

            const candidates=Array.from(card.querySelectorAll('*'))
                .filter(el=>el.children.length===0&&norm(el.textContent)!=='');

            const valueLeaf=candidates.find(el=>{
                const text=norm(el.textContent);
                return !labels.some(label=>text.includes(norm(label)));
            });

            valueLeaf?.classList.add(
                'et-air-metric-value-103172'
            );
        });
    }
}

decorateMetricGroup(
    batch,
    ['Linked Passengers','Actual Tickets','Customer Sale','Supplier Cost'],
    'et-air-kpi-grid-103172',
    'et-air-kpi-card-103172'
);

decorateMetricGroup(
    commercial || batch,
    [
        'Selected Passengers',
        'Batch Customer Sale',
        'Batch Supplier Cost',
        'Gross Margin',
        'Commissions',
        'Net Contribution',
        'Net Margin %'
    ],
    'et-air-summary-grid-103172',
    'et-air-summary-card-103172'
);

/* Customer/Supplier Pricing and commission blocks use gp-style sub-panels. */
[
    ['Customer Pricing',fares],
    ['Supplier Pricing',fares],
    ['Vendor / Supplier',commercial],
    ['Agent Commission',commercial],
    ['Salesperson Commission',commercial]
].forEach(([label,scope])=>{
    if(!scope)return;

    const heading=firstLeafContaining(
        scope,
        label
    );

    if(!heading)return;

    let holder=heading.parentElement;

    for(let i=0;i<3&&holder&&holder!==scope;i++){
        if(
            holder.getBoundingClientRect().width>150
            && holder.querySelector(
                'input,select,textarea'
            )
        ){
            break;
        }
        holder=holder.parentElement;
    }

    if(holder&&holder!==scope){
        holder.classList.add(
            'et-air-subpanel-103172'
        );

        heading.classList.add(
            'et-air-subpanel-title-103172'
        );
    }
});

/* Workspace/card headings above and below Air Batch use same ERP scale. */
const workspaceHeadings=[
    'Booking Header',
    'Passengers',
    'Air Ticket Batch Entry',
    'Booking Workflow',
    'Client Voucher / Operational Documents',
    'Commercial & Accounting Bridge'
];

workspaceHeadings.forEach(label=>{
    const heading=leaf(
        document,
        [label]
    );

    if(!heading)return;

    heading.classList.add(
        'et-air-major-title-103172'
    );

    const parent=heading.parentElement;
    const noteCandidates=parent
        ? Array.from(parent.children)
        : [];

    noteCandidates
        .filter(el=>el!==heading)
        .filter(el=>norm(el.textContent)!=='')
        .slice(0,1)
        .forEach(el=>{
            el.classList.add(
                'et-air-major-note-103172'
            );
        });
});

/* =========================================================
   ERP-11.3.69 — LIVE ITINERARY ROWS / SILENT SAVE
   ========================================================= */
function etAirLiveNotice103169(message,isError=false){
    if(
        typeof window.etBookingLiveNotice103169==='function'
    ){
        window.etBookingLiveNotice103169(
            message,
            isError
        );
        return;
    }

    const n=document.createElement('div');
    n.className='et-booking-live-notice-103169'
        +(isError?' is-error':'');
    n.textContent=message;
    document.body.appendChild(n);
    setTimeout(()=>n.remove(),3200);
}

function etAirParseHtml103169(text){
    try{
        return new DOMParser().parseFromString(
            String(text||''),
            'text/html'
        );
    }catch(_){
        return null;
    }
}

function etAirCopyRootDataset103169(freshRoot){
    if(!freshRoot)return;

    [
        'pnr',
        'origin',
        'originId',
        'destination',
        'destinationId',
        'airlineCode',
        'airlineName',
        'airlineId',
        'departure'
    ].forEach(key=>{
        root.dataset[key]=
            freshRoot.dataset[key]
            || '';
    });
}

function etAirApplyFreshItinerary103169(doc){
    const freshRoot=
        doc?.querySelector(
            '#et-air-fresh-103172'
        );

    if(!freshRoot)return false;

    const currentBody=root.querySelector(
        '[data-et-segment-body]'
    );

    const freshBody=freshRoot.querySelector(
        '[data-et-segment-body]'
    );

    if(currentBody&&freshBody){
        currentBody.innerHTML=
            freshBody.innerHTML;
    }

    const currentMeta=root.querySelector(
        '.headx .meta'
    );

    const freshMeta=freshRoot.querySelector(
        '.headx .meta'
    );

    if(currentMeta&&freshMeta){
        currentMeta.innerHTML=
            freshMeta.innerHTML;
    }

    etAirCopyRootDataset103169(
        freshRoot
    );

    etAirBindSegmentRows103169();
    syncNative();
    etAirRefreshDerivedDefaults103169();

    return true;
}

function etAirSegmentField103169(row,name){
    return row?.querySelector(
        '[data-et-segment-field="'+name+'"]'
    ) || null;
}

function etAirSegmentPayload103169(row){
    const payload={};

    [
        'segment_type',
        'from_code',
        'to_code',
        'airline_name',
        'flight_number',
        'departure_at',
        'arrival_at',
        'pnr',
        'status'
    ].forEach(name=>{
        payload[name]=
            etAirSegmentField103169(
                row,
                name
            )?.value
            || '';
    });

    return payload;
}

function etAirSegmentReady103169(row){
    return [
        'segment_type',
        'from_code',
        'to_code',
        'departure_at'
    ].every(name=>
        String(
            etAirSegmentField103169(
                row,
                name
            )?.value
            || ''
        ).trim()!==''
    );
}

function etAirSetRowState103169(
    row,
    text,
    state=''
){
    const host=row?.querySelector(
        '[data-et-segment-save-state]'
    );

    if(!host)return;

    host.textContent=text;
    host.className=
        'et-air-save-state-103169'
        +(state?' '+state:'');
}

async function etAirSubmitSegment103169(
    row,
    force=false
){
    if(
        !row
        || row.dataset.etSaving==='1'
    ){
        return;
    }

    if(
        !etAirSegmentReady103169(row)
    ){
        etAirSetRowState103169(
            row,
            'Complete row',
            ''
        );
        return;
    }

    const id=
        String(
            row.dataset.etSegmentId
            || ''
        ).trim();

    let url=id
        ? String(
            root.dataset.updateTemplate
            || ''
        ).replace(
            '__SEGMENT_ID__',
            id
        )
        : String(
            root.dataset.storeUrl
            || ''
        );

    if(!url){
        etAirSetRowState103169(
            row,
            'Route error',
            'is-error'
        );
        return;
    }

    row.dataset.etSaving='1';
    etAirSetRowState103169(
        row,
        'Saving…',
        'is-saving'
    );

    const fd=new FormData();
    fd.append(
        '_token',
        root.dataset.csrf||''
    );

    if(id){
        fd.append(
            '_method',
            'PUT'
        );
    }

    Object.entries(
        etAirSegmentPayload103169(row)
    ).forEach(([name,value])=>
        fd.append(
            name,
            value
        )
    );

    try{
        const response=await fetch(
            url,
            {
                method:'POST',
                body:fd,
                credentials:'same-origin',
                redirect:'follow',
                headers:{
                    'X-Requested-With':'XMLHttpRequest',
                    'Accept':'text/html,application/xhtml+xml'
                }
            }
        );

        const text=await response.text();
        const doc=etAirParseHtml103169(
            text
        );

        if(
            !response.ok
            || !doc
            || !etAirApplyFreshItinerary103169(
                doc
            )
        ){
            throw new Error(
                'Flight row could not be saved.'
            );
        }

        etAirLiveNotice103169(
            id
                ? 'Flight row updated.'
                : 'Flight row added.'
        );
    }catch(error){
        row.dataset.etSaving='0';
        etAirSetRowState103169(
            row,
            'Save failed',
            'is-error'
        );
        etAirLiveNotice103169(
            error?.message
                || 'Flight row could not be saved.',
            true
        );
    }
}

function etAirDraftRow103169(type){
    const tbody=root.querySelector(
        '[data-et-segment-body]'
    );

    if(!tbody)return null;

    tbody.querySelector(
        '[data-et-segment-empty]'
    )?.remove();

    const row=document.createElement(
        'tr'
    );

    row.setAttribute(
        'data-et-segment-row',
        '1'
    );
    row.setAttribute(
        'data-et-segment-new',
        '1'
    );
    row.dataset.etSegmentType=type;

    const label=
        type==='return'
            ? 'Return'
            : (
                type==='connection'
                    ? 'Connection'
                    : 'Outbound'
            );

    const cls=[
        'outbound',
        'return',
        'connection'
    ].includes(type)
        ? type
        : 'other';

    const from=
        type==='return'
            ? root.dataset.destination||''
            : '';

    const to=
        type==='return'
            ? root.dataset.origin||''
            : '';

    const airline=
        root.dataset.airlineName
        || root.dataset.airlineCode
        || '';

    const pnr=
        root.dataset.pnr
        || '';

    row.innerHTML=`
        <td data-et-segment-index></td>
        <td>
            <span class="pill ${esc(cls)}">${esc(label)}</span>
            <input type="hidden" data-et-segment-field="segment_type" value="${esc(type)}">
        </td>
        <td><input class="et-air-row-input-103169" data-et-segment-field="from_code" value="${esc(from)}" placeholder="LHE"></td>
        <td><input class="et-air-row-input-103169" data-et-segment-field="to_code" value="${esc(to)}" placeholder="JED"></td>
        <td><input class="et-air-row-input-103169" data-et-segment-field="airline_name" value="${esc(airline)}" placeholder="Saudia / SV"></td>
        <td><input class="et-air-row-input-103169" data-et-segment-field="flight_number" placeholder="SV739"></td>
        <td><input type="datetime-local" class="et-air-row-input-103169" data-et-segment-field="departure_at"></td>
        <td><input type="datetime-local" class="et-air-row-input-103169" data-et-segment-field="arrival_at"></td>
        <td><input class="et-air-row-input-103169" data-et-segment-field="pnr" value="${esc(pnr)}" placeholder="ABC123"></td>
        <td>
            <select class="et-air-row-input-103169" data-et-segment-field="status">
                <option value="requested">Requested</option>
                <option value="booked" selected>Booked</option>
                <option value="confirmed">Confirmed</option>
                <option value="issued">Issued</option>
            </select>
        </td>
        <td><span class="et-air-save-state-103169" data-et-segment-save-state>Complete row</span></td>
        <td><button type="button" class="btnx danger" data-et-discard-new-segment>Remove</button></td>
    `;

    tbody.appendChild(
        row
    );

    etAirBindSegmentRows103169();

    row.querySelector(
        '[data-et-segment-field="from_code"]'
    )?.focus();

    row.scrollIntoView({
        behavior:'smooth',
        block:'nearest'
    });

    return row;
}

function etAirBindSegmentRows103169(){
    const rows=Array.from(
        root.querySelectorAll(
            '[data-et-segment-row]'
        )
    );

    rows.forEach((row,index)=>{
        const indexHost=row.querySelector(
            '[data-et-segment-index]'
        );

        if(indexHost){
            indexHost.textContent=
                String(index+1);
        }

        if(
            row.dataset.etLiveBound==='1'
        ){
            return;
        }

        row.dataset.etLiveBound='1';

        Array.from(
            row.querySelectorAll(
                '[data-et-segment-field]'
            )
        ).forEach(field=>{
            const immediate=()=>{
                etAirRefreshDerivedDefaults103169();
            };

            field.addEventListener(
                'input',
                immediate
            );

            field.addEventListener(
                'change',
                ()=>{
                    immediate();
                    etAirSubmitSegment103169(
                        row
                    );
                }
            );

            if(
                field.tagName==='INPUT'
                && ![
                    'datetime-local',
                    'hidden'
                ].includes(
                    String(
                        field.type
                        || ''
                    ).toLowerCase()
                )
            ){
                field.addEventListener(
                    'blur',
                    ()=>{
                        etAirSubmitSegment103169(
                            row
                        );
                    }
                );
            }
        });

        row.querySelector(
            '[data-et-discard-new-segment]'
        )?.addEventListener(
            'click',
            ()=>{
                row.remove();

                if(
                    !root.querySelector(
                        '[data-et-segment-row]'
                    )
                ){
                    const body=root.querySelector(
                        '[data-et-segment-body]'
                    );

                    if(body){
                        body.innerHTML=
                            '<tr data-et-segment-empty><td colspan="12" style="padding:14px;color:#748197">No flight sector entered yet. Click Outbound / Return / Connection and enter the row directly.</td></tr>';
                    }
                }

                etAirRefreshDerivedDefaults103169();
            }
        );
    });

    root.querySelectorAll(
        '[data-et-remove-segment]'
    ).forEach(button=>{
        if(
            button.dataset.etLiveBound==='1'
        ){
            return;
        }

        button.dataset.etLiveBound='1';

        button.addEventListener(
            'click',
            async ()=>{
                if(
                    !confirm(
                        'Remove this flight segment?'
                    )
                ){
                    return;
                }

                const id=String(
                    button.dataset.etRemoveSegment
                    || ''
                ).trim();

                const url=String(
                    root.dataset.destroyTemplate
                    || ''
                ).replace(
                    '__SEGMENT_ID__',
                    id
                );

                if(!url)return;

                const fd=new FormData();
                fd.append(
                    '_token',
                    root.dataset.csrf||''
                );
                fd.append(
                    '_method',
                    'DELETE'
                );

                try{
                    const response=await fetch(
                        url,
                        {
                            method:'POST',
                            body:fd,
                            credentials:'same-origin',
                            redirect:'follow',
                            headers:{
                                'X-Requested-With':'XMLHttpRequest',
                                'Accept':'text/html,application/xhtml+xml'
                            }
                        }
                    );

                    const text=await response.text();
                    const doc=etAirParseHtml103169(
                        text
                    );

                    if(
                        !response.ok
                        || !doc
                        || !etAirApplyFreshItinerary103169(
                            doc
                        )
                    ){
                        throw new Error(
                            'Flight row could not be removed.'
                        );
                    }

                    etAirLiveNotice103169(
                        'Flight row removed.'
                    );
                }catch(error){
                    etAirLiveNotice103169(
                        error?.message
                            || 'Flight row could not be removed.',
                        true
                    );
                }
            }
        );
    });
}

root.querySelectorAll(
    '[data-et-add-flight]'
).forEach(button=>{
    if(
        button.dataset.etLiveBound==='1'
    ){
        return;
    }

    button.dataset.etLiveBound='1';
    button.addEventListener(
        'click',
        ()=>etAirDraftRow103169(
            button.dataset.etAddFlight
            || 'outbound'
        )
    );
});

etAirBindSegmentRows103169();

/*
 * ERP-10.31.72 — SAVE PASSENGER TICKETS TRANSPORT BRIDGE
 *
 * The legacy booking page can render ticket/fare controls outside the browser's
 * final form association after DOM repair. Instead of depending on button.form,
 * resolve the existing native Air Ticket form ACTION, collect the current
 * controls from the Air Ticket workspace, and POST them to that same endpoint.
 *
 * No new backend route/controller is introduced.
 */
function etAirSuccessfulControls103172(scope){
    if(!scope)return [];

    return Array.from(
        scope.querySelectorAll(
            'input[name],select[name],textarea[name],button[name]'
        )
    ).filter(control=>{
        if(control.disabled)return false;

        const tag=control.tagName;
        const type=norm(control.type);

        if(tag==='BUTTON'){
            return false;
        }

        if(
            tag==='INPUT'
            && ['submit','button','reset','image','file'].includes(type)
        ){
            return false;
        }

        if(
            tag==='INPUT'
            && ['checkbox','radio'].includes(type)
            && !control.checked
        ){
            return false;
        }

        return String(control.name||'').trim()!=='';
    });
}

function etAirNativeSaveForm103172(){
    const direct=
        saveButton?.form
        || saveButton?.closest('form');

    if(
        direct
        && String(direct.action||'').trim()!==''
    ){
        return direct;
    }

    const forms=Array.from(
        document.querySelectorAll('form')
    );

    const scored=forms.map(form=>{
        let score=0;
        const text=norm(form.textContent);
        const controls=etAirSuccessfulControls103172(form);
        const names=controls.map(x=>norm(x.name));

        if(
            common
            && form.contains(common)
        ) score+=80;

        if(
            tickets
            && form.contains(tickets)
        ) score+=80;

        if(
            fares
            && form.contains(fares)
        ) score+=60;

        if(
            commercial
            && form.contains(commercial)
        ) score+=40;

        if(
            names.some(n=>n==='pnr'||n.includes('[pnr]'))
        ) score+=35;

        if(
            names.some(n=>n==='booking_source_id'||n.includes('[booking_source_id]'))
        ) score+=30;

        if(
            names.some(n=>n.includes('ticket'))
        ) score+=30;

        if(
            names.some(n=>n.includes('fare')||n.includes('base'))
        ) score+=20;

        if(
            text.includes('save passenger tickets')
        ) score+=20;

        if(
            String(form.action||'').trim()!==''
        ) score+=10;

        return {form,score};
    }).sort((a,b)=>b.score-a.score);

    return scored[0]?.score>0
        ? scored[0].form
        : null;
}

function etAirNativeSaveAction103172(form){
    const buttonAction=
        saveButton?.getAttribute('formaction')
        || saveButton?.dataset?.action
        || saveButton?.dataset?.url
        || saveButton?.dataset?.route
        || '';

    if(String(buttonAction||'').trim()!==''){
        try{
            return new URL(
                buttonAction,
                window.location.href
            ).href;
        }catch(_){
            return buttonAction;
        }
    }

    return String(form?.action||'').trim();
}

function etAirAppendControl103172(target,control){
    const name=String(control.name||'').trim();

    if(!name)return;

    if(
        control.tagName==='SELECT'
        && control.multiple
    ){
        Array.from(
            control.selectedOptions||[]
        ).forEach(option=>{
            const hidden=document.createElement('input');
            hidden.type='hidden';
            hidden.name=name;
            hidden.value=option.value;
            target.appendChild(hidden);
        });
        return;
    }

    const hidden=document.createElement('input');
    hidden.type='hidden';
    hidden.name=name;
    hidden.value=control.value ?? '';
    target.appendChild(hidden);
}

async function etAirSubmitNativeTicketPayload103172(){
    syncNative();

    const nativeForm=
        etAirNativeSaveForm103172();

    const action=
        etAirNativeSaveAction103172(
            nativeForm
        );

    if(!action){
        etAirLiveNotice103169(
            'Air Ticket save route could not be resolved. Please refresh the page and try again.',
            true
        );
        return false;
    }

    const transport=
        document.createElement('form');

    transport.style.display='none';
    transport.method='POST';
    transport.action=action;
    transport.acceptCharset='UTF-8';

    const scopes=[
        nativeForm,
        common,
        tickets,
        fares,
        commercial
    ].filter(Boolean);

    const seenElements=
        new Set();

    scopes.forEach(scope=>{
        etAirSuccessfulControls103172(scope)
            .forEach(control=>{
                if(seenElements.has(control))return;
                seenElements.add(control);
                etAirAppendControl103172(
                    transport,
                    control
                );
            });
    });

    if(
        !transport.querySelector(
            'input[name="_token"]'
        )
    ){
        const token=
            document.createElement('input');

        token.type='hidden';
        token.name='_token';
        token.value=
            nativeForm?.querySelector(
                'input[name="_token"]'
            )?.value
            || root.dataset.csrf
            || '';

        transport.appendChild(token);
    }

    const nativeMethodSpoof=
        nativeForm?.querySelector(
            'input[name="_method"]'
        )?.value
        || '';

    if(
        nativeMethodSpoof
        && !transport.querySelector(
            'input[name="_method"]'
        )
    ){
        const method=
            document.createElement('input');

        method.type='hidden';
        method.name='_method';
        method.value=nativeMethodSpoof;
        transport.appendChild(method);
    }

    if(
        saveButton?.name
        && String(saveButton.name).trim()!==''
    ){
        const submitter=
            document.createElement('input');

        submitter.type='hidden';
        submitter.name=saveButton.name;
        submitter.value=saveButton.value ?? '';
        transport.appendChild(submitter);
    }

    document.body.appendChild(
        transport
    );

    const originalText=
        String(
            saveButton?.textContent
            || saveButton?.value
            || 'Save Passenger Tickets'
        );

    if(saveButton){
        saveButton.disabled=true;

        if(saveButton.tagName==='INPUT'){
            saveButton.value='Saving…';
        }else{
            saveButton.textContent='Saving…';
        }
    }

    try{
        const response=await fetch(
            action,
            {
                method:'POST',
                body:new FormData(
                    transport
                ),
                credentials:'same-origin',
                redirect:'follow',
                headers:{
                    'X-Requested-With':'XMLHttpRequest',
                    'Accept':'text/html,application/xhtml+xml'
                }
            }
        );

        const text=await response.text();
        const doc=etAirParseHtml103169(
            text
        );

        if(!response.ok||!doc){
            throw new Error(
                'Passenger tickets could not be saved.'
            );
        }

        const validation=doc.querySelector(
            '.ticket-validation-retained,.alert-danger,[role="alert"]'
        );

        if(
            validation
            && (
                norm(validation.textContent)
                    .includes('need correction')
                || norm(validation.textContent)
                    .includes('please correct')
                || norm(validation.textContent)
                    .includes('error')
            )
        ){
            throw new Error(
                String(
                    validation.textContent
                    || 'Ticket data needs correction.'
                )
                    .replace(/\s+/g,' ')
                    .trim()
            );
        }

        /*
         * Synchronize Ticket Numbers, Fare Entry and Commercial fields from
         * the authoritative server response. This is critical after an
         * "Add Passenger Tickets" save: native may clear/reset the new-entry
         * batch. Keeping stale values on-screen would allow an autosave to
         * append the same batch again.
         */
        etAirSyncBatchFromFresh103171(
            doc
        );

        const currentSaved=batch.querySelector(
            '[id^="saved-passenger-tickets-"]'
        );

        if(currentSaved){
            const freshSaved=doc.getElementById(
                currentSaved.id
            );

            if(freshSaved){
                currentSaved.innerHTML=
                    freshSaved.innerHTML;
            }
        }

        /*
         * Copy the four Air service mini KPIs from the fresh native response.
         */
        function copyMetric(label){
            const find=function(scope){
                return Array.from(
                    scope.querySelectorAll(
                        'small,span,div,strong'
                    )
                ).find(el=>
                    norm(el.textContent)===norm(label)
                )||null;
            };

            const currentLabel=find(document);
            const freshLabel=find(doc);

            if(
                !currentLabel
                || !freshLabel
            ){
                return;
            }

            const currentCard=
                currentLabel.parentElement;

            const freshCard=
                freshLabel.parentElement;

            const currentValue=
                currentCard?.querySelector(
                    'strong'
                );

            const freshValue=
                freshCard?.querySelector(
                    'strong'
                );

            if(
                currentValue
                && freshValue
            ){
                currentValue.textContent=
                    freshValue.textContent;
            }
        }

        [
            'Linked Passengers',
            'Actual Tickets',
            'Customer Sale',
            'Supplier Cost'
        ].forEach(copyMetric);

        /*
         * Booking Workflow warnings/buttons can change after ticket save.
         * Replace only that panel, not the whole page.
         */
        function panelByHeading(scope,label){
            const heading=Array.from(
                scope.querySelectorAll(
                    'h1,h2,h3,h4,strong'
                )
            ).find(el=>
                norm(el.textContent)===norm(label)
            );

            if(!heading)return null;

            return heading.closest(
                '.panel,.card,section,[class*="panel"],[class*="card"]'
            ) || heading.parentElement;
        }

        const currentWorkflow=
            panelByHeading(
                document,
                'Booking Workflow'
            );

        const freshWorkflow=
            panelByHeading(
                doc,
                'Booking Workflow'
            );

        if(
            currentWorkflow
            && freshWorkflow
        ){
            currentWorkflow.innerHTML=
                freshWorkflow.innerHTML;
        }

        batch.querySelectorAll(
            '.et-air-unsaved-103172'
        ).forEach(b=>b.remove());

        etAirLiveNotice103169(
            'Passenger ticket data saved automatically.'
        );

        return true;
    }catch(error){
        etAirLiveNotice103169(
            error?.message
                || 'Passenger tickets could not be saved.',
            true
        );

        return false;
    }finally{
        transport.remove();

        if(saveButton){
            saveButton.disabled=false;

            if(saveButton.tagName==='INPUT'){
                saveButton.value=
                    originalText;
            }else{
                saveButton.textContent=
                    originalText;
            }
        }
    }
}


const saveButton=Array.from(
    batch.querySelectorAll(
        'button,input[type="submit"],input[type="button"]'
    )
).find(el=>
    norm(el.textContent||el.value)
        .includes('save passenger tickets')
);

if(saveButton){
    saveButton.addEventListener(
        'pointerdown',
        syncNative,
        true
    );

    saveButton.addEventListener(
        'click',
        event=>{
            /*
             * ERP-10.31.72 owns only the browser transport. The payload still
             * goes to the existing native Air Ticket Laravel action.
             */
            event.preventDefault();
            event.stopPropagation();

            etAirSubmitNativeTicketPayload103172();
        },
        true
    );
}

/* =========================================================
   ERP-11.3.71 — TICKET NUMBER / FARE SAFE AUTOSAVE
   ========================================================= */
let etAirTicketAutosaveTimer103170=null;
let etAirTicketAutosaveInFlight103170=false;
let etAirTicketAutosaveQueued103170=false;
let etAirBatchCommittedFingerprint103171='';

function etAirNumber103171(value){
    const n=Number(
        String(
            value
            ?? ''
        )
        .replace(/,/g,'')
        .trim()
    );

    return Number.isFinite(n)
        ? n
        : 0;
}

function etAirFareCommercialReady103171(){
    if(!fares){
        return false;
    }

    const controls=etAirSuccessfulControls103172(
        fares
    );

    const byName=function(pattern){
        return controls.filter(control=>
            pattern.test(
                String(
                    control.name
                    || ''
                ).toLowerCase()
            )
        );
    };

    const fareTotals=byName(
        /fare[_\[\].-]*total|total[_\[\].-]*fare/
    );

    const baseFares=byName(
        /base[_\[\].-]*fare|basic[_\[\].-]*fare/
    );

    const fareReady=
        fareTotals.some(control=>
            etAirNumber103171(
                control.value
            )>0
        )
        || baseFares.some(control=>
            etAirNumber103171(
                control.value
            )>0
        );

    if(fareReady){
        return true;
    }

    /*
     * Fallback for native schemas whose field names differ. Resolve inputs
     * by their visible Fare Total / Base Fare labels.
     */
    const labelLeaves=Array.from(
        fares.querySelectorAll(
            'label,small,strong,span,div'
        )
    );

    return labelLeaves.some(label=>{
        const text=norm(
            label.textContent
        );

        if(
            !text.includes('fare total')
            && !text.includes('base fare')
        ){
            return false;
        }

        const field=
            label.closest(
                'div,td,section'
            )?.querySelector(
                'input[type="number"],input:not([type]),input[type="text"]'
            );

        return etAirNumber103171(
            field?.value
        )>0;
    });
}

function etAirSelectedTicketNumbersReady103171(){
    if(!tickets){
        return false;
    }

    const rows=Array.from(
        tickets.querySelectorAll(
            '[data-ticket-passenger]'
        )
    ).filter(row=>{
        const checkbox=row.querySelector(
            'input[type="checkbox"][name*="passenger_ids"]'
        );

        return !checkbox||checkbox.checked;
    });

    if(rows.length===0){
        return false;
    }

    /*
     * When the derived/native status is ISSUED, every selected passenger
     * needs a ticket number. BOOKED may remain numberless by native rule,
     * but this autosave path is only entered from ticket/fare live entry.
     */
    return rows.every(row=>
        String(
            row.querySelector(
                '[data-ticket-number-input]'
            )?.value
            || ''
        ).trim()!==''
    );
}

function etAirTicketBatchReady103171(){
    return (
        etAirSelectedTicketNumbersReady103171()
        && etAirFareCommercialReady103171()
    );
}

function etAirTicketBatchFingerprint103171(){
    const selected=Array.from(
        tickets?.querySelectorAll(
            '[data-ticket-passenger]'
        )
        || []
    ).filter(row=>{
        const checkbox=row.querySelector(
            'input[type="checkbox"][name*="passenger_ids"]'
        );
        return !checkbox||checkbox.checked;
    }).map(row=>{
        const checkbox=row.querySelector(
            'input[type="checkbox"][name*="passenger_ids"]'
        );
        const ticket=row.querySelector(
            '[data-ticket-number-input]'
        );

        return [
            String(
                checkbox?.value
                || ''
            ),
            String(
                ticket?.value
                || ''
            ).trim()
        ].join(':');
    });

    const pricing=etAirSuccessfulControls103172(
        fares
    ).concat(
        etAirSuccessfulControls103172(
            commercial
        )
    ).map(control=>[
        String(control.name||''),
        String(control.value??'')
    ].join('='));

    return JSON.stringify({
        selected,
        pricing
    });
}

function etAirFindFreshBatch103171(doc){
    if(!doc){
        return null;
    }

    const heading=Array.from(
        doc.querySelectorAll(
            'h1,h2,h3,h4,strong,div'
        )
    ).find(el=>
        norm(
            el.textContent
        )==='air ticket batch entry'
    );

    if(!heading){
        return null;
    }

    let node=heading;

    for(
        let i=0;
        i<8&&node;
        i++
    ){
        const text=norm(
            node.textContent
        );

        if(
            text.includes(
                'saved passenger tickets'
            )
            && text.includes(
                'passenger-type fare entry'
            )
        ){
            return node;
        }

        node=node.parentElement;
    }

    return heading.parentElement;
}

function etAirSyncNamedControls103171(
    currentScope,
    freshScope
){
    if(
        !currentScope
        || !freshScope
    ){
        return;
    }

    const current=Array.from(
        currentScope.querySelectorAll(
            'input[name],select[name],textarea[name]'
        )
    );

    const fresh=Array.from(
        freshScope.querySelectorAll(
            'input[name],select[name],textarea[name]'
        )
    );

    const groups=new Map();

    fresh.forEach(control=>{
        const name=String(
            control.name
            || ''
        );

        if(!name){
            return;
        }

        if(!groups.has(name)){
            groups.set(
                name,
                []
            );
        }

        groups.get(name).push(
            control
        );
    });

    const seen=new Map();

    current.forEach(control=>{
        const name=String(
            control.name
            || ''
        );

        if(
            !name
            || !groups.has(name)
        ){
            return;
        }

        const index=seen.get(name)||0;
        const source=
            groups.get(name)[index]
            || groups.get(name)[0];

        seen.set(
            name,
            index+1
        );

        if(!source){
            return;
        }

        const type=norm(
            control.type
        );

        if(
            type==='checkbox'
            || type==='radio'
        ){
            control.checked=
                !!source.checked;
            return;
        }

        control.value=
            source.value
            ?? '';
    });
}

function etAirSyncBatchFromFresh103171(doc){
    const freshBatch=
        etAirFindFreshBatch103171(
            doc
        );

    if(!freshBatch){
        return;
    }

    const freshTicketHeading=Array.from(
        freshBatch.querySelectorAll(
            'h1,h2,h3,h4,strong,div'
        )
    ).find(el=>{
        const text=norm(
            el.textContent
        );
        return (
            text==='2. ticket numbers'
            || text==='ticket numbers'
            || text==='2. passenger ticket numbers'
        );
    });

    const freshFareHeading=Array.from(
        freshBatch.querySelectorAll(
            'h1,h2,h3,h4,strong,div'
        )
    ).find(el=>{
        const text=norm(
            el.textContent
        );
        return (
            text==='3. passenger-type fare entry'
            || text==='passenger-type fare entry'
            || text==='passenger type fare entry'
        );
    });

    const freshCommercialHeading=Array.from(
        freshBatch.querySelectorAll(
            'h1,h2,h3,h4,strong,div'
        )
    ).find(el=>{
        const text=norm(
            el.textContent
        );
        return (
            text==='4. commercial parties & commissions'
            || text==='commercial parties & commissions'
            || text==='commercial parties and commissions'
        );
    });

    /*
     * We intentionally synchronize named controls, not outerHTML. That keeps
     * the approved ERP-11.3.70 live UI and event bindings intact while making
     * the authoritative server response clear/reset stale "new batch" values.
     */
    if(freshTicketHeading){
        etAirSyncNamedControls103171(
            tickets,
            sectionUntil(
                freshTicketHeading,
                freshFareHeading,
                freshBatch
            )
        );
    }

    if(freshFareHeading){
        etAirSyncNamedControls103171(
            fares,
            sectionUntil(
                freshFareHeading,
                freshCommercialHeading,
                freshBatch
            )
        );
    }

    if(freshCommercialHeading){
        const freshSavedHeading=Array.from(
            freshBatch.querySelectorAll(
                'h1,h2,h3,h4,strong,div'
            )
        ).find(el=>
            norm(
                el.textContent
            )==='saved passenger tickets'
        );

        etAirSyncNamedControls103171(
            commercial,
            sectionUntil(
                freshCommercialHeading,
                freshSavedHeading,
                freshBatch
            )
        );
    }

    etAirBindTicketSequence103169();
}

function etAirBindFareCommercialAutosave103171(){
    [fares,commercial]
        .filter(Boolean)
        .forEach(scope=>{
            Array.from(
                scope.querySelectorAll(
                    'input[name],select[name],textarea[name]'
                )
            ).forEach(field=>{
                if(
                    field.dataset.etFareLiveBound==='1'
                    || norm(field.type)==='hidden'
                ){
                    return;
                }

                field.dataset.etFareLiveBound='1';

                const schedule=()=>{
                    if(
                        etAirTicketBatchReady103171()
                    ){
                        etAirScheduleTicketAutosave103170(
                            500
                        );
                    }
                };

                field.addEventListener(
                    'change',
                    schedule
                );

                if(
                    field.tagName==='INPUT'
                    || field.tagName==='TEXTAREA'
                ){
                    field.addEventListener(
                        'blur',
                        schedule
                    );
                }
            });
        });
}


function etAirScheduleTicketAutosave103170(
    delay=650
){
    window.clearTimeout(
        etAirTicketAutosaveTimer103170
    );

    etAirTicketAutosaveTimer103170=
        window.setTimeout(
            ()=>etAirRunTicketAutosave103170(),
            Math.max(
                120,
                Number(delay)||650
            )
        );
}

async function etAirRunTicketAutosave103170(){
    if(
        etAirTicketAutosaveInFlight103170
    ){
        etAirTicketAutosaveQueued103170=true;
        return;
    }

    if(
        !etAirTicketBatchReady103171()
    ){
        /*
         * Ticket numbers remain editable on-screen, but a NEW native ticket
         * batch is not committed until fare/base-fare data is ready. This
         * prevents zero-price partial Saved Passenger Tickets.
         */
        return;
    }

    const fingerprint=
        etAirTicketBatchFingerprint103171();

    if(
        fingerprint
        && fingerprint===
            etAirBatchCommittedFingerprint103171
    ){
        return;
    }

    const hasSelectedTicket=Array.from(
        tickets?.querySelectorAll(
            '[data-ticket-passenger]'
        )
        || []
    ).some(row=>{
        const checkbox=row.querySelector(
            'input[type="checkbox"][name*="passenger_ids"]'
        );

        const input=row.querySelector(
            '[data-ticket-number-input]'
        );

        return (
            (!checkbox||checkbox.checked)
            && String(
                input?.value
                || ''
            ).trim()!==''
        );
    });

    if(!hasSelectedTicket){
        return;
    }

    etAirTicketAutosaveInFlight103170=true;

    try{
        const saved=
            await etAirSubmitNativeTicketPayload103172();

        if(saved){
            etAirBatchCommittedFingerprint103171=
                fingerprint;
        }
    }finally{
        etAirTicketAutosaveInFlight103170=false;

        if(
            etAirTicketAutosaveQueued103170
        ){
            etAirTicketAutosaveQueued103170=false;
            etAirScheduleTicketAutosave103170(
                250
            );
        }
    }
}

function etAirMarkTicketEdited103170(input){
    if(!input)return;

    input.dataset.etTicketManualValue=
        String(
            input.value
            || ''
        ).trim();

    etAirScheduleTicketAutosave103170();
}

/* =========================================================
   ERP-11.3.71 — PASSENGER TICKET NUMBER SEQUENCE
   ========================================================= */
function etAirIncrementTicket103169(value){
    const text=String(
        value
        || ''
    ).trim();

    const match=text.match(
        /^(.*?)(\d+)$/
    );

    if(!match)return null;

    const prefix=match[1]||'';
    const digits=match[2]||'';

    try{
        const next=(
            BigInt(digits)
            + 1n
        ).toString().padStart(
            digits.length,
            '0'
        );

        return prefix+next;
    }catch(_){
        return null;
    }
}

function etAirFillTicketSequence103169(
    sourceInput
){
    if(!tickets)return;

    const rows=Array.from(
        tickets.querySelectorAll(
            '[data-ticket-passenger]'
        )
    );

    const sourceRow=
        sourceInput?.closest(
            '[data-ticket-passenger]'
        );

    let sourceIndex=rows.indexOf(
        sourceRow
    );

    if(sourceIndex<0){
        sourceIndex=rows.findIndex(row=>{
            const checkbox=row.querySelector(
                'input[type="checkbox"][name*="passenger_ids"]'
            );

            const input=row.querySelector(
                '[data-ticket-number-input]'
            );

            return (
                (!checkbox||checkbox.checked)
                && String(
                    input?.value
                    || ''
                ).trim()!==''
            );
        });
    }

    if(sourceIndex<0)return;

    let current=String(
        rows[sourceIndex]
            ?.querySelector(
                '[data-ticket-number-input]'
            )
            ?.value
        || ''
    ).trim();

    if(!current)return;

    for(
        let i=sourceIndex+1;
        i<rows.length;
        i++
    ){
        const row=rows[i];

        const checkbox=row.querySelector(
            'input[type="checkbox"][name*="passenger_ids"]'
        );

        const input=row.querySelector(
            '[data-ticket-number-input]'
        );

        if(!input)continue;

        if(
            checkbox
            && !checkbox.checked
        ){
            continue;
        }

        const existing=String(
            input.value
            || ''
        ).trim();

        if(existing!==''){
            current=existing;
            continue;
        }

        const next=
            etAirIncrementTicket103169(
                current
            );

        if(!next)break;

        input.value=next;
        input.dataset.etTicketAutoGenerated='1';

        input.dispatchEvent(
            new Event(
                'input',
                {
                    bubbles:true
                }
            )
        );

        current=next;
    }

    /*
     * Sequence is complete. Save the whole ticket batch once rather than
     * issuing one request for every auto-generated passenger ticket number.
     */
    etAirScheduleTicketAutosave103170(
        250
    );
}

function etAirBindTicketSequence103169(){
    if(!tickets)return;

    const inputs=Array.from(
        tickets.querySelectorAll(
            '[data-ticket-number-input]'
        )
    );

    inputs.forEach(input=>{
        if(
            input.dataset.etTicketSequenceBound==='1'
        ){
            return;
        }

        input.dataset.etTicketSequenceBound='1';

        input.addEventListener(
            'input',
            ()=>{
                /*
                 * Any staff edit is authoritative for this passenger. Do not
                 * overwrite the edited value on later sequencing passes.
                 */
                input.dataset.etTicketAutoGenerated='0';
            }
        );

        input.addEventListener(
            'change',
            ()=>{
                etAirFillTicketSequence103169(
                    input
                );
                etAirMarkTicketEdited103170(
                    input
                );
            }
        );

        input.addEventListener(
            'blur',
            ()=>{
                etAirFillTicketSequence103169(
                    input
                );
                etAirMarkTicketEdited103170(
                    input
                );
            }
        );
    });

    const first=inputs.find(input=>
        String(
            input.value
            || ''
        ).trim()!==''
    );

    if(first){
        /*
         * Fill only missing following numbers. Existing/manual values remain
         * untouched. The sequence helper schedules a single silent save only
         * when it actually creates new values.
         */
        const before=Array.from(
            inputs
        ).map(input=>
            String(
                input.value
                || ''
            ).trim()
        );

        etAirFillTicketSequence103169(
            first
        );

        const after=Array.from(
            inputs
        ).map(input=>
            String(
                input.value
                || ''
            ).trim()
        );

        const changed=before.some(
            (value,index)=>
                value!==after[index]
        );

        if(!changed){
            window.clearTimeout(
                etAirTicketAutosaveTimer103170
            );
        }
    }
}

window.etAirBookingLiveRebind103169=function(){
    etAirBindTicketSequence103169();
    etAirBindFareCommercialAutosave103171();
    etAirBindSegmentRows103169();
    syncNative();
    etAirRefreshDerivedDefaults103169();
};

etAirBindTicketSequence103169();
etAirBindFareCommercialAutosave103171();

/* =========================================================
   ERP-11.3.71 — SAVED PASSENGER TICKET EDIT AUTOSAVE
   ========================================================= */
let etAirSavedTicketUpdateTimer103171=null;
let etAirSavedTicketUpdateBusy103171=false;
let etAirSavedTicketUpdateQueued103171=false;

function etAirSavedTicketUpdateButton103171(){
    if(!saved){
        return null;
    }

    return Array.from(
        saved.querySelectorAll(
            'button,input[type="submit"]'
        )
    ).find(button=>
        norm(
            button.textContent
            || button.value
        )==='save ticket updates'
    ) || null;
}

async function etAirSaveExistingTicketUpdates103171(){
    if(
        etAirSavedTicketUpdateBusy103171
    ){
        etAirSavedTicketUpdateQueued103171=true;
        return;
    }

    const button=
        etAirSavedTicketUpdateButton103171();

    const form=
        button?.form
        || button?.closest(
            'form'
        );

    if(
        !button
        || !form
        || !String(
            form.action
            || ''
        ).trim()
    ){
        return;
    }

    etAirSavedTicketUpdateBusy103171=true;

    try{
        const response=await fetch(
            form.action,
            {
                method:'POST',
                body:new FormData(
                    form
                ),
                credentials:'same-origin',
                redirect:'follow',
                headers:{
                    'X-Requested-With':'XMLHttpRequest',
                    'Accept':'text/html,application/xhtml+xml'
                }
            }
        );

        const text=await response.text();
        const doc=etAirParseHtml103169(
            text
        );

        if(
            !response.ok
            || !doc
        ){
            throw new Error(
                'Saved ticket update failed.'
            );
        }

        const currentSaved=batch.querySelector(
            '[id^="saved-passenger-tickets-"]'
        );

        const freshSaved=currentSaved
            ? doc.getElementById(
                currentSaved.id
            )
            : null;

        if(
            currentSaved
            && freshSaved
        ){
            currentSaved.innerHTML=
                freshSaved.innerHTML;
            etAirBindSavedTicketAutosave103171();
        }

        etAirLiveNotice103169(
            'Saved passenger ticket updated automatically.'
        );
    }catch(error){
        etAirLiveNotice103169(
            error?.message
                || 'Saved ticket update failed.',
            true
        );
    }finally{
        etAirSavedTicketUpdateBusy103171=false;

        if(
            etAirSavedTicketUpdateQueued103171
        ){
            etAirSavedTicketUpdateQueued103171=false;
            window.setTimeout(
                ()=>etAirSaveExistingTicketUpdates103171(),
                220
            );
        }
    }
}

function etAirBindSavedTicketAutosave103171(){
    if(!saved){
        return;
    }

    const button=
        etAirSavedTicketUpdateButton103171();

    const form=
        button?.form
        || button?.closest(
            'form'
        );

    if(!form){
        return;
    }

    Array.from(
        form.querySelectorAll(
            'input[name],select[name],textarea[name]'
        )
    ).forEach(field=>{
        if(
            field.dataset.etSavedTicketLiveBound==='1'
            || norm(
                field.type
            )==='hidden'
        ){
            return;
        }

        field.dataset.etSavedTicketLiveBound='1';

        const schedule=()=>{
            window.clearTimeout(
                etAirSavedTicketUpdateTimer103171
            );

            etAirSavedTicketUpdateTimer103171=
                window.setTimeout(
                    ()=>etAirSaveExistingTicketUpdates103171(),
                    550
                );
        };

        field.addEventListener(
            'change',
            schedule
        );

        if(
            field.tagName==='INPUT'
            || field.tagName==='TEXTAREA'
        ){
            field.addEventListener(
                'blur',
                schedule
            );
        }
    });
}

etAirBindSavedTicketAutosave103171();

/* Unsaved markers */
if(tickets){
    const ins=Array.from(tickets.querySelectorAll('input[name*="ticket" i],input[id*="ticket" i],input[placeholder*="ticket" i]'));
    const refresh=()=>ins.forEach(input=>{
        const h=input.closest('td')||input.parentElement;if(!h)return;
        let b=h.querySelector('.et-air-unsaved-103172');
        if(String(input.value||'').trim()!==''){if(!b){b=document.createElement('span');b.className='et-air-unsaved-103172';b.textContent='UNSAVED';input.insertAdjacentElement('afterend',b)}}else b?.remove();
    });
    ins.forEach(i=>{i.addEventListener('input',refresh);i.addEventListener('change',refresh)});refresh();
}

/* Sensitive profit output */
if(root.dataset.canViewProfitability!=='1'){
    const labels=['gross margin','net contribution','net margin %','net margin'];
    Array.from(batch.querySelectorAll('*')).forEach(el=>{
        if(el.children.length>2||!labels.includes(norm(el.textContent)))return;
        const h=el.closest('[class*="summary"],[class*="stat"],[class*="metric"]')||el.parentElement;
        if(h&&h!==batch&&batch.contains(h))h.classList.add('et-air-sensitive-hidden-103172');
    });
}

/* Formula audit */
function firstNumber(txt){const m=String(txt||'').replace(/,/g,'').match(/-?\d+(?:\.\d+)?/);return m?Number(m[0]):NaN}
function labelNode(scope,label){
    const w=norm(label);
    return Array.from(scope?.querySelectorAll('label,*')||[]).find(el=>el.children.length<=3&&(norm(el.textContent)===w||norm(el.textContent).startsWith(w+' ')))||null;
}
function fieldByLabel(scope,label){
    const n=labelNode(scope,label);if(!n)return null;
    if(n.tagName==='LABEL'&&n.htmlFor){const x=document.getElementById(n.htmlFor);if(x)return x}
    let h=n;for(let i=0;i<4&&h&&h!==scope;i++){const f=h.querySelector?.('input,select,textarea');if(f)return f;h=h.parentElement}
    return null;
}
function outputByLabel(scope,label){
    const n=labelNode(scope,label);if(!n)return NaN;
    let h=n.parentElement;for(let i=0;i<4&&h&&h!==scope;i++){const v=firstNumber(h.textContent);if(Number.isFinite(v))return v;h=h.parentElement}
    return NaN;
}
const nv=f=>f?Number(String(f.value||'').replace(/,/g,'')):NaN;
const disc=(base,type,value)=>norm(type).includes('percent')||norm(type).includes('%')?base*value/100:value;
const close=(a,b)=>Number.isFinite(a)&&Number.isFinite(b)&&Math.abs(a-b)<=.05;

if(fares){
    const audit=document.createElement('div');audit.className='et-air-formula-audit-103172';fares.appendChild(audit);
    function runAudit(){
        const fare=nv(fieldByLabel(fares,'Fare Total')),base=nv(fieldByLabel(fares,'Base Fare')),tax=nv(fieldByLabel(fares,'Taxes'));
        const service=nv(fieldByLabel(fares,'Service / Markup')),dtype=fieldByLabel(fares,'Discount Type')?.selectedOptions?.[0]?.textContent||'',dval=nv(fieldByLabel(fares,'Discount Value'));
        const sh=Array.from(fares.querySelectorAll('*')).find(el=>el.children.length<4&&norm(el.textContent).includes('supplier pricing'));
        const ss=sh?.parentElement?.parentElement||fares;
        const sch=nv(fieldByLabel(ss,'Supplier Charges')),sdtype=fieldByLabel(ss,'Supplier Discount Type')?.selectedOptions?.[0]?.textContent||'',sdval=nv(fieldByLabel(ss,'Supplier Discount Value'));
        const expTax=fare-base,expSale=fare+service-disc(base,dtype,dval),expCost=fare+sch-disc(base,sdtype,sdval);
        const actSale=outputByLabel(fares,'Customer Sale'),actCost=outputByLabel(fares,'Supplier Cost'),actMargin=outputByLabel(fares,'Gross Margin');
        const agent=outputByLabel(fares,'Agent Commission'),sales=outputByLabel(fares,'Sales Commission'),actNet=outputByLabel(fares,'Net Contribution'),actPct=outputByLabel(fares,'Net Margin %');
        const expMargin=expSale-expCost,expNet=expMargin-(Number.isFinite(agent)?agent:0)-(Number.isFinite(sales)?sales:0),expPct=expSale>0?expNet/expSale*100:0;
        const checks=[];
        if(Number.isFinite(fare)&&Number.isFinite(base)&&Number.isFinite(tax))checks.push(['Taxes',close(tax,expTax)]);
        if(Number.isFinite(actSale))checks.push(['Customer Sale',close(actSale,expSale)]);
        if(Number.isFinite(actCost))checks.push(['Supplier Cost',close(actCost,expCost)]);
        if(Number.isFinite(actMargin))checks.push(['Gross Margin',close(actMargin,expMargin)]);
        if(Number.isFinite(actNet))checks.push(['Net Contribution',close(actNet,expNet)]);
        if(Number.isFinite(actPct))checks.push(['Net Margin %',close(actPct,expPct)]);
        if(!checks.length){audit.innerHTML='<span class="auditreview">Formula Audit</span><span>Enter fare values to run checks.</span>';return}
        const ok=checks.every(x=>x[1]);
        audit.innerHTML='<span class="'+(ok?'auditpass':'auditreview')+'">'+(ok?'Formula Check PASS':'Formula Check REVIEW')+'</span>'+checks.map(x=>'<span>'+esc(x[0])+': '+(x[1]?'✓':'check')+'</span>').join('');
    }
    fares.addEventListener('input',()=>setTimeout(runAudit,0));fares.addEventListener('change',()=>setTimeout(runAudit,0));setTimeout(runAudit,80);
}

/* Focused workspace */
function findWorkspace(){
    let c=batch.parentElement;
    for(let i=0;i<10&&c&&c!==document.body;i++){
        const t=norm(c.textContent);
        if(t.includes('booking workspace')&&t.includes('passengers')&&t.includes('air ticket batch entry')&&t.includes('booking workflow'))return c;
        c=c.parentElement;
    }
    return null;
}
function focus(){
    const ws=findWorkspace();
    if(!ws){document.documentElement.classList.remove('et-air-preload-103172');return}
    if(ws.dataset.etAirFocusInitialized==='1'){document.documentElement.classList.remove('et-air-preload-103172');return}
    ws.dataset.etAirFocusInitialized='1';ws.classList.add('et-air-workspace-103172');document.body.classList.add('et-air-focus-mode-103172');

    const side=Array.from(document.querySelectorAll('aside,nav,[class*="sidebar"],[class*="navbar-vertical"],[class*="side-nav"]'))
        .filter(el=>!ws.contains(el)).find(el=>norm(el.textContent).includes('dashboard')&&norm(el.textContent).includes('easy ticket'));

    const reg=Array.from(ws.querySelectorAll('a')).find(a=>norm(a.textContent)==='booking register'||norm(a.textContent).startsWith('booking register '));

    /*
     * ERP-11.3.52 — Air uses the same booking shell as Umrah/native bookings.
     * Keep only a hidden sentinel because some legacy notice helpers reference
     * `tb`; the visible header and Menu come from the shared booking shell.
     */
    const tb=document.createElement('div');
    tb.className='et-air-focus-toolbar-103172';
    tb.setAttribute('data-et-air-shell-sentinel','ERP-11.3.52');
    tb.style.display='none';
    tb.setAttribute('aria-hidden','true');
    ws.insertBefore(tb,ws.firstChild);

    /*
     * ERP-10.31.72 — SESSION / VALIDATION NOTICE PLACEMENT
     *
     * Native Laravel flashes are rendered by the old ERP shell above the
     * booking workspace. Move only alert-like content physically above this
     * focused toolbar and place it directly below the toolbar.
     */
    const relocateAirFlashMessages103172=()=>{
        let noticeHost=ws.querySelector(
            '.et-air-focus-notices-103172'
        );

        const toolbarRect=
            tb.getBoundingClientRect();

        const alertSelectors=[
            '[role="alert"]',
            '.alert',
            '[class*="alert-"]',
            '[class*="flash"]',
            '[class*="notification"]',
            '[class*="message"]'
        ].join(',');

        const semanticCandidates=
            Array.from(
                document.querySelectorAll(
                    alertSelectors
                )
            );

        /*
         * Some legacy Laravel views render a flash as a plain div with no
         * useful class. Include only compact text blocks above the toolbar whose
         * content matches known success/error/warning language.
         */
        const textCandidates=
            Array.from(
                document.querySelectorAll(
                    'div,section,p'
                )
            ).filter(el=>{
                if(
                    ws.contains(el)
                    || el===tb
                    || el.children.length>8
                ){
                    return false;
                }

                const style=
                    window.getComputedStyle(el);

                if(style.display==='none'){
                    return false;
                }

                const rect=
                    el.getBoundingClientRect();

                if(
                    rect.height<=0
                    || rect.height>220
                    || rect.width<=20
                    || rect.top>=toolbarRect.top
                ){
                    return false;
                }

                const text=
                    norm(el.textContent);

                if(!text){
                    return false;
                }

                return (
                    text.includes('passenger tickets saved')
                    || text.includes('booking submitted for confirmation')
                    || text.includes('booking submitted for approval')
                    || text.includes('submitted for confirmation')
                    || text.includes('submitted for approval')
                    || text.includes('ticket data retained')
                    || text.includes('please correct the following')
                    || text.includes('successfully saved')
                    || text.includes('saved successfully')
                    || text.includes('validation error')
                    || text.startsWith('error:')
                    || text.startsWith('warning:')
                );
            });

        const rawCandidates=
            Array.from(
                new Set([
                    ...semanticCandidates,
                    ...textCandidates
                ])
            );

        const qualifying=
            rawCandidates.filter(el=>{
                if(
                    !el
                    || ws.contains(el)
                    || el===document.body
                    || el===document.documentElement
                    || el.hasAttribute(
                        'data-et-air-focus-native-header'
                    )
                ){
                    return false;
                }

                const style=
                    window.getComputedStyle(el);

                if(style.display==='none'){
                    return false;
                }

                const rect=
                    el.getBoundingClientRect();

                if(
                    rect.height<=0
                    || rect.height>240
                    || rect.width<=20
                    || rect.top>=toolbarRect.top
                ){
                    return false;
                }

                const text=
                    norm(el.textContent);

                if(!text){
                    return false;
                }

                const classText=
                    norm(el.className);

                const alertLike=
                    classText.includes('alert')
                    || classText.includes('flash')
                    || classText.includes('notification')
                    || el.getAttribute('role')==='alert'
                    || text.includes('passenger tickets saved')
                    || text.includes('booking submitted for confirmation')
                    || text.includes('booking submitted for approval')
                    || text.includes('submitted for confirmation')
                    || text.includes('submitted for approval')
                    || text.includes('ticket data retained')
                    || text.includes('please correct the following')
                    || text.includes('successfully saved')
                    || text.includes('saved successfully')
                    || text.includes('validation error');

                return alertLike;
            });

        /*
         * Prefer the outer alert box over nested text nodes, but do not climb
         * into unrelated page wrappers.
         */
        const outermost=
            qualifying.filter(el=>
                !qualifying.some(other=>
                    other!==el
                    && other.contains(el)
                )
            );

        if(!outermost.length){
            return;
        }

        if(!noticeHost){
            noticeHost=
                document.createElement('div');

            noticeHost.className=
                'et-air-focus-notices-103172';

            tb.insertAdjacentElement(
                'afterend',
                noticeHost
            );
        }

        outermost.forEach(alert=>{
            if(
                alert.dataset.etAirFlashMoved==='1'
            ){
                return;
            }

            const oldParent=
                alert.parentElement;

            const text=
                norm(alert.textContent);

            const classText=
                norm(alert.className);

            let type='et-info';

            if(
                classText.includes('success')
                || text.includes('saved')
                || text.includes('success')
            ){
                type='et-success';
            }

            if(
                classText.includes('danger')
                || classText.includes('error')
                || text.includes('please correct')
                || text.includes('validation error')
                || text.startsWith('error:')
            ){
                type='et-error';
            }else if(
                classText.includes('warning')
                || text.includes('ticket data retained')
                || text.startsWith('warning:')
            ){
                type='et-warning';
            }

            alert.dataset.etAirFlashMoved='1';

            alert.classList.add(
                'et-air-focus-notice-103172',
                type
            );

            /*
             * Neutralize legacy absolute/fixed positioning and narrow width.
             */
            alert.style.setProperty(
                'position',
                'static',
                'important'
            );
            alert.style.setProperty(
                'left',
                'auto',
                'important'
            );
            alert.style.setProperty(
                'right',
                'auto',
                'important'
            );
            alert.style.setProperty(
                'top',
                'auto',
                'important'
            );
            alert.style.setProperty(
                'bottom',
                'auto',
                'important'
            );

            noticeHost.appendChild(
                alert
            );

            /*
             * The old shell sometimes wraps the alert in an otherwise-empty
             * spacing container. Collapse only that now-empty compact wrapper.
             */
            if(
                oldParent
                && oldParent!==document.body
                && !ws.contains(oldParent)
            ){
                const remainingText=
                    norm(oldParent.textContent);

                const remainingChildren=
                    Array.from(oldParent.children)
                        .filter(child=>
                            child!==alert
                            && window.getComputedStyle(child).display!=='none'
                        );

                const oldRect=
                    oldParent.getBoundingClientRect();

                if(
                    remainingText===''
                    && remainingChildren.length===0
                    && oldRect.height<=260
                ){
                    oldParent.style.setProperty(
                        'display',
                        'none',
                        'important'
                    );
                }
            }
        });
    };

    relocateAirFlashMessages103172();

    /*
     * ERP-10.31.72 — WORKFLOW ACTION VISIBILITY
     *
     * Submit for Approval is a one-way workflow action. Once the booking has
     * advanced beyond Draft/unsubmitted state, the action must not be offered
     * again. Existing backend workflow remains authoritative; this is a UI
     * state correction only.
     */
    const applyAirWorkflowActionState103172=()=>{
        const normalizeStatus=value=>
            norm(value)
                .replace(/[_-]+/g,' ')
                .replace(/\s+/g,' ')
                .trim();

        const advancedStatuses=[
            'pending confirmation',
            'pending approval',
            'submitted',
            'submitted for confirmation',
            'submitted for approval',
            'awaiting confirmation',
            'awaiting approval',
            'approved',
            'confirmed',
            'posted',
            'completed',
            'cancelled',
            'canceled'
        ];

        const leafStatusNodes=
            Array.from(
                ws.querySelectorAll(
                    'span,div,p,strong,b,small'
                )
            ).filter(el=>
                el.children.length<=2
            );

        const currentAdvancedStatus=
            leafStatusNodes.find(el=>{
                const text=
                    normalizeStatus(
                        el.textContent
                    );

                return advancedStatuses.includes(
                    text
                );
            }) || null;

        /*
         * A successful Laravel flash is also definitive evidence that the
         * submit action already completed, even if a custom theme labels the
         * status badge differently.
         */
        const noticeText=
            normalizeStatus(
                ws.querySelector(
                    '.et-air-focus-notices-103172'
                )?.textContent
                || ''
            );

        const submittedFlash=
            noticeText.includes(
                'booking submitted for confirmation'
            )
            || noticeText.includes(
                'booking submitted for approval'
            )
            || noticeText.includes(
                'submitted for confirmation'
            )
            || noticeText.includes(
                'submitted for approval'
            );

        const hasAdvanced=
            Boolean(
                currentAdvancedStatus
                || submittedFlash
            );

        const submitActions=
            Array.from(
                ws.querySelectorAll(
                    'button,a,input[type="submit"],input[type="button"]'
                )
            ).filter(el=>
                normalizeStatus(
                    el.textContent
                    || el.value
                )==='submit for approval'
            );

        submitActions.forEach(action=>{
            if(hasAdvanced){
                action.style.setProperty(
                    'display',
                    'none',
                    'important'
                );

                action.setAttribute(
                    'aria-hidden',
                    'true'
                );

                /*
                 * If this is a dedicated one-button form, remove only the empty
                 * visual shell so no gap remains in Booking Workflow.
                 */
                const form=
                    action.closest('form');

                if(form){
                    const visibleActionSiblings=
                        Array.from(
                            form.querySelectorAll(
                                'button,a,input[type="submit"],input[type="button"]'
                            )
                        ).filter(other=>
                            other!==action
                            && window.getComputedStyle(other).display!=='none'
                        );

                    if(
                        visibleActionSiblings.length===0
                        && norm(form.textContent)===''
                    ){
                        form.style.setProperty(
                            'display',
                            'none',
                            'important'
                        );
                    }
                }
            }else{
                /*
                 * Draft/unsubmitted booking: leave native action available.
                 */
                action.style.removeProperty(
                    'display'
                );
                action.removeAttribute(
                    'aria-hidden'
                );
            }
        });
    };

    applyAirWorkflowActionState103172();

    /*
     * ERP-11.3.52 — shared booking-focus.js owns Menu/sidebar behavior on Air.
     */
    const menu=null;

    /*
     * Native account/header strip.
     *
     * Some ERP layouts render this as a generic div rather than a semantic
     * <header> / topbar / navbar. First try the known shell selectors; then
     * resolve the smallest visible ancestor around the Sign out / Logout
     * control that also looks like the booking/user account strip.
     */
    const shellHeaderCandidates=Array.from(
        document.querySelectorAll(
            'header,[class*="topbar"],[class*="top-bar"],'
            + '[class*="page-header"],[class*="navbar"]'
        )
    ).filter(el=>
        !ws.contains(el)
        && visible(el)
        && el.getBoundingClientRect().height<=150
        && (
            norm(el.textContent).includes('sign out')
            || norm(el.textContent).includes('logout')
        )
    );

    let header=shellHeaderCandidates
        .sort((a,b)=>
            a.getBoundingClientRect().height
            - b.getBoundingClientRect().height
        )[0] || null;

    if(!header){
        const logoutLeaf=Array.from(
            document.querySelectorAll('a,button,span,div')
        ).find(el=>{
            if(ws.contains(el)||!visible(el))return false;

            const text=norm(el.textContent);

            return el.children.length<=2
                && (
                    text==='sign out'
                    || text==='logout'
                    || text==='log out'
                );
        });

        if(logoutLeaf){
            const bookingText=norm(
                ws.querySelector('h1,h2,[class*="booking"]')?.textContent
                || ''
            );

            let current=logoutLeaf;

            for(let i=0;i<7&&current&&current!==document.body;i++){
                const parent=current.parentElement;

                if(!parent||ws.contains(parent))break;

                const rect=parent.getBoundingClientRect();
                const text=norm(parent.textContent);

                const containsAccountSignals=
                    text.includes('sign out')
                    || text.includes('logout')
                    || text.includes('log out');

                const containsBookingSignal=
                    text.includes('bk-')
                    || (
                        bookingText
                        && bookingText.length>=6
                        && text.includes(bookingText)
                    );

                const compactTopStrip=
                    rect.height>0
                    && rect.height<=170
                    && rect.width>120
                    && rect.top<=170;

                if(
                    compactTopStrip
                    && containsAccountSignals
                    && containsBookingSignal
                ){
                    header=parent;
                    break;
                }

                current=parent;
            }

            /*
             * Final fallback: hide the smallest compact top strip containing
             * the logout control, but never the body, sidebar or Air workspace.
             */
            if(!header){
                current=logoutLeaf;

                for(let i=0;i<6&&current&&current!==document.body;i++){
                    const parent=current.parentElement;

                    if(!parent||ws.contains(parent))break;

                    const rect=parent.getBoundingClientRect();
                    const text=norm(parent.textContent);

                    if(
                        rect.height>0
                        && rect.height<=120
                        && rect.width>120
                        && rect.top<=120
                        && (
                            text.includes('sign out')
                            || text.includes('logout')
                            || text.includes('log out')
                        )
                        && !text.includes('dashboard')
                    ){
                        header=parent;
                        break;
                    }

                    current=parent;
                }
            }
        }
    }

    /*
     * ERP-11.3.52 — keep the native ERP top booking/user header, matching
     * Umrah/standard booking pages.
     */
    if(header){
        header.removeAttribute('data-et-air-focus-native-header');
        header.style.removeProperty('display');
    }

    /*
     * ERP-10.31.72 — coordinate-based account-strip cleanup.
     *
     * Live ERP renders the top account strip as several sibling blocks rather
     * than one semantic header. Remove every compact, account-like block that
     * is physically ABOVE the focused Air Ticket toolbar. Nothing at or below
     * the toolbar can match this sweep.
     */
    const hideNativeTopAccountStrip=()=>{
        /*
         * ERP-11.3.52: intentional no-op.
         * Air now keeps the same native ERP top header as Umrah.
         */
        return;
    };

    hideNativeTopAccountStrip();
    relocateAirFlashMessages103172();
    applyAirWorkflowActionState103172();

    /*
     * ERP-11.3.51 — do NOT calculate a viewport-wide Air canvas.
     *
     * Live Inspector comparison:
     *   Air workspace:   1158.67px visible with inline width/max-width 1231px
     *                    and margin-left -36px.
     *   Other bookings:  section.content = 1262.67px.
     *
     * The correct contract is to inherit the existing native section.content
     * width. Remove every legacy inline sizing value and let CSS width:100%
     * fill the parent responsively.
     */
    const fit=()=>{
        ws.style.removeProperty('width');
        ws.style.removeProperty('max-width');
        ws.style.removeProperty('min-width');
        ws.style.removeProperty('margin-left');
        ws.style.removeProperty('margin-right');
        ws.style.removeProperty('box-sizing');
        ws.setAttribute(
            'data-et-air-native-content-canvas',
            'ERP-11.3.51'
        );
    };
    requestAnimationFrame(()=>{
        hideNativeTopAccountStrip();
        relocateAirFlashMessages103172();
        applyAirWorkflowActionState103172();
        fit();

        requestAnimationFrame(()=>{
            hideNativeTopAccountStrip();
            relocateAirFlashMessages103172();
            applyAirWorkflowActionState103172();
            fit();
            document.documentElement.scrollLeft=0;
            document.body.scrollLeft=0;
            document.documentElement.classList.remove('et-air-preload-103172');

            window.setTimeout(()=>{
                hideNativeTopAccountStrip();
                relocateAirFlashMessages103172();
                applyAirWorkflowActionState103172();
            },0);
        });
    });
    window.addEventListener('resize',()=>{
        hideNativeTopAccountStrip();
        relocateAirFlashMessages103172();
        applyAirWorkflowActionState103172();
        fit();
    },{passive:true});
}
focus();
})();
</script>
