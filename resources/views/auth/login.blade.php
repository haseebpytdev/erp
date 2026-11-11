<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Easy Ticket Travel ERP</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-page">
    <div class="login-wrap">
        <div class="login-panel">
            <div class="login-brand">
                <div class="brand-mark big">ET</div>
                <div>
                    <h1>Easy Ticket</h1>
                    <p>Travel ERP · Finance & Operations</p>
                </div>
            </div>

            <div class="login-copy">
                <span class="eyebrow">ERP-00 FOUNDATION</span>
                <h2>One operational and accounting workspace.</h2>
                <p>The foundation is ready for bookings, multi-service invoicing, supplier costing, agent commission, refunds and double-entry accounting modules.</p>
            </div>

            <div class="login-status">
                <span>Laravel {{ app()->version() }}</span>
                <span>PHP {{ PHP_VERSION }}</span>
                <span>Secure Session</span>
            </div>
        </div>

        <div class="login-card">
            <div class="card-head">
                <h2>Welcome back</h2>
                <p>Sign in with your ERP administrator account.</p>
            </div>

            @if ($errors->any())
                <div class="alert danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login.store') }}" class="form-stack">
                @csrf
                <label>
                    <span>Email address</span>
                    <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                </label>
                <label>
                    <span>Password</span>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <label class="check-row">
                    <input type="checkbox" name="remember" value="1">
                    <span>Keep me signed in on this device</span>
                </label>
                <button class="btn btn-primary btn-wide" type="submit">Sign in</button>
            </form>

            <div class="security-note">Authorized staff only · Posted accounting actions will be auditable.</div>
        </div>
    </div>
</body>
</html>
