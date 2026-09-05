# Removed Local Release/Build Artifacts

| Path | Type | Version | Reason removed |
|---|---|---:|---|
| `D:\Easy Ticket\ERP\FINAL\source` | Duplicate editable source tree | ERP-11.3.156 | Verified byte-identical to the `.156` package and promoted to `CURRENT`; retaining it would create a second authority. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_149_FINAL_DIRECT_UPLOAD.zip` | Old cumulative deployment ZIP | ERP-11.3.149 | Superseded release archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_149_FINAL_DIRECT_UPLOAD.zip.sha256.txt` | Old checksum | ERP-11.3.149 | Checksum for superseded archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_150_FINAL_DIRECT_UPLOAD.zip` | Old cumulative deployment ZIP | ERP-11.3.150 | Superseded release archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_150_FINAL_DIRECT_UPLOAD.zip.sha256.txt` | Old checksum | ERP-11.3.150 | Checksum for superseded archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_151_FINAL_DIRECT_UPLOAD.zip` | Old cumulative deployment ZIP | ERP-11.3.151 | Superseded release archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_151_FINAL_DIRECT_UPLOAD.zip.sha256.txt` | Old checksum | ERP-11.3.151 | Checksum for superseded archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_152_FINAL_DIRECT_UPLOAD.zip` | Old cumulative deployment ZIP | ERP-11.3.152 | Superseded release archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_152_FINAL_DIRECT_UPLOAD.zip.sha256.txt` | Old checksum | ERP-11.3.152 | Checksum for superseded archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_153_FINAL_DIRECT_UPLOAD.zip` | Old cumulative deployment ZIP | ERP-11.3.153 | Superseded release archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_153_FINAL_DIRECT_UPLOAD.zip.sha256.txt` | Old checksum | ERP-11.3.153 | Checksum for superseded archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_154_FINAL_DIRECT_UPLOAD.zip` | Old cumulative deployment ZIP | ERP-11.3.154 | Superseded release archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_154_FINAL_DIRECT_UPLOAD.zip.sha256.txt` | Old checksum | ERP-11.3.154 | Checksum for superseded archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_155_FINAL_DIRECT_UPLOAD.zip` | Old cumulative deployment ZIP | ERP-11.3.155 | Superseded release archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_155_FINAL_DIRECT_UPLOAD.zip.sha256.txt` | Old checksum | ERP-11.3.155 | Checksum for superseded archive. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_156_FINAL_DIRECT_UPLOAD.zip` | Verified seed deployment ZIP | ERP-11.3.156 | SHA-256 and 151-file equivalence recorded in `CURRENT_RELEASE.md`; no longer needed as development authority. |
| `D:\Easy Ticket\ERP\FINAL\Easy_Ticket_Travel_ERP_ERP11_3_156_FINAL_DIRECT_UPLOAD.zip.sha256.txt` | Verified seed checksum | ERP-11.3.156 | Baseline checksum retained in `CURRENT_RELEASE.md`. |
| `D:\Easy Ticket\ERP\FINAL\BROWSER_UAT.md` | Historical release report | ERP-11.3.150–156 | Historical packaging workflow document; current findings retained in current audit/report where relevant. |
| `D:\Easy Ticket\ERP\FINAL\COMPANY_PROFILE_AUTHORITY_ERP11_3_156.md` | Release-specific audit note | ERP-11.3.156 | Findings consolidated in `CODE_AUDIT.md`. |
| `D:\Easy Ticket\ERP\FINAL\DEPLOYMENT.md` | Old deployment instruction | ERP-11.3.156 | Deployment packaging now occurs only after `PREPARE DEPLOYMENT`. |
| `D:\Easy Ticket\ERP\FINAL\RELEASE_MANIFEST.md` | Old package manifest | ERP-11.3.156 | Baseline identity/checksum retained in `CURRENT_RELEASE.md`. |
| `D:\Easy Ticket\ERP\FINAL\REMOVED_STALE_RELEASES.md` | Historical cleanup log | Historical | Obsolete release-workflow log. |
| `D:\Easy Ticket\ERP\FINAL\SCHEMA_MAP_ERP11_3_150.md` | Historical release audit | ERP-11.3.150 | Superseded audit; actionable authority findings remain in current code/audit. |
| `D:\Easy Ticket\ERP\FINAL\TEST_REPORT.md` | Release test report copy | ERP-11.3.156 | Copied into `CURRENT` before removal. |
| `D:\Easy Ticket\ERP\FINAL\CODE_AUDIT.md` | Release code audit copy | ERP-11.3.156 | Copied into `CURRENT` before removal. |
| `D:\Easy Ticket\ERP\FINAL` | Old release workspace | ERP-11.3.149–156 | All useful current content transferred or recorded; directory removed to eliminate the competing authority. |

Unrelated user artifacts intentionally retained outside `CURRENT`: the
`backup` directory, `BK-2026-000013 · ACCOMMODATION VOUCHER.pdf`, and
`Customer Ledger Statement.pdf`.
