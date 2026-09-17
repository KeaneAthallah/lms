<?php

namespace App;

enum SubmissionStatus: string
{
    case Submitted = 'submitted';
    case Graded = 'graded';
    case Returned = 'returned';
}
