<?php

namespace App;

enum Role: string
{
    case Admin = 'admin';
    case Instructor = 'instructor';
    case Student = 'student';
    case CustomerService = 'customer_service';
}
