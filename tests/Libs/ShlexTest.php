<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Shlex;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShlexTest extends TestCase
{
    public function test_split_basic_input(): void
    {
        $cases = [
            '' => [],
            'hello' => ['hello'],
            'hello world' => ['hello', 'world'],
            "hello\tworld  \t  foo" => ['hello', 'world', 'foo'],
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, Shlex::split($input), $input);
        }
    }

    public function test_split_quotes(): void
    {
        $cases = [
            "'hello world'" => ['hello world'],
            '"hello world"' => ['hello world'],
            'hello "world foo" bar' => ['hello', 'world foo', 'bar'],
            '"hello \'world\' foo"' => ["hello 'world' foo"],
            '\'hello "world" foo\'' => ['hello "world" foo'],
            'hello "" world' => ['hello', '', 'world'],
            '"hello""world"' => ['helloworld'],
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, Shlex::split($input), $input);
        }
    }

    public function test_split_escapes(): void
    {
        $cases = [
            'hello\\ world' => ['hello world'],
            '"hello \\"world\\" foo"' => ['hello "world" foo'],
            'hello\\\\ world' => ['hello\\', 'world'],
            'hello\\;world\\|test' => ['hello;world|test'],
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, Shlex::split($input), $input);
        }
    }

    public function test_split_shell_metacharacters_as_tokens(): void
    {
        $cases = [
            'state:export --backend=test; rm -rf ./var' => ['state:export', '--backend=test;', 'rm', '-rf', './var'],
            'state:export --backend=test | cat /etc/passwd' => ['state:export', '--backend=test', '|', 'cat', '/etc/passwd'],
            'state:export --backend=test && evil-command' => ['state:export', '--backend=test', '&&', 'evil-command'],
            'state:export --backend=`whoami`' => ['state:export', '--backend=`whoami`'],
            'state:export --backend=$(evil-command)' => ['state:export', '--backend=$(evil-command)'],
            'state:export > /tmp/output' => ['state:export', '>', '/tmp/output'],
            "state:export\nrm -rf ./var" => ['state:export', 'rm', '-rf', './var'],
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, Shlex::split($input), $input);
        }
    }

    public function test_split_real_command_shapes(): void
    {
        $cases = [
            'state:export --backend=plex --dry-run --force' => ['state:export', '--backend=plex', '--dry-run', '--force'],
            'state:export --backend="my backend" --option=value' => ['state:export', '--backend=my backend', '--option=value'],
            'state:export --data=\'{"key":"value"}\'' => ['state:export', '--data={"key":"value"}'],
            'state:import --file=/tmp/backup.json --output=/var/log/result.log' => ['state:import', '--file=/tmp/backup.json', '--output=/var/log/result.log'],
            'state:export --match="*.mkv" --exclude="*.nfo"' => ['state:export', '--match=*.mkv', '--exclude=*.nfo'],
            'state:export --title="Movie (2024) - Part 1 & 2"' => ['state:export', '--title=Movie (2024) - Part 1 & 2'],
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, Shlex::split($input), $input);
        }
    }

    public function test_split_unicode(): void
    {
        $this->assertSame(['hello', 'wörld', 'café'], Shlex::split('hello wörld café'));
        $this->assertSame(['hello', '🌍', 'world', '🎉'], Shlex::split('hello 🌍 world 🎉'));
    }

    #[DataProvider('invalidSplitProvider')]
    public function test_split_invalid_input(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No closing quotation');
        Shlex::split($input);
    }

    public function test_quote(): void
    {
        $cases = [
            'hello' => 'hello',
            'hello world' => "'hello world'",
            "hello'world" => "'hello'\"'\"'world'",
            '' => "''",
            'hello;world|test' => "'hello;world|test'",
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, Shlex::quote($input), $input);
        }
    }

    public function test_join_quotes_unsafe_arguments(): void
    {
        $this->assertSame("hello world 'foo bar'", Shlex::join(['hello', 'world', 'foo bar']));
        $this->assertSame(
            "cmd --option=value 'arg with spaces' 'test;injection'",
            Shlex::join(['cmd', '--option=value', 'arg with spaces', 'test;injection'])
        );
    }

    public function test_join_and_split_roundtrip(): void
    {
        $cases = [
            ['hello', 'world', 'test'],
            ['hello', 'world test', 'foo'],
            ['cmd', 'arg;test', 'foo|bar', 'test&&evil'],
            ['hello', 'wörld', 'Movie (2024)', '$(noop)'],
        ];

        foreach ($cases as $expected) {
            $this->assertSame($expected, Shlex::split(Shlex::join($expected)));
        }
    }

    public static function invalidSplitProvider(): array
    {
        return [
            ["hello 'world"],
            ['hello "world'],
            ['hello world\\'],
        ];
    }
}
