<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Tenant;

class StanclMerchant extends Model implements Tenant
{
    protected $table = 'merchants';

    protected $guarded = [];

    public function getTenantKeyName(): string
    {
        return 'id';
    }

    public function getTenantKey()
    {
        return $this->getKey();
    }

    public function getInternal(string $key)
    {
        return null;
    }

    public function setInternal(string $key, $value)
    {
        return $this;
    }

    public function run(callable $callback)
    {
        return $callback($this);
    }
}
