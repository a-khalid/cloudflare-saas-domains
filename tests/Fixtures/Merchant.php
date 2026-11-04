<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use YourVendor\CloudflareSaasDomains\Concerns\HasCustomDomains;

class Merchant extends Model
{
    use HasCustomDomains, SoftDeletes;

    protected $guarded = [];
}
