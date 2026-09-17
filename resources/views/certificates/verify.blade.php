@php
    $institution = config('lms.institution_name', 'LMS');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Verify Certificate — {{ $institution }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(160deg, #1e3a8a 0%, #3b82f6 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.5);
            padding: 40px;
            max-width: 640px;
            width: 100%;
            text-align: center;
        }
        .badge { font-size: 12px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #64748b; }
        h1 { font-size: 24px; color: #0f172a; margin: 8px 0 4px; }
        .subtitle { color: #64748b; font-size: 14px; margin: 0 0 24px; }
        .result {
            border-radius: 12px;
            padding: 24px;
            margin: 16px 0;
        }
        .result.valid { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .result.invalid { background: #fef2f2; border: 1px solid #fecaca; }
        .result .state { font-size: 36px; }
        .result h2 { margin: 8px 0 4px; font-size: 18px; }
        .result.valid h2 { color: #15803d; }
        .result.invalid h2 { color: #b91c1c; }
        .result p { margin: 0; font-size: 14px; color: #475569; }
        dl {
            text-align: left;
            margin: 24px 0 8px;
            border-top: 1px solid #e2e8f0;
            display: grid;
            grid-template-columns: 160px 1fr;
            gap: 0;
        }
        dt, dd { padding: 12px 8px; border-bottom: 1px solid #e2e8f0; margin: 0; font-size: 14px; }
        dt { color: #64748b; font-weight: 500; }
        dd { color: #0f172a; word-break: break-word; }
        .footer { color: #94a3b8; font-size: 12px; margin-top: 24px; }
    </style>
</head>
<body>
    <main class="card">
        <p class="badge">{{ $institution }}</p>
        <h1>Certificate Verification</h1>
        <p class="subtitle">Verification for certificate {{ $identifier }}</p>

        @if ($verified)
            <div class="result valid">
                <div class="state">{{ '✓' }}</div>
                <h2>This certificate is authentic</h2>
                <p>Issued by {{ $institution }} and verified against our records.</p>
            </div>
            <dl>
                <dt>Certificate Number</dt>
                <dd>{{ $certificate->certificate_number }}</dd>
                <dt>Recipient</dt>
                <dd>{{ $certificate->student->name ?? '—' }}</dd>
                <dt>Course</dt>
                <dd>{{ $certificate->course->title ?? '—' }}</dd>
                <dt>Awarded By</dt>
                <dd>{{ $certificate->instructor->name ?? '—' }}</dd>
                <dt>Issued On</dt>
                <dd>{{ $certificate->issued_at?->format('F j, Y') }}</dd>
            </dl>
        @else
            <div class="result invalid">
                <div class="state">{{ '✕' }}</div>
                <h2>Certificate not found</h2>
                <p>No valid certificate matches this identifier. It may have been revoked or the link is incorrect.</p>
            </div>
        @endif

        <p class="footer">This page was generated automatically. Tampering with a certificate is a violation of our academic integrity policy.</p>
    </main>
</body>
</html>