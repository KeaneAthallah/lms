@php
    $institution = config('lms.institution_name', 'LMS');
    $course = $certificate->course;
    $student = $certificate->student;
    $instructor = $course?->instructor;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Certificate of Completion — {{ $institution }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            background: #0f172a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px;
            color: #78350f;
        }
        .certificate {
            width: 100%;
            max-width: 960px;
            background: #fffdf5;
            border: 18px double #b45309;
            padding: 48px 40px;
            text-align: center;
            position: relative;
            box-shadow: 0 30px 60px -20px rgba(0, 0, 0, 0.7);
        }
        .seal {
            position: absolute;
            top: 24px;
            right: 24px;
            width: 84px;
            height: 84px;
            border: 3px solid #b45309;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #92400e;
            font-family: ui-sans-serif, system-ui, sans-serif;
            line-height: 1.3;
            text-align: center;
        }
        .institution {
            font-family: ui-sans-serif, system-ui, sans-serif;
            font-size: 13px;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: #92400e;
            margin: 0 0 24px;
        }
        h1 { margin: 8px 0 4px; font-size: 40px; font-weight: 500; letter-spacing: 0.02em; }
        .lead { font-size: 16px; color: #92400e; margin: 0 0 12px; }
        .recipient {
            font-size: 48px;
            font-family: ui-sans-serif, system-ui, sans-serif;
            color: #1e293b;
            margin: 20px 0;
        }
        .course-line { font-size: 19px; margin: 8px 0; }
        .course-name { font-family: ui-sans-serif, system-ui, sans-serif; color: #1e293b; font-weight: 600; }
        .meta { font-size: 14px; color: #78350f; margin: 10px 0; }
        .footer-row {
            display: flex;
            justify-content: space-between;
            margin-top: 48px;
            gap: 24px;
        }
        .signature { flex: 1; border-top: 1px solid #92400e; padding-top: 12px; font-size: 14px; }
        .signature .role { color: #92400e; font-size: 12px; }
        .verify-hint {
            font-family: ui-sans-serif, system-ui, sans-serif;
            margin-top: 16px;
            font-size: 12px;
            color: #94a3b8;
        }
        .verify-hint a { color: #f59e0b; }
        @media print {
            body { background: #fff; padding: 0; }
            .certificate { box-shadow: none; border-width: 6px; }
            .verify-hint { display: none; }
        }
    </style>
</head>
<body>
    <div class="certificate">
        <div class="seal">Verified<br>Authentic</div>
        <p class="institution">{{ $institution }}</p>
        <h1>Certificate of Completion</h1>
        <p class="lead">This certificate is proudly presented to</p>
        <p class="recipient">{{ $student->name ?? 'Recipient' }}</p>
        <p class="course-line">
            for successfully completing the course
            <span class="course-name">{{ $course->title ?? 'Course' }}</span>
        </p>
        <p class="meta">Awarded on {{ $certificate->issued_at?->format('F j, Y') }}</p>
        <p class="meta">Certificate No. {{ $certificate->certificate_number }}</p>

        <div class="footer-row">
            <div class="signature">
                {{ $instructor->name ?? $institution }}
                <div class="role">{{ $instructor ? 'Lead Instructor' : 'Institution' }}</div>
            </div>
            <div class="signature" style="text-align:right">
                {{ $institution }}
                <div class="role">Academic Office</div>
            </div>
        </div>
    </div>
    <p class="verify-hint">
        Verify this certificate: <a href="{{ route('certificates.verify', $certificate->identifier) }}">{{ url('certificates/verify/'.$certificate->identifier) }}</a>
    </p>
    <script>
        window.addEventListener('load', function () {
            if (window.matchMedia('print').matches) { return; }
        });
    </script>
</body>
</html>