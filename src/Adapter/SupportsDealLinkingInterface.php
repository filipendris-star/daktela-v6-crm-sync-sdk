<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

use Daktela\CrmSync\Entity\Activity;

/**
 * Optional capability: attach an outgoing activity to a CRM deal/opportunity.
 *
 * Implementing adapters own both the resolution (e.g. "the person's most recently
 * updated open deal") and the field naming (deal_id, WhatId, ...). The engine calls
 * this after field mapping, just before the activity is upserted, whenever the
 * activity entity config declares a `link_deal` strategy.
 *
 * Implementations should cache resolutions per person within a sync run and must
 * not overwrite a deal reference already present on the activity (a mapping- or
 * caller-provided value wins).
 */
interface SupportsDealLinkingInterface
{
    /**
     * @param string $strategy strategy declared in config (e.g. "latest_open")
     * @return Activity the same or an augmented activity instance
     */
    public function linkActivityToDeal(Activity $activity, string $strategy): Activity;
}
