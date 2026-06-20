<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case FAILED = 'failed';
    case PENDING_PAYMENT = 'pending_payment';
}
