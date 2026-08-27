<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Entity;

enum ActivityType: string
{
    case Call = 'call';
    case Email = 'email';
    case Chat = 'web';
    case Sms = 'sms';
    case Messenger = 'fbm';
    case WhatsApp = 'wap';
    case Viber = 'vbr';
    case InstagramDm = 'igdm';

    /**
     * The value the platform's Activities API stores in `type`.
     *
     * NOT simply strtoupper($this->value): this enum's values come from the
     * webhook EVENT-PREFIX namespace, where web chat is "web", while the API
     * stores it as "CHAT" (Activity::I_TYPE_CHAT). Filtering on "WEB" matched
     * nothing, so a configured `activity_types: [web]` export yielded zero rows
     * on every run and advanced the watermark anyway — silently dead, and the
     * config guard waved it through because "web" IS a valid case.
     *
     * The two namespaces are kept separate rather than merged, because the enum
     * value is what appears in customer config files and renaming it would break
     * every existing `activity_types` entry.
     */
    public function apiValue(): string
    {
        return match ($this) {
            self::Chat => 'CHAT',
            default => strtoupper($this->value),
        };
    }
}
