<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class OtherTenant extends Model
{
    protected $table = 'merchants';

    protected $guarded = [];
}
