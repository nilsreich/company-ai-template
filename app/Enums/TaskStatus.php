<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
}
