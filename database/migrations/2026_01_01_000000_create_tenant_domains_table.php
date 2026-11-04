<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            match (config('cloudflare-saas-domains.tenant_key_type', 'int')) {
                'uuid' => $table->uuidMorphs('tenant'),
                'ulid' => $table->ulidMorphs('tenant'),
                'string' => $this->stringMorphs($table),
                default => $table->morphs('tenant'),
            };

            $table->string('hostname')->unique();
            $table->string('cloudflare_id', 64)->nullable()->index();
            $table->string('hostname_status', 32)->nullable()->index();
            $table->string('ssl_status', 32)->nullable()->index();
            $table->json('verification_records')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->timestamp('last_checked_at')->nullable()->index();
            $table->timestamp('verified_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('cloudflare-saas-domains.table', 'tenant_domains');

        return is_string($table) ? $table : 'tenant_domains';
    }

    private function stringMorphs(Blueprint $table): void
    {
        $table->string('tenant_type');
        $table->string('tenant_id');
        $table->index(['tenant_type', 'tenant_id']);
    }
};
