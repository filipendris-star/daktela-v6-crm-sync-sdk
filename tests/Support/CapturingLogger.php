<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Support;

/**
 * Shared PSR-3 double.
 *
 * In its own PSR-4 file on purpose. It used to be a secondary class at the
 * bottom of SyncEngineTest.php, which resolved only because a FULL run
 * require'd that file during discovery — so any single-file, IDE or CI-shard
 * run of another test that used it died with "Class not found". --filter hid
 * that, because filtering still discovers every file.
 */
final class CapturingLogger extends \Psr\Log\AbstractLogger
{
    /** @var array<string, list<string>> */
    private array $records = [];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $text = (string) $message;
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $text = str_replace('{' . $key . '}', (string) $value, $text);
            }
        }
        $this->records[(string) $level][] = $text;
    }

    /** @return list<string> */
    public function messagesAt(string $level): array
    {
        return $this->records[$level] ?? [];
    }
}
