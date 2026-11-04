<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Dns;

use AKhalid\CloudflareSaasDomains\Enums\DnsRecordPurpose;
use AKhalid\CloudflareSaasDomains\Enums\HostnameStatus;
use AKhalid\CloudflareSaasDomains\Enums\SslStatus;
use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use AKhalid\CloudflareSaasDomains\Support\Payload;
use JsonSerializable;

/**
 * What the merchant has to do in DNS, given the domain's current state.
 */
final readonly class DnsInstructions implements JsonSerializable
{
    /**
     * @param  list<DnsRecord>  $records
     * @param  bool  $retryRequired  Validation failed. Fix DNS, then call retryValidation(); new tokens can follow.
     * @param  bool  $hostnameGone  Cloudflare no longer has this hostname. Add the domain again to get new records.
     */
    public function __construct(
        public string $hostname,
        public array $records,
        public bool $awaitingValidationRecords,
        public bool $complete,
        public bool $retryRequired = false,
        public bool $hostnameGone = false,
    ) {}

    public static function for(TenantDomain $domain, string $cnameTarget): self
    {
        if ($domain->hostnameState() === HostnameStatus::Deleted) {
            return new self($domain->hostname, [], false, false, hostnameGone: true);
        }

        $stored = $domain->verification_records ?? [];
        $hostnameActive = $domain->hostnameState()->isActive();

        $records = [new DnsRecord(
            type: 'CNAME',
            name: $domain->hostname,
            value: $cnameTarget,
            purpose: DnsRecordPurpose::Routing,
            satisfied: $hostnameActive,
        )];

        $ownership = Payload::array($stored, 'ownership') ?? [];
        $ownershipName = Payload::string($ownership, 'name');
        $ownershipValue = Payload::string($ownership, 'value');

        if (! $hostnameActive && $ownershipName !== null && $ownershipValue !== null) {
            $records[] = new DnsRecord('TXT', $ownershipName, $ownershipValue, DnsRecordPurpose::Ownership);
        }

        $sslRecords = self::sslValidationRecords($domain, $stored);

        return new self(
            hostname: $domain->hostname,
            records: [...$records, ...$sslRecords, ...self::delegationRecords($stored)],
            awaitingValidationRecords: self::isAwaitingRecords($domain, $stored, $sslRecords),
            complete: $domain->isLive(),
            retryRequired: $domain->isFailed(),
        );
    }

    /**
     * @return list<DnsRecord>
     */
    public function required(): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (DnsRecord $record): bool => $record->required && ! $record->satisfied,
        ));
    }

    /**
     * Records that are useful but not needed, such as DCV delegation.
     *
     * @return list<DnsRecord>
     */
    public function optional(): array
    {
        return array_values(array_filter($this->records, static fn (DnsRecord $record): bool => ! $record->required));
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return list<DnsRecord>
     */
    private static function sslValidationRecords(TenantDomain $domain, array $stored): array
    {
        if ($domain->sslState() !== SslStatus::PendingValidation || ! self::usesDnsValidation($stored)) {
            return [];
        }

        $records = [];

        foreach (Payload::arrays($stored, 'ssl_validation') as $record) {
            $txtName = Payload::string($record, 'txt_name');
            $txtValue = Payload::string($record, 'txt_value');
            $cname = Payload::string($record, 'cname');
            $cnameTarget = Payload::string($record, 'cname_target');

            if ($txtName !== null && $txtValue !== null) {
                $records[] = new DnsRecord('TXT', $txtName, $txtValue, DnsRecordPurpose::SslValidation);
            } elseif ($cname !== null && $cnameTarget !== null) {
                $records[] = new DnsRecord('CNAME', $cname, $cnameTarget, DnsRecordPurpose::SslValidation);
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return list<DnsRecord>
     */
    private static function delegationRecords(array $stored): array
    {
        $records = [];

        foreach (Payload::arrays($stored, 'dcv_delegation') as $record) {
            $cname = Payload::string($record, 'cname');
            $target = Payload::string($record, 'cname_target');

            if ($cname !== null && $target !== null) {
                $records[] = new DnsRecord('CNAME', $cname, $target, DnsRecordPurpose::DcvDelegation, required: false);
            }
        }

        return $records;
    }

    /**
     * Cloudflare generates DCV tokens asynchronously, so they can be missing just
     * after create. Refresh the domain and ask again.
     *
     * @param  array<string, mixed>  $stored
     * @param  list<DnsRecord>  $sslRecords
     */
    private static function isAwaitingRecords(TenantDomain $domain, array $stored, array $sslRecords): bool
    {
        if (! self::usesDnsValidation($stored)) {
            return false;
        }

        return match ($domain->sslState()) {
            SslStatus::Initializing => true,
            SslStatus::PendingValidation => $sslRecords === [],
            default => $domain->ssl_status === null,
        };
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private static function usesDnsValidation(array $stored): bool
    {
        return in_array(Payload::string($stored, 'ssl_method'), ['txt', null], true);
    }

    /**
     * @return array{hostname: string, records: list<array<string, mixed>>, awaiting_validation_records: bool, complete: bool, retry_required: bool, hostname_gone: bool}
     */
    public function toArray(): array
    {
        return [
            'hostname' => $this->hostname,
            'records' => array_map(static fn (DnsRecord $record): array => $record->toArray(), $this->records),
            'awaiting_validation_records' => $this->awaitingValidationRecords,
            'complete' => $this->complete,
            'retry_required' => $this->retryRequired,
            'hostname_gone' => $this->hostnameGone,
        ];
    }

    /**
     * @return array{hostname: string, records: list<array<string, mixed>>, awaiting_validation_records: bool, complete: bool, retry_required: bool, hostname_gone: bool}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
