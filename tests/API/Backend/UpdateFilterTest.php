<?php

declare(strict_types=1);

namespace Tests\API\Backend;

use App\Libs\ConfigFile;
use App\Libs\TestCase;
use Throwable;

class UpdateFilterTest extends TestCase
{
    public function test_removed_keys_filter_logic()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');

        try {
            $initialConfig = [
                'backend1' => [
                    'type' => 'plex',
                    'url' => 'http://example.com',
                    'token' => 'token1',
                    'webhook' => [], // Should be removed
                ],
                'backend2' => [
                    'type' => 'jellyfin',
                    'url' => 'http://example2.com',
                    'token' => 'token2',
                    'webhook' => [],
                ],
            ];

            $config = ConfigFile::open($tmpFile, 'yaml', autoSave: false, autoCreate: true, autoBackup: false);
            foreach ($initialConfig as $key => $value) {
                $config->set($key, $value);
            }
            $config->persist();

            $config = ConfigFile::open($tmpFile, 'yaml', autoSave: false, autoCreate: false, autoBackup: false);

            $name = 'backend1';
            $updatedBackendConfig = [
                'type' => 'plex',
                'url' => 'http://example.com',
                'token' => 'new_token',
                // webhook is not included in the update
            ];

            $xf = function (array $data): array {
                $removed = ['webhook'];
                foreach ($removed as $key) {
                    foreach ($data as &$v) {
                        if (false === is_array($v)) {
                            continue;
                        }
                        if (false === ag_exists($v, $key)) {
                            continue;
                        }
                        $v = ag_delete($v, $key);
                    }
                }
                return $data;
            };

            $config->set($name, $updatedBackendConfig)->addFilter('removed.keys', $xf)->persist();

            $reloaded = ConfigFile::open($tmpFile, 'yaml', autoSave: false, autoCreate: false, autoBackup: false);

            $this->assertFalse(
                ag_exists($reloaded->get('backend1'), 'webhook'),
                'backend1 webhook should be removed'
            );

            $this->assertFalse(
                ag_exists($reloaded->get('backend2'), 'webhook'),
                'backend2 webhook should ALSO be removed during global cleanup'
            );

            $this->assertEquals(
                'http://example2.com',
                $reloaded->get('backend2.url'),
                'backend2 url should be unchanged'
            );

            $this->assertEquals(
                'token2',
                $reloaded->get('backend2.token'),
                'backend2 token should be unchanged'
            );
        } catch (Throwable $e) {
            $this->fail('Test failed with exception: ' . $e->getMessage());
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            if (file_exists($tmpFile . '.bak')) {
                unlink($tmpFile . '.bak');
            }
        }
    }

    public function test_removed_keys_filter_edge_cases()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');

        try {
            // Test edge cases: non-array values, nested structures, missing keys
            $initialConfig = [
                'backend1' => [
                    'type' => 'plex',
                    'url' => 'http://example.com',
                    'webhook' => 'should_be_removed',
                    'options' => [
                        'webhook' => 'nested_webhook', // Nested webhooks should NOT be removed
                        'other' => 'value',
                    ],
                ],
                'backend2' => [
                    'type' => 'jellyfin',
                    'url' => 'http://example2.com',
                    // No webhook key - should not cause issues
                ],
                'not_a_backend' => 'string_value', // Non-array value should be skipped
            ];

            $config = ConfigFile::open($tmpFile, 'yaml', autoSave: false, autoCreate: true, autoBackup: false);
            foreach ($initialConfig as $key => $value) {
                $config->set($key, $value);
            }
            $config->persist();

            // Apply filter
            $config = ConfigFile::open($tmpFile, 'yaml', autoSave: false, autoCreate: false, autoBackup: false);

            $xf = function (array $data): array {
                $removed = ['webhook'];
                foreach ($removed as $key) {
                    foreach ($data as &$v) {
                        if (false === is_array($v)) {
                            continue;
                        }
                        if (false === ag_exists($v, $key)) {
                            continue;
                        }
                        $v = ag_delete($v, $key);
                    }
                }
                return $data;
            };

            $config->set('backend1.url', 'http://updated.com')->addFilter('removed.keys', $xf)->persist();

            // Verify results
            $reloaded = ConfigFile::open($tmpFile, 'yaml', autoSave: false, autoCreate: false, autoBackup: false);

            // Top-level webhook removed from backend1
            $this->assertFalse(
                ag_exists($reloaded->get('backend1'), 'webhook'),
                'Top-level webhook in backend1 should be removed'
            );

            // Nested webhook in options should remain (filter only processes top-level backend keys)
            $this->assertTrue(
                ag_exists($reloaded->get('backend1.options'), 'webhook'),
                'Nested webhook in options should remain'
            );
            $this->assertEquals(
                'nested_webhook',
                $reloaded->get('backend1.options.webhook'),
                'Nested webhook value should be unchanged'
            );

            // backend2 without webhook key should be fine
            $this->assertIsArray($reloaded->get('backend2'), 'backend2 should still exist');
            $this->assertEquals(
                'http://example2.com',
                $reloaded->get('backend2.url'),
                'backend2 url should be unchanged'
            );

            // Non-array value should remain unchanged
            $this->assertEquals(
                'string_value',
                $reloaded->get('not_a_backend'),
                'Non-array value should be unchanged'
            );
        } catch (Throwable $e) {
            $this->fail('Test failed with exception: ' . $e->getMessage());
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            if (file_exists($tmpFile . '.bak')) {
                unlink($tmpFile . '.bak');
            }
        }
    }
}
