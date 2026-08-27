<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Entity;

use Daktela\CrmSync\Entity\ActivityType;
use PHPUnit\Framework\TestCase;

final class ActivityTypeTest extends TestCase
{
    /**
     * Regression: every case must map to the type value the platform stores.
     *
     * Asserted PER CASE with assertSame, not as set membership. An earlier
     * version checked `assertContains($case->apiValue(), $platformTypes)` over a
     * list holding all eight values — so swapping Sms to 'CALL' kept the whole
     * suite green, while `activity_types: [sms]` would have filtered on CALL,
     * exported every closed call into the CRM as an SMS, and advanced the
     * watermark past the SMS records it never read. Membership cannot see that;
     * a table can.
     *
     * Source of truth: Activity::I_TYPE_* in the platform
     * (app/daktela/Shared/Constants/Activity.php).
     */
    public function testEveryCaseMapsToItsPlatformActivityTypeValue(): void
    {
        $expected = [
            'call' => 'CALL',
            'email' => 'EMAIL',
            'web' => 'CHAT',
            'sms' => 'SMS',
            'fbm' => 'FBM',
            'wap' => 'WAP',
            'vbr' => 'VBR',
            'igdm' => 'IGDM',
        ];

        $actual = [];
        foreach (ActivityType::cases() as $case) {
            $actual[$case->value] = $case->apiValue();
        }

        self::assertSame($expected, $actual, 'each config value must map to its own platform type');
    }

    public function testChatMapsToChatNotWeb(): void
    {
        self::assertSame('CHAT', ActivityType::Chat->apiValue());
        self::assertSame('web', ActivityType::Chat->value, 'the config-facing value must not change');
    }

    public function testInstagramDmIsConfigurable(): void
    {
        self::assertSame(ActivityType::InstagramDm, ActivityType::tryFrom('igdm'));
        self::assertSame('IGDM', ActivityType::InstagramDm->apiValue());
    }
}
