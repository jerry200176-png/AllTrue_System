<?php

namespace App\Enums;

enum ParentBindingMethod: string
{
    case Name = 'name';
    case StudentId = 'student_id';
    case Unknown = 'unknown';
}
