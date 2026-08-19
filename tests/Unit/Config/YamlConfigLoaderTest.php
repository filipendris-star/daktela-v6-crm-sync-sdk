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
