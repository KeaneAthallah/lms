<?php

namespace App;

enum LessonType: string
{
    case Video = 'video';
    case Text = 'text';
    case Document = 'document';
    case ExternalLink = 'external_link';
    case Quiz = 'quiz';
    case Assignment = 'assignment';
}
