<?php

namespace App\Enums;

enum ParentBindingOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Noop = 'noop';
}
