<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
}
