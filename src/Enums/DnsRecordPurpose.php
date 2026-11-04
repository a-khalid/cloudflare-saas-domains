<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Enums;

enum DnsRecordPurpose: string
{
    case Routing = 'routing';
    case Ownership = 'ownership';
    case SslValidation = 'ssl_validation';
    case DcvDelegation = 'dcv_delegation';
}
