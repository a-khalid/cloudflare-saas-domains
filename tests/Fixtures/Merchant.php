<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Tests\Fixtures;

use AKhalid\CloudflareSaasDomains\Concerns\HasCustomDomains;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use HasCustomDomains, SoftDeletes;

    protected $guarded = [];
}
