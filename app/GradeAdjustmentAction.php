<?php

namespace App;

enum GradeAdjustmentAction: string
{
    case Override = 'override';
    case ClearOverride = 'clear_override';
    case Drop = 'drop';
    case Restore = 'restore';
    case Annotate = 'annotate';
}
