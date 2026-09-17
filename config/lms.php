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
];
