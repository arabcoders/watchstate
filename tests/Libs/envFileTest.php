<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\EnvFile;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\TestCase;

class envFileTest extends TestCase
{
    private array $data = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->data = [
            "WS_TZ" => "Asia/Kuwait",
            "WS_CRON_IMPORT" => "1",
            "WS_CRON_EXPORT" => "0",
            "WS_CRON_IMPORT_AT" => "16 */1 * * *",
            "WS_CRON_EXPORT_AT" => "30 */3 * * *",
            "WS_CRON_PUSH_AT" => "*/10 * * * *",
        ];
    }

    public function test_constructor()
    {
        $this->checkException(
            closure: fn() => new EnvFile('nonexistent.env', create: false),
            reason: 'If file does not exist, and autoCreate is set to false, an exception should be thrown.',
            exception: RuntimeException::class,
            exceptionMessage: "does not exist.",
        );

        $tmpFile = sys_get_temp_dir() . '/watchstate_test.env';
        try {
            $envFile = new EnvFile($tmpFile, create: true);
            $this->assertFileExists($tmpFile);
            $this->assertEmpty($envFile->get(array_keys($this->data)[0]));
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function test_get()
    {
        $tmpFile = sys_get_temp_dir() . '/watchstate_test.env';
        try {
            $envFile = new EnvFile($tmpFile, create: true);
            $this->assertEmpty($envFile->get(array_keys($this->data)[0]));

            foreach ($this->data as $key => $value) {
                $envFile->set($key, $value);
                $this->assertEquals($value, $envFile->get($key), "The value of key '{$key}' should be '{$value}'.");
            }

            $this->assertNull(
                $envFile->get('nonexistent_key'),
                "The value of a nonexistent key should be NULL by default."
            );

            $this->assertSame(
                'default',
                $envFile->get('nonexistent', 'default'),
                "The default value should be returned."
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function test_set()
    {
        $tmpFile = sys_get_temp_dir() . '/watchstate_test.env';
        try {
            $envFile = new EnvFile($tmpFile, create: true);
            $this->assertEmpty($envFile->get(array_keys($this->data)[0]));

            foreach ($this->data as $key => $value) {
                $envFile->set($key, $value);
            }
            $envFile->persist();

            $envFile = new EnvFile($tmpFile);

            foreach ($this->data as $key => $value) {
                $this->assertEquals($value, $envFile->get($key), "The value of key '{$key}' should be '{$value}'.");
            }

            $this->assertNull(
                $envFile->get('nonexistent_key'),
                "The value of a nonexistent key should be NULL by default."
            );

            $this->assertSame(
                'default',
                $envFile->get('nonexistent', 'default'),
                "The default value should be returned."
            );

            $envFile->set('new_key', true);

            $this->assertTrue(
                $envFile->get('new_key'),
                "Due to unfortunate design, the value of key bool 'new_key' should be true. until we persist it."
            );

            $envFile->persist();
            $envFile = $envFile->newInstance();
            $this->assertTrue($envFile->has('new_key'), "The key 'new_key' should exist.");
            $this->assertSame(
                '1',
                $envFile->get('new_key'),
                "The value of key 'new_key' should be '1' as we cast bool to string."
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function test_has()
    {
        $tmpFile = sys_get_temp_dir() . '/watchstate_test.env';
        try {
            $envFile = new EnvFile($tmpFile, create: true);
            $this->assertEmpty($envFile->get(array_keys($this->data)[0]));

            foreach ($this->data as $key => $value) {
                $envFile->set($key, $value);
            }
            $envFile->persist();

            $envFile = new EnvFile($tmpFile);

            foreach ($this->data as $key => $value) {
                $this->assertTrue($envFile->has($key), "The key '{$key}' should exist.");
            }

            $this->assertFalse($envFile->has('nonexistent_key'), "The key 'nonexistent_key' should not exist.");
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function test_persist()
    {
        $tmpFile = sys_get_temp_dir() . '/watchstate_test.env';
        try {
            $envFile = new EnvFile($tmpFile, create: true);
            $this->assertEmpty($envFile->get(array_keys($this->data)[0]));

            foreach ($this->data as $key => $value) {
                $envFile->set($key, $value);
            }
            $envFile->persist();

            $envFile = new EnvFile($tmpFile);
            $this->assertSame($this->data, $envFile->getAll(), "The data should be persisted and retrieved correctly.");
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function test_getAll()
    {
        $tmpFile = sys_get_temp_dir() . '/watchstate_test.env';
        try {
            $envFile = new EnvFile($tmpFile, create: true);
            $this->assertEmpty($envFile->get(array_keys($this->data)[0]));

            foreach ($this->data as $key => $value) {
                $envFile->set($key, $value);
            }
            $envFile->persist();

            $envFile = new EnvFile($tmpFile);
            $this->assertSame($this->data, $envFile->getAll(), "The data should be persisted and retrieved correctly.");
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function test_remove()
    {
        $tmpFile = sys_get_temp_dir() . '/watchstate_test.env';
        $key = array_keys($this->data)[0];

        try {
            $envFile = new EnvFile($tmpFile, create: true);
            $this->assertEmpty($envFile->get(array_keys($this->data)[0]));

            foreach ($this->data as $key => $value) {
                $envFile->set($key, $value);
            }

            $envFile->persist();
            $envFile->remove($key);
            $envFile->persist();

            $envFile = new EnvFile($tmpFile);
            $this->assertNotSame($this->data, $envFile->getAll(), "The key '{$key}' should be been removed.");
            $this->assertArrayNotHasKey($key, $envFile->getAll(), "The key '{$key}' should be been removed.");
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }
}
