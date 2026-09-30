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
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
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
            margin: 0 0 8px;
        }
        .emblem { display: block; width: 76px; height: auto; margin: 0 auto 14px; }
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
        .print-bar {
            display: flex;
            justify-content: center;
            margin-bottom: 24px;
        }
        .print-button {
            font-family: ui-sans-serif, system-ui, sans-serif;
            font-size: 14px;
            font-weight: 600;
            color: #78350f;
            background: #f59e0b;
            border: 0;
            border-radius: 8px;
            padding: 11px 22px;
            cursor: pointer;
        }
        .print-button:hover { background: #fbbf24; }
        .verify-print {
            display: none;
            font-family: ui-sans-serif, system-ui, sans-serif;
            font-size: 9px;
            color: #92400e;
            margin: 14px 0 0;
            word-break: break-all;
        }
        @media print {
            /* A certificate is a landscape sheet, and an unsized @page means the
               browser picks its own default (letter, portrait), which has never
               been looked at. Pinning it here is what makes the printed artifact
               predictable instead of accidental. */
            @page {
                size: A4 landscape;
                margin: 10mm;
            }
            html, body {
                background: #fff;
                /* min-height:100vh plus centring is what pushes a blank second
                   page out of the printer. */
                min-height: 0;
                margin: 0;
                padding: 0;
            }
            .certificate {
                width: 100%;
                max-width: none;
                box-shadow: none;
                border-width: 8px;
                padding: 32px 36px;
            }
            .print-bar, .verify-hint { display: none; }
            /* The paper certificate carries its own way to be checked, which is
               the only thing that makes a sheet of paper trustworthy. */
            .verify-print { display: block; }
        }
    </style>
</head>
<body>
    <div class="print-bar">
        <button type="button" class="print-button" id="print-certificate">Print / Save as PDF</button>
    </div>
    <div class="certificate">
        <div class="seal">Verified<br>Authentic</div>
        <img class="emblem" src="/logo-donggala.png" alt="">
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
        <p class="verify-print">
            Verify at {{ route('certificates.verify', $certificate->identifier) }}
        </p>

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
        // The document is a complete artifact on its own, so the print control
        // lives here rather than in the React app: whoever opens this URL --
        // the student, or whoever the verify link was forwarded to -- can save
        // it as a PDF without needing the certificate list in front of them.
        document.getElementById('print-certificate').addEventListener('click', function () {
            window.print();
        });
    </script>
</body>
</html>