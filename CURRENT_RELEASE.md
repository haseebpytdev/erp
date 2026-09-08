# Easy Ticket ERP — Current Local Authority

```text
CURRENT_VERSION=ERP-11.3.204
APPLICATION_VERSION=v1.1.33.204-ERP11.3.204
SOURCE_BASELINE=ERP-11.3.156 FINAL
BASELINE_SHA256=82d6a91af3256a94babbdc907fee89f77af2fc48c98ce341d92b7f8c10b87c83
WORKSPACE=D:\Easy Ticket\ERP\CURRENT
PRODUCTION_STATUS=NOT VERIFIED FROM THIS CLEANUP
LAST_PACKAGED_RELEASE=ERP-11.3.204
```

`CURRENT` is now the sole editable development authority. Future changes are
made and tested in this directory without creating versioned source copies or
automatic ZIP archives.

Only an explicit `PREPARE DEPLOYMENT` request authorizes release-number review,
full release validation, and creation of one package in `D:\Easy Ticket\ERP\DEPLOY`.
Deployment remains manual; this cleanup did not modify production.

Baseline proof: the former `.156` package matched the SHA-256 above, reported
the same application version, and independently extracted to 151 files that
matched all 151 files in the former `FINAL\source` tree byte-for-byte.

ERP-11.3.157 release candidate: the voucher logo adapter now reuses the native
Company report-logo presentation value (the same data-URI path proven on the
Company Profile page), with controlled blank/invalid fallback. No migration or
non-logo voucher behavior changed.

ERP-11.3.158 release candidate: completes embedded Company report-logo value
resolution and changes General/Multi-Service voucher footer authority to the
approved Saudi Company Footer → Company Default Footer rule. Pakistan IATA
footer inheritance is removed; no migration is added.

ERP-11.3.159 release candidate: constrains the real voucher header logo to an
aspect-ratio-preserving 80 x 80 pixel maximum and gives the initials fallback
the same footprint. Preview and print share the same markup and sizing rules.

Local development after `.159`: adds the authenticated GENERAL / MULTI-SERVICE
Booking Review & Process dashboard using native booking fields, saved product
snapshots, Company Profile, Sales Invoice, payment and central travel-readiness
authorities. No version increment, package or migration was created.

ERP-11.3.160 release candidate: consolidates that Booking Review implementation,
opens Preview/Review links in new tabs, moves the existing native Menu and
Booking Register controls into the width-aligned title toolbar, and removes the
redundant text checklist below the five service cards. No migration is added.

Local development after `.160`: consolidates product-save, Review-card,
Commercial-status and approval-gate rules in one booking commercial-completeness
resolver. Positive supplier cost now requires its existing Vendor authority.
Travel issuance/readiness remains a separate resolver. No version increment,
migration or package was created.

ERP-11.3.161 release candidate packages the shared commercial Vendor authority:
positive supplier costs require an existing cost owner across Air, Hotel,
Transport and Visa; Review status and approval consume the same resolver.

ERP-11.3.162 release candidate adds the Air Vendor, public voucher-token and
Travel Status migrations; separates readiness eligibility from persisted Ready;
enforces Approved/Ready read-only presentation and server locks; fixes internal
voucher/public QR route collisions; and keeps native Sales Invoice creation in
explicit default-off safe mode pending host-runtime verification.

ERP-11.3.163 release candidate adds the guarded native host-runtime Sales
Invoice bridge. Creation remains unavailable unless the host service and
createFromBooking method exist. Eligible creation is serialized and wrapped in
a database transaction, prevents duplicates, verifies the Draft header,
customer, booking, native number, product lines and authoritative total before
commit, and rolls back on any mismatch. No fallback accounting writes, automatic
approval/posting or new migration are added.

ERP-11.3.164 release candidate makes the host Booking model Customer / Party
relationship the first shared authority for the main booking, Booking Review,
Sales Invoice eligibility and native creator input. It also introduces the
approved two-level Visa presentation: clean section actions, one-line desktop
headers, associated passenger detail strips, compact row actions, balanced
totals/pagination and locked read-only behavior. Visa calculations, persistence
and commercial authority are unchanged. No migration is added.

ERP-11.3.165 release candidate persists the native host `confirmed` booking
state only when the GENERAL approval transition succeeds and returns it to an
editable non-confirmed state on submit/reopen. The native Sales Invoice gate,
runtime bridge, customer authority and legacy booking workflow remain intact.
No migration is added.

ERP-11.3.166 release candidate writes `bookings.status` directly on GENERAL
approval, reads it back inside the transaction, and rolls back instead of
reporting success if the host-native confirmation state did not persist. Reopen
returns the native status to an editable state. No migration is added.

ERP-11.3.167 release candidate adds a temporary Super-Admin-only GET diagnostic
for the host SalesInvoiceService confirmation predicate. It is reflection and
read-query only: it cannot invoke invoice creation or write booking/accounting data.
No migration is added.

ERP-11.3.202 direct-upload release adds deterministic native Air-to-generic
passenger-link reconciliation inside the guarded Sales Invoice transaction,
after booking/customer validation and before the unchanged host invoice
creator and validator. Normal Air Save synchronization remains intact. No
commercial values or other product data are changed, and no migration is added.

ERP-11.3.203 direct-upload release extends that reconciliation to the confirmed
booking-wide Hotel product 3 and Transport product 4 contracts. Their exact
active booking-passenger set is synchronized only for REQUIRED/MULTIPLE +
PER_SERVICE services, both during normal product saves and before native Sales
Invoice creation. Air remains ticket-authoritative; no commercial values,
validator logic or migrations change.

ERP-11.3.204 direct-upload release materializes the native Visa booking service
from authoritative `booking_visa_services` child rows. It resolves the native
Visa Product/Service identity and contract at runtime, preserves the exact Visa
passenger subset, synchronizes Visa customer/vendor totals, and supports
historical approved bookings inside the guarded Sales Invoice transaction.
Air, Hotel and Transport values, invoice verification and migrations remain
unchanged.
