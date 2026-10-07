<?php

namespace App\Services\Billing;

use RuntimeException;

/** Thrown by {@see InvoiceIssuer::issue()} when the same-period guard finds a live invoice. */
final class BillingPeriodInvoiceExists extends RuntimeException
{
}
