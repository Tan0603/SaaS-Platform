<?php

namespace App\Enums;

enum InvoiceEmailEvent: string
{
    case Issued = 'issued';
    case Overdue = 'overdue';
}
