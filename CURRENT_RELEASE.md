# Easy Ticket ERP — Current Local Authority

```text
CURRENT_VERSION=ERP-11.3.158
APPLICATION_VERSION=v1.1.33.158-ERP11.3.158
SOURCE_BASELINE=ERP-11.3.156 FINAL
BASELINE_SHA256=82d6a91af3256a94babbdc907fee89f77af2fc48c98ce341d92b7f8c10b87c83
WORKSPACE=D:\Easy Ticket\ERP\CURRENT
PRODUCTION_STATUS=NOT VERIFIED FROM THIS CLEANUP
LAST_PACKAGED_RELEASE=ERP-11.3.158
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
