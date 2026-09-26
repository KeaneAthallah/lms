<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Video Completion Threshold
    |--------------------------------------------------------------------------
    | The percentage of the video a student must watch before a video lesson
    | counts towards completion. Configurable so course authors can tune it.
    */
    'video_completion_threshold_percent' => (int) env('LMS_VIDEO_COMPLETION_THRESHOLD', 90),

    /*
    |--------------------------------------------------------------------------
    | Institution
    |--------------------------------------------------------------------------
    | Display name used on certificates and emails.
    */
    'institution_name' => env('LMS_INSTITUTION_NAME', 'Lumen Academy'),

    /*
    |--------------------------------------------------------------------------
    | Certificate Requirements
    |--------------------------------------------------------------------------
    | When true, every quiz in the course must be passed before a certificate
    | can be issued. When false, completing all lessons is sufficient.
    */
    'certificate_requires_passing_quizzes' => (bool) env('LMS_CERT_REQUIRE_QUIZ_PASS', true),

    /*
    |--------------------------------------------------------------------------
    | Assignment Reminder
    |--------------------------------------------------------------------------
    | How long before an assignment deadline the reminder notification is sent.
    */
    'assignment_reminder_hours' => (int) env('LMS_ASSIGNMENT_REMINDER_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Upload Allowlists
    |--------------------------------------------------------------------------
    |
    | Uploads are validated against an allowlist of extensions, not just a size
    | limit. A missing allowlist must never mean "accept anything", so both
    | lists below have safe defaults and are always intersected with the
    | globally forbidden list in App\Support\UploadRules.
    |
    */

    /*
     * Extensions an instructor may attach to a lesson as a material. Materials
     * are instructor-authored and served to every enrolled student, so this list
     * deliberately excludes anything a browser would execute in our origin.
     */
    'material_extensions' => [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'md', 'rtf', 'csv',
        'png', 'jpg', 'jpeg', 'gif', 'webp',
        'mp3', 'wav', 'm4a', 'mp4', 'webm', 'zip',
    ],

    /*
     * Extensions a student may submit when the assignment itself does not
     * restrict them. Deliberately narrow: student uploads are untrusted.
     */
    'default_assignment_extensions' => [
        'pdf', 'doc', 'docx', 'txt', 'md', 'rtf', 'csv',
        'png', 'jpg', 'jpeg', 'zip',
    ],

    /*
     * Never accepted, whatever an allowlist says. A `.php` or `.html` file
     * written to the local disk is inert today, but it becomes an
     * instant remote code execution the moment the disk is served by a web
     * server instead of through an authorized download route.
     */
    'forbidden_upload_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar',
        'asp', 'aspx', 'ascx', 'ashx', 'asmx', 'cer',
        'jsp', 'jspx', 'jsw', 'jsv', 'jspf', 'cfm', 'cfml',
        'htaccess', 'htpasswd', 'ini', 'env', 'exe', 'bat', 'cmd', 'com', 'cgi',
        'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'ps1', 'psm1', 'vbs', 'vbe',
        'jar', 'msi', 'scr', 'dll', 'so', 'dylib', 'app', 'com.app',
        'html', 'htm', 'xhtml', 'shtml', 'svgz',
    ],
];
