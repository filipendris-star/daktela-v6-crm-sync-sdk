<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync\Result;

final readonly class FullSyncResult
{
    /**
     * @param array<string, SyncResult> $customEntities keyed by CustomEntitySyncConfig::$name
     * @param array<string, string> $stepFailures entity type => error message, for steps that
     *        failed or were skipped as a whole (an adapter fault or misconfiguration, as opposed
     *        to individual records failing). A run with any of these did NOT sync everything it
     *        was asked to, even though it returned normally — schedulers should treat it as a
     *        failed run.
     */
    public function __construct(
        public ?SyncResult $account = null,
        public ?SyncResult $autoContact = null,
        public ?SyncResult $contact = null,
        public ?SyncResult $activity = null,
        public array $customEntities = [],
        public array $stepFailures = [],
    ) {
    }

    /** True when at least one whole step failed or was skipped; see $stepFailures. */
    public function hasStepFailures(): bool
    {
        return $this->stepFailures !== [];
    }

    /**
     * @return array<string, SyncResult>
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->account !== null) {
            $result['account'] = $this->account;
        }
        if ($this->autoContact !== null) {
            $result['auto_contact'] = $this->autoContact;
        }
        if ($this->contact !== null) {
            $result['contact'] = $this->contact;
        }
        if ($this->activity !== null) {
            $result['activity'] = $this->activity;
        }
        foreach ($this->customEntities as $name => $entryResult) {
            $result["custom:{$name}"] = $entryResult;
        }

        return $result;
    }
}
