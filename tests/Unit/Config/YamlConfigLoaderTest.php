<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Config;

use Daktela\CrmSync\Config\SkipIfExistsMode;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Sync\SyncDirection;
use PHPUnit\Framework\TestCase;

final class YamlConfigLoaderTest extends TestCase
{
    private YamlConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new YamlConfigLoader();
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedConfigProvider(): iterable
    {
        // An unknown activity type used to be dropped silently, leaving the list
        // empty — so syncActivities() iterated nothing, reported an exhausted
        // 0-record success and advanced the watermark on every run. The values
        // are lowercase while the API filter is uppercased by the adapter, which
        // makes [CALL] a natural mistake.
        yield 'uppercase activity type' => [
            "activity:\n      enabled: true\n      direction: cc_to_crm\n      mapping_file: m.yaml\n      activity_types: [CALL]",
            'unknown activity type',
        ];
        yield 'misspelled activity type' => [
            "activity:\n      enabled: true\n      direction: cc_to_crm\n      mapping_file: m.yaml\n      activity_types: [chat]",
            'unknown activity type',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedConfigProvider')]
    public function testAnUnusableEntityConfigIsRejectedRatherThanSilentlyEmptied(string $entityBlock, string $expected): void
    {
        $path = $this->writeConfig("sync:\n  batch_size: 10\n  entities:\n    {$entityBlock}\n");

        try {
            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');
            $this->loader->load($path);
        } finally {
            $this->cleanUp($path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function emptyActivityTypesProvider(): iterable
    {
        yield 'key absent' => ["activity:\n      enabled: true\n      direction: cc_to_crm\n      mapping_file: m.yaml"];
        yield 'empty list' => ["activity:\n      enabled: true\n      direction: cc_to_crm\n      mapping_file: m.yaml\n      activity_types: []"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyActivityTypesProvider')]
    public function testAnEnabledActivityEntityMustNameItsTypes(string $entityBlock): void
    {
        // syncActivities() resolves a CONFIGURED entity's types from the config —
        // the [call] fallback applies only when there is no entity config at all.
        // So an absent or empty list made it iterate nothing, report an exhausted
        // 0-record success and advance the watermark on every run: the export
        // silently never happened and only forceFullSync recovered it. Unknown
        // values were already rejected for exactly this reason; emptiness is the
        // same failure with the same cause.
        $path = $this->writeConfig("sync:\n  batch_size: 10\n  entities:\n    {$entityBlock}\n");

        try {
            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessageMatches('/activity_types is required/');
            $this->loader->load($path);
        } finally {
            $this->cleanUp($path);
        }
    }

    public function testADisabledActivityEntityNeedsNoTypes(): void
    {
        // Only an ENABLED entity is required to name them; a disabled block is
        // inert and must not block the whole config from loading.
        $path = $this->writeConfig(
            "sync:\n  batch_size: 10\n  entities:\n    activity:\n      enabled: false\n"
            . "      direction: cc_to_crm\n      mapping_file: m.yaml\n",
        );

        try {
            $config = $this->loader->load($path);
            self::assertFalse($config->isEntityEnabled('activity'));
        } finally {
            $this->cleanUp($path);
        }
    }

    public function testBatchSizeBelowOneIsRejected(): void
    {
        // batch_size 0 degrades every drain to one record per batch.
        $path = $this->writeConfig("sync:\n  batch_size: 0\n");

        try {
            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessageMatches('/batch_size must be at least 1/');
            $this->loader->load($path);
        } finally {
            $this->cleanUp($path);
        }
    }

    public function testDuplicateCustomEntityNamesAreRejected(): void
    {
        // The name is the slot key for the mapping AND the state key, so two
        // entries sharing it means one is synced with the other's rules and both
        // share one watermark.
        $path = $this->writeConfig(
            "sync:\n  batch_size: 10\n  custom_entities:\n"
            . "    - { name: deals, enabled: true, direction: crm_to_cc, source: contact, target: a, mapping_file: m.yaml }\n"
            . "    - { name: deals, enabled: true, direction: crm_to_cc, source: contact, target: b, mapping_file: m.yaml }\n",
        );

        try {
            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessageMatches('/duplicate name "deals"/');
            $this->loader->load($path);
        } finally {
            $this->cleanUp($path);
        }
    }

    private function writeConfig(string $syncBlock): string
    {
        $dir = sys_get_temp_dir() . '/crmsync_cfg_' . bin2hex(random_bytes(6));
        @mkdir($dir, 0777, true);
        $path = $dir . '/sync.yaml';
        file_put_contents(
            $path,
            "daktela:\n  instance_url: \"https://t.daktela.com\"\n  access_token: \"t\"\n  database: \"d\"\n" . $syncBlock,
        );
        // A loadable mapping file, so a config that gets far enough to read one
        // fails on the thing under test rather than on a missing file.
        file_put_contents(
            $dir . '/m.yaml',
            "entity: contact\nlookup_field: name\nmappings:\n  - { cc_field: title, crm_field: name }\n",
        );

        return $path;
    }

    private function cleanUp(string $configPath): void
    {
        $dir = dirname($configPath);
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    public function testADisabledLeftoverExportEntryDoesNotFailTheWholeConfig(): void
    {
        // An inert entry must not take contacts, accounts and activities down with
        // it. A deployed config carrying `enabled: false, direction: cc_to_crm`
        // was accepted before and never ran; failing the load for it is a far
        // wider blast radius than the entry deserves.
        $path = $this->writeConfig(
            "sync:\n  batch_size: 10\n  custom_entities:\n"
            . "    - { name: persons_export, enabled: false, direction: cc_to_crm, source: contact, target: persons, mapping_file: m.yaml }\n",
        );

        try {
            $config = $this->loader->load($path);
            self::assertSame([], $config->getEnabledCustomEntities(), 'and it stays inert');
        } finally {
            $this->cleanUp($path);
        }
    }

    public function testAnInvalidDirectionIsStillRejectedEvenWhenDisabled(): void
    {
        // A direction that does not parse at all is a typo, not an inert leftover.
        $path = $this->writeConfig(
            "sync:\n  batch_size: 10\n  custom_entities:\n"
            . "    - { name: x, enabled: false, direction: sideways, source: contact, target: persons, mapping_file: m.yaml }\n",
        );

        try {
            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessageMatches('/Invalid direction for custom entity "x"/');
            $this->loader->load($path);
        } finally {
            $this->cleanUp($path);
        }
    }

    public function testOnlyCrmToCcIsAcceptedForACustomEntity(): void
    {
        // The enum accepts `bidirectional` (first-class entities use it) but a
        // custom entity slot syncs one way and there is no handler: the entry fell
        // into the IMPORT branch and died with "Unsupported entity type: persons",
        // naming the CRM resource instead of the real problem.
        $dir = sys_get_temp_dir() . '/crmsync_cfg_' . bin2hex((string) getmypid());
        @mkdir($dir, 0777, true);
        $path = $dir . '/sync.yaml';
        file_put_contents($path, <<<YAML
            daktela:
              instance_url: "https://t.daktela.com"
              access_token: "t"
              database: "d"
            sync:
              batch_size: 10
              custom_entities:
                - name: contact_export
                  enabled: true
                  direction: bidirectional
                  source: contact
                  target: persons
                  mapping_file: mappings/contacts.yaml
            YAML);

        try {
            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessageMatches('/only "crm_to_cc" is supported/');
            $this->loader->load($path);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function testLoadValidConfig(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../Fixtures/config/sync.yaml');

        self::assertSame('https://test.daktela.com', $config->instanceUrl);
        self::assertSame('test-token', $config->accessToken);
        self::assertSame('test-db', $config->database);
        self::assertSame(50, $config->batchSize);
        self::assertSame('test-secret', $config->webhookSecret);
    }

    public function testEntityConfigs(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../Fixtures/config/sync.yaml');

        self::assertTrue($config->isEntityEnabled('contact'));
        self::assertTrue($config->isEntityEnabled('account'));
        self::assertTrue($config->isEntityEnabled('activity'));
        self::assertFalse($config->isEntityEnabled('nonexistent'));

        $contactConfig = $config->getEntityConfig('contact');
        self::assertNotNull($contactConfig);
        self::assertSame(SyncDirection::CrmToCc, $contactConfig->direction);
    }

    public function testActivityTypesLoaded(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../Fixtures/config/sync.yaml');

        $activityConfig = $config->getEntityConfig('activity');
        self::assertNotNull($activityConfig);
        self::assertCount(2, $activityConfig->activityTypes);
        self::assertSame(ActivityType::Call, $activityConfig->activityTypes[0]);
        self::assertSame(ActivityType::Email, $activityConfig->activityTypes[1]);
    }

    public function testMappingsLoaded(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../Fixtures/config/sync.yaml');

        $contactMapping = $config->getMapping('contact');
        self::assertNotNull($contactMapping);
        self::assertSame('contact', $contactMapping->entityType);
        self::assertSame('email', $contactMapping->lookupField);
    }

    public function testFileNotFoundThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->loader->load('/nonexistent/sync.yaml');
    }

    public function testLoadRawReturnsFullArrayWithEnvResolution(): void
    {
        putenv('TEST_RAW_VALUE=resolved');

        $base = tempnam(sys_get_temp_dir(), 'sync_');
        $tmpFile = $base . '.yaml';
        @unlink($base); // tempnam creates the extension-less file; don't leak it
        file_put_contents($tmpFile, implode("\n", [
            'daktela:',
            '  instance_url: "https://test.daktela.com"',
            '  access_token: "token"',
            'custom_adapter:',
            '  api_key: "${TEST_RAW_VALUE}"',
            '  setting: "literal"',
            'sync:',
            '  batch_size: 10',
            '  entities: {}',
        ]));

        try {
            $data = $this->loader->loadRaw($tmpFile);

            // SDK sections are present
            self::assertSame('https://test.daktela.com', $data['daktela']['instance_url']);

            // Adapter-specific section is preserved and env vars resolved
            self::assertIsArray($data['custom_adapter']);
            self::assertSame('resolved', $data['custom_adapter']['api_key']);
            self::assertSame('literal', $data['custom_adapter']['setting']);
        } finally {
            unlink($tmpFile);
            putenv('TEST_RAW_VALUE');
        }
    }

    public function testLoadRawFileNotFoundThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->loader->loadRaw('/nonexistent/sync.yaml');
    }

    public function testAutoCreateContactConfigLoaded(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../Fixtures/config/sync_with_auto_contact.yaml');

        $accountConfig = $config->getEntityConfig('account');
        self::assertNotNull($accountConfig);
        self::assertNotNull($accountConfig->autoCreateContact);
        self::assertSame('../mappings/account-contact.yaml', $accountConfig->autoCreateContact->mappingFile);
        self::assertSame(['email', 'number'], $accountConfig->autoCreateContact->skipIfExistsFields);
        self::assertSame(SkipIfExistsMode::All, $accountConfig->autoCreateContact->skipIfExistsMode);
        self::assertSame(['email', 'number'], $accountConfig->autoCreateContact->skipIfEmpty);

        $autoMapping = $config->getAutoCreateContactMapping('account');
        self::assertNotNull($autoMapping);
        self::assertSame('contact', $autoMapping->entityType);
        self::assertSame('name', $autoMapping->lookupField);
        self::assertCount(4, $autoMapping->mappings);
    }

    public function testAutoCreateContactSkipModeAny(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../Fixtures/config/sync_with_auto_contact_any.yaml');

        $accountConfig = $config->getEntityConfig('account');
        self::assertNotNull($accountConfig);
        self::assertNotNull($accountConfig->autoCreateContact);
        self::assertSame(SkipIfExistsMode::Any, $accountConfig->autoCreateContact->skipIfExistsMode);
    }

    public function testAutoCreateContactConfigNullWhenNotSet(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../Fixtures/config/sync.yaml');

        $accountConfig = $config->getEntityConfig('account');
        self::assertNotNull($accountConfig);
        self::assertNull($accountConfig->autoCreateContact);

        self::assertNull($config->getAutoCreateContactMapping('account'));
    }

    public function testEnvVarResolution(): void
    {
        putenv('TEST_DAKTELA_TOKEN=env-token');

        // Create a temp config with env var
        $base = tempnam(sys_get_temp_dir(), 'sync_');
        $tmpFile = $base . '.yaml';
        @unlink($base); // tempnam creates the extension-less file; don't leak it
        file_put_contents($tmpFile, "daktela:\n  instance_url: \"https://test.daktela.com\"\n  access_token: \"\${TEST_DAKTELA_TOKEN}\"\nsync:\n  batch_size: 10\n  entities: {}\nwebhook:\n  secret: \"\"");

        try {
            $config = $this->loader->load($tmpFile);
            self::assertSame('env-token', $config->accessToken);
        } finally {
            unlink($tmpFile);
            putenv('TEST_DAKTELA_TOKEN');
        }
    }
}
