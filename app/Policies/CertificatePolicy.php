<?php

namespace App\Policies;

use App\Models\Certificate;
use App\Models\User;

class CertificatePolicy
{
    public function view(User $user, Certificate $certificate): bool
    {
        return (int) $certificate->student_id === (int) $user->id;
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('certificates.manage');
    }
}
