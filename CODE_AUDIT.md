# ERP-11.3.159 Code Audit

## ERP-11.3.159 voucher logo sizing correction

- The General Client Voucher real logo now uses `max-width: 80px`, `max-height: 80px`, automatic intrinsic dimensions, and `object-fit: contain`.
- The fallback initials mark uses the same 80 x 80 footprint, and a centered logo box keeps header alignment stable.
- Client Preview and Print / Save PDF use the same voucher markup and CSS; no unrelated voucher layout or business logic changed.

## ERP-11.3.158 final logo/footer authority correction

- Company report-logo extraction now recognizes the native helper, exact attribute/storage member, and image-shaped embedded data already used by the working Company Profile preview.
- The selected Saudi Company row's `voucher_footer_html` is the only relationship-level footer authority. Pakistan IATA footer propagation was removed.
- A narrow GET-only Company Profile response presenter corrects the native help copy without replacing its controller, form, persistence, or authorization.
- All other voucher layout/data behavior remains unchanged; no migration was added.

## ERP-11.3.157 voucher logo-only correction

- Live read-only comparison proved the voucher emitted no Company `<img>` and immediately rendered `EG`, while the same Company Profile emitted one complete 180×180 JPEG data URI.
- Root cause was value extraction: the voucher adapter read only the raw upload request attribute instead of the native Company model's proven computed report-logo presentation value/storage member.
- `CompanyReportLogoValueResolver` now prefers the safe zero-argument native report-logo presentation helper, then the exact `report_logo` attribute, then a native report-logo storage member. The existing URL/data/binary normalization remains the only rendering conversion.
- Blank values still render the initials fallback; invalid Windows filesystem paths are rejected; broken web images retain the existing `onerror` fallback.
- Company identity/footer, Saudi/IATA, passenger Visa mapping, travel sections, QR, references, and A4 CSS were not changed. No migration was added.

## Canonical workspace cleanup

- `D:\Easy Ticket\ERP\CURRENT` is the sole editable source authority.
- The 151 runtime/package files were promoted from an independently extracted `.156` package only after byte-for-byte comparison with the former `FINAL\source` tree.
- Release-specific copies and archives are not source control. No automatic package should be generated; packaging is authorized only by `PREPARE DEPLOYMENT` and must target the otherwise-empty sibling `DEPLOY` directory.
- Local-only governance documents are `CURRENT_RELEASE.md`, `TEST_REPORT.md`, `CODE_AUDIT.md`, and `REMOVED_LOCAL_ARTIFACTS.md`; a future deploy package must exclude them unless explicitly required.
- No risky runtime refactor was performed during cleanup. Version-specific view/asset filenames and compatibility bridges remain because routes/controllers still reference them; they require feature-level audit before any later removal.
- Cleanup sanity checks found no duplicate fully-qualified PHP class, no stale runtime reference to the former source/build paths, no embedded deployment ZIP, and no `.env`, private-key, or credential container file.

## ERP-11.3.156 Company Profile authority correction

- Read-only live inspection identified the canonical Company Profile route `/organization/company`, native `App\Models\Company` / `companies` authority, `name`, `legal_name`, `report_logo`, and `voucher_footer_html` bindings. The saved logo is already rendered by the ERP as a JPEG data URI.
- Removed the guessed table/field scan and `config('app.name')` fallback from the voucher data resolver. The voucher now selects the booking company, then owning branch company, then authenticated user company, and permits an unscoped record only in a single-company installation.
- The General Voucher passes its booking context into the profile service. Native data-URI, binary JPEG/PNG/WEBP, public storage, and public URL logo forms resolve to print-safe URLs; the existing initials mark remains only for absent/broken images.
- Live Travel Masters inspection found the actual `voucher_footer_html` field on the linked Pakistan Visa / IATA row in `travel_voucher_partners`. That exact field now propagates through the existing Saudi → Pakistan IATA relationship used by booking Visa rows.
- Footer priority remains exclusive: first genuinely non-empty linked relationship footer, otherwise `companies.voucher_footer_html`; NULL, empty, and whitespace values all fall through. Approved safe HTML tags render while unsafe tags/attributes are removed.
- No migration, Company Profile field, Travel Master, Visa record, rate, or commercial calculation was added or rewritten.

## ERP-11.3.155 voucher finalization

- Removed the standalone client Visa table and added one compact Visa No. column to Passengers / Guests. Mapping is delegated to a pure stable-ID map supporting safe legacy booking-passenger/traveller aliases; names are never matching authority.
- Added a pure exclusive footer resolver: the first non-empty saved native Saudi footer wins; otherwise the Company Profile default footer is used; when both are blank no footer element renders. The two sources are never concatenated.
- Native Saudi footer discovery reuses physical or JSON-backed Travel Master aliases (`voucher_footer`, KSA/Saudi contact footer, print/default/footer-note variants). No duplicate Saudi master and no migration were added.
- Company Profile fallback expanded only across compatible native footer aliases. Company name/logo remain sourced through the existing profile resolver; saved Visa Saudi/IATA snapshot names remain the historical header authority.
- The lower voucher layout now keeps Special Instructions and the single footer on the left and a compact QR slot on the right. Only pre-existing saved QR/public URLs are sanitized and rendered; because the overlay has no public voucher/token route, absence or image failure uses a controlled placeholder rather than inventing an unsafe public link.
- The raw public URL is never printed. Vendor/cost/FX/margin/accounting fields remain absent from the voucher.
- A4 portrait, table header repetition, unsplit passenger rows, multi-page flow, logo aspect ratio, itinerary/hotel/transport sections, toolbar, and bottom voucher/booking references remain intact.

## ERP-11.3.154 Visa Add flow

- `+ Add Visa` now reuses one modal for compact passenger selection and a second Visa Details step. Selection/search/pagination state remains in memory when navigating Back/Continue.
- Step 2 selects one effective Visa Rate and exposes the resolved country, type, Saudi Company, Pakistani IATA, Vendor Account, cost currency/rate, FX, vendor PKR cost, and default sale as read-only values. Only sale per passenger and initial status are editable.
- The final wizard action validates the complete relationship and sale, rechecks every stable booking-passenger ID for duplicates, then appends the complete batch once. A duplicate race aborts the batch rather than creating a partial subset.
- Main rows no longer require per-passenger rate/company/cost setup. Status and sale remain inline; Application Ref, Visa No., issue/expiry dates, and notes remain in the compact row detail. Bulk Actions remains the secondary multi-row correction tool.
- The repetitive per-row Visa Rate warning was replaced by one incomplete-row count. The existing server write remains one DB transaction keyed by booking + stable passenger ID.
- Empty Visa payloads are accepted so removal of the last Visa passenger can persist explicitly. Visa readiness now evaluates only rows that exist, so passengers without a Visa row do not block travel.
- No master, relationship, commercial, voucher, Booking Value, or status subsystem was duplicated; `.153` behavior remains cumulative.

## ERP-11.3.153 controlled correction

- Booking Value arithmetic remains the sum of saved Air, Hotel, Transport, and Visa customer summaries. A new read-only operational-summary endpoint loads those persisted authorities and is the only top-card writer; unsaved Visa/Hotel/Transport/Air editor totals cannot change the KPI.
- Successful Air, Hotel, Transport, and Visa saves immediately refetch that server summary. A public `et:booking-product-saved` event provides the same refresh contract to future product panels.
- One pure `BookingTravelReadinessResolver` derives `Ready` or `PendingTravel` from booking approval and only the selected Air, Hotel, Transport, and Visa gates. It has no accounting or payment gates and reverses when a saved gate becomes invalid.
- Voucher company identity now comes from `CompanyProfileSnapshotService`; hard-coded Easy Ticket/Group identity was removed from this voucher path. Public-disk, `/storage`, absolute, and data-URL logos resolve without distortion, with a company-name-derived fallback only on absence/load failure.
- The voucher header uses deduplicated saved Visa Saudi Company/Pakistani IATA snapshot names. Head Office and booking reference were removed from that location; booking identity remains in the summary/footer.
- The existing client-safe Visa section is retained after Transport. Print CSS repeats table headers, avoids splitting rows, and permits a long Visa table to paginate.
- No migration or parallel status/profile schema was added. The approved `.152` compact Visa passenger selector was not rewritten.

## Root cause and schema decision

- The native Travel Masters implementation is part of the installed host application and is not included in this direct-upload overlay. Its concrete controller, form, and table definition therefore cannot be truthfully inspected or replaced locally.
- Read-only live `.150` inspection confirmed the exact native fields: Pakistani IATA uses `default_vendor_party_id`, while Saudi Visa Company uses `linked_pakistan_iata_operator_id`. Neither literal alias existed in `.150`, directly causing the unresolved chain.
- It also treated any positive vendor ID as linked even when that ID did not resolve to the legitimate ERP vendor/supplier Party source. That could hide a broken or cross-source relation.
- The pre-change relationship/schema map is in `SCHEMA_MAP_ERP11_3_150.md`.
- ERP-11.3.151 adds those two exact aliases and no table or column. Native Travel Masters and the existing ERP Party/Vendor source remain authoritative.

## Consolidated implementation

- Added one storage-agnostic `VisaMasterRelationshipResolver` for the chain `Saudi Company -> Pakistani IATA -> Vendor Party`.
- The existing repository remains the single native-schema adapter and delegates all relationship decisions to that resolver. Visa Management, Visa Rates, and booking Visa already consume this same repository.
- Vendor resolution accepts compatible scalar IDs, relationship-object IDs, and unique exact normalized names. It never fuzzy-matches and never accepts an ID absent from the legitimate vendor source.
- Saudi/IATA fallback by a bare numeric ID is allowed only when the ID is unique across discovered native tables. Explicit master keys and same-table references take precedence.
- Statuses are exact: `READY`, `IATA LINK REQUIRED`, and `VENDOR LINK REQUIRED`.
- Visa Rate now displays resolved Pakistani IATA and Vendor Account as read-only fields immediately after Saudi selection; broken chains are disabled client-side and rejected server-side with a targeted message.
- Visa Rate status is explicitly validated and stored. Creation remains insert-only, preserving historical effective-dated rows.
- Booking Visa continues to re-resolve the current native chain before new assignment and snapshots Saudi/IATA identities plus vendor/cost/FX/sale/margin values.

## Approved Visa passenger modal

- Replaced only the passenger selector presentation with the approved compact four-column table: checkbox, Passenger Name, Passport No., and Fare Type badge.
- Kept the existing 10-row client-side pagination, stable `booking_passenger_id` authority, all-eligible Select All, search filtering, cross-page/search selection retention, clear behavior, and add-once row construction.
- Already-added Visa passengers remain excluded, matching the existing backend uniqueness rule without showing duplicate selectable rows.
- Added a live selected-count pill, exact range text, centered pager, Cancel action, disabled empty confirmation, `No passport` fallback, and responsive no-horizontal-scroll CSS.
- No Visa rate, commercial calculation, Saudi/IATA/Vendor resolution, bulk action, persistence payload, or saved Visa data code was changed.

## Classification, routes, and shell

- Explicit native type/category descriptors remain the first classification authority.
- Shared partner rows require row-specific Visa evidence; `TRN-*` is not an accepted Pakistani-IATA fallback.
- Canonical Travel Masters remains `/master-data/travel-masters`; Visa Management remains the named route at `/master-data/travel-masters/visa-management`.
- Obsolete `/travel-masters/visa-management` exists only as a compatibility redirect/endpoint boundary.
- The Visa page continues through `NativeErpLayoutResolver`, preserving the normal ERP shell.
- The response presentation bridge was retained because the native Travel Masters view/controller is absent from the overlay; replacing it without the installed source would be speculative. Its marker was updated to `.150`.

## Migration and legacy audit

- Eighteen cumulative migrations remain present; ERP-11.3.152 adds zero migrations.
- The `.142` migration contains the historical parallel Visa-master creation, while `.147` stopped using those tables and conditionally removes them only when empty and unreferenced. Removing already-shipped migrations would break migration history, so both are retained.
- `visa_*` identity tables remain excluded from native authority discovery.
- Existing booking/rate compatibility IDs and snapshots are retained for historical data.

## Duplication, performance, and security

- Exactly one Visa relationship resolver class exists.
- Native tables and vendor options are discovered once per repository request; controller vendor options remain request-cached. No row-level database query was added.
- Dynamic UI values use escaped Blade attributes and DOM `value`/safe dataset paths; no `innerHTML` was introduced.
- Client voucher continues to exclude vendor cost, exchange rate, margin, and vendor IDs.
- No secret pattern or unfinished marker was found in the canonical source.

## Known boundary

This is an overlay, not a complete Laravel checkout. It has no `composer.json`, `artisan`, `vendor/`, `.env`, base native Travel Masters controller/view, or local database. `.151` live read-only UAT passed the repaired relationship chain. The `.152` modal requires manual deployment for visual and real-booking browser UAT.
