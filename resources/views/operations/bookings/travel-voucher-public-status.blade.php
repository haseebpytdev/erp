<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="referrer" content="no-referrer">
<title>{{ $status }} · {{ $voucher->voucher_no }}</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#142033;font-family:Arial,Helvetica,sans-serif}.wrap{min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(560px,100%);background:#fff;border:1px solid #dbe3ee;border-radius:18px;padding:28px;box-shadow:0 14px 40px rgba(20,40,70,.10);text-align:center}.brand{font-size:22px;font-weight:900;color:#0753a0}.legal{margin-top:4px;color:#6c7b90;font-size:12px}.status{display:inline-block;margin:24px 0 12px;padding:8px 14px;border-radius:999px;background:#fdecec;color:#a32424;font-weight:900;font-size:12px;letter-spacing:.06em}.voucher{font-size:18px;font-weight:900;margin-bottom:10px}.message{font-size:14px;line-height:1.55;color:#4b5d73}.note{margin-top:18px;padding-top:16px;border-top:1px solid #e4e9f0;color:#738196;font-size:11px}
</style>
</head>
<body>
<div class="wrap"><div class="card">
    <div class="brand">{{ $booking->company?->name ?: 'Easy Ticket' }}</div>
    <div class="legal">{{ $booking->company?->legal_name ?: 'Easy Group Of Travels' }}</div>
    <div class="status">{{ $status }}</div>
    <div class="voucher">{{ $voucher->voucher_no }}</div>
    <div class="message">{{ $message }}</div>
    <div class="note">This page is read-only and does not expose booking, accounting, supplier, ledger or ERP administration information.</div>
</div></div>
</body>
</html>
