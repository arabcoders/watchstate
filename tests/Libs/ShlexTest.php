<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Shlex;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive test suite for Shlex implementation.
 * Tests include security attack vectors and edge cases.
 */
class ShlexTest extends TestCase
{
    // ==================== Basic Functionality Tests ====================

    public function test_basic_split(): void
    {
        $result = Shlex::split('hello world');
        $this->assertSame(['hello', 'world'], $result);
    }

    public function test_empty_string(): void
    {
        $result = Shlex::split('');
        $this->assertSame([], $result);
    }

    public function test_single_word(): void
    {
        $result = Shlex::split('hello');
        $this->assertSame(['hello'], $result);
    }

    public function test_multiple_spaces(): void
    {
        $result = Shlex::split('hello    world     foo');
        $this->assertSame(['hello', 'world', 'foo'], $result);
    }

    public function test_tabs_and_spaces(): void
    {
        $result = Shlex::split("hello\tworld  \t  foo");
        $this->assertSame(['hello', 'world', 'foo'], $result);
    }

    // ==================== Quote Handling Tests ====================

    public function test_single_quotes(): void
    {
        $result = Shlex::split("'hello world'");
        $this->assertSame(['hello world'], $result);
    }

    public function test_double_quotes(): void
    {
        $result = Shlex::split('"hello world"');
        $this->assertSame(['hello world'], $result);
    }

    public function test_mixed_quotes(): void
    {
        $result = Shlex::split('hello "world foo" bar');
        $this->assertSame(['hello', 'world foo', 'bar'], $result);
    }

    public function test_nested_quotes_single_in_double(): void
    {
        $result = Shlex::split('"hello \'world\' foo"');
        $this->assertSame(["hello 'world' foo"], $result);
    }

    public function test_nested_quotes_double_in_single(): void
    {
        $result = Shlex::split('\'hello "world" foo\'');
        $this->assertSame(['hello "world" foo'], $result);
    }

    public function test_empty_quotes(): void
    {
        $result = Shlex::split('hello "" world');
        $this->assertSame(['hello', '', 'world'], $result);
    }

    public function test_adjacent_quotes(): void
    {
        $result = Shlex::split('"hello""world"');
        $this->assertSame(['helloworld'], $result);
    }

    // ==================== Escape Sequence Tests ====================

    public function test_backslash_escape(): void
    {
        $result = Shlex::split('hello\\ world');
        $this->assertSame(['hello world'], $result);
    }

    public function test_escaped_quote_in_double_quotes(): void
    {
        $result = Shlex::split('"hello \\"world\\" foo"');
        $this->assertSame(['hello "world" foo'], $result);
    }

    public function test_escaped_backslash(): void
    {
        $result = Shlex::split('hello\\\\ world');
        $this->assertSame(['hello\\', 'world'], $result);
    }

    public function test_escape_special_chars(): void
    {
        $result = Shlex::split('hello\\;world\\|test');
        $this->assertSame(['hello;world|test'], $result);
    }

    // ==================== Command Injection Attack Tests ====================

    public function test_injection_semicolon(): void
    {
        // Attacker tries: state:export --backend=test; rm -rf ./var
        $result = Shlex::split('state:export --backend=test; rm -rf ./var');
        $this->assertSame(['state:export', '--backend=test;', 'rm', '-rf', './var'], $result);
        // Each part is separate token - semicolon attached to backend param
    }

    public function test_injection_pipe(): void
    {
        // Attacker tries: state:export --backend=test | cat /etc/passwd
        $result = Shlex::split('state:export --backend=test | cat /etc/passwd');
        $this->assertSame(['state:export', '--backend=test', '|', 'cat', '/etc/passwd'], $result);
        // Pipe is separate token, won't be interpreted by Process array constructor
    }

    public function test_injection_ampersand(): void
    {
        // Attacker tries: state:export --backend=test && evil-command
        $result = Shlex::split('state:export --backend=test && evil-command');
        $this->assertSame(['state:export', '--backend=test', '&&', 'evil-command'], $result);
        // && is separate token
    }

    public function test_injection_backticks(): void
    {
        // Attacker tries: state:export --backend=`whoami`
        $result = Shlex::split('state:export --backend=`whoami`');
        $this->assertSame(['state:export', '--backend=`whoami`'], $result);
        // Backticks treated as regular chars, not command substitution
    }

    public function test_injection_dollar_paren(): void
    {
        // Attacker tries: state:export --backend=$(evil-command)
        $result = Shlex::split('state:export --backend=$(evil-command)');
        $this->assertSame(['state:export', '--backend=$(evil-command)'], $result);
        // $() treated as regular chars
    }

    public function test_injection_redirect(): void
    {
        // Attacker tries: state:export > /tmp/output
        $result = Shlex::split('state:export > /tmp/output');
        $this->assertSame(['state:export', '>', '/tmp/output'], $result);
        // Redirect is separate token
    }

    public function test_injection_newline(): void
    {
        // Attacker tries to inject newline command
        $result = Shlex::split("state:export\nrm -rf ./var");
        $this->assertSame(['state:export', 'rm', '-rf', './var'], $result);
        // Newline is whitespace, splits tokens
    }

    public function test_injection_null_byte(): void
    {
        // Null byte injection attempt
        $result = Shlex::split("state:export\0--malicious");
        // Null bytes should be handled gracefully
        $this->assertIsArray($result);
    }

    // ==================== Complex Real-World Command Tests ====================

    public function test_console_command_with_options(): void
    {
        $result = Shlex::split('state:export --backend=plex --dry-run --force');
        $this->assertSame(['state:export', '--backend=plex', '--dry-run', '--force'], $result);
    }

    public function test_console_command_with_quoted_value(): void
    {
        $result = Shlex::split('state:export --backend="my backend" --option=value');
        $this->assertSame(['state:export', '--backend=my backend', '--option=value'], $result);
    }

    public function test_console_command_with_json(): void
    {
        $result = Shlex::split('state:export --data=\'{"key":"value"}\'');
        $this->assertSame(['state:export', '--data={"key":"value"}'], $result);
    }

    public function test_console_command_with_paths(): void
    {
        $result = Shlex::split('state:import --file=/tmp/backup.json --output=/var/log/result.log');
        $this->assertSame(['state:import', '--file=/tmp/backup.json', '--output=/var/log/result.log'], $result);
    }

    public function test_console_command_with_wildcards(): void
    {
        $result = Shlex::split('state:export --match="*.mkv" --exclude="*.nfo"');
        $this->assertSame(['state:export', '--match=*.mkv', '--exclude=*.nfo'], $result);
    }

    public function test_console_command_with_special_chars_quoted(): void
    {
        $result = Shlex::split('state:export --title="Movie (2024) - Part 1 & 2"');
        $this->assertSame(['state:export', '--title=Movie (2024) - Part 1 & 2'], $result);
    }

    // ==================== Edge Cases and Error Handling ====================

    public function test_unclosed_single_quote_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No closing quotation');
        Shlex::split("hello 'world");
    }

    public function test_unclosed_double_quote_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No closing quotation');
        Shlex::split('hello "world');
    }

    public function test_trailing_backslash_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No closing quotation');
        Shlex::split('hello world\\');
    }

    public function test_unicode_characters(): void
    {
        $result = Shlex::split('hello wörld café');
        $this->assertSame(['hello', 'wörld', 'café'], $result);
    }

    public function test_emoji_in_string(): void
    {
        $result = Shlex::split('hello 🌍 world 🎉');
        $this->assertSame(['hello', '🌍', 'world', '🎉'], $result);
    }

    public function test_very_long_string(): void
    {
        $longString = str_repeat('a', 10000);
        $result = Shlex::split($longString);
        $this->assertSame([$longString], $result);
    }

    public function test_many_arguments(): void
    {
        $args = array_map(fn($i) => "arg{$i}", range(1, 100));
        $input = implode(' ', $args);
        $result = Shlex::split($input);
        $this->assertSame($args, $result);
    }


    // ==================== Shlex Quote/Join Tests ====================

    public function test_shlex_quote_simple(): void
    {
        $result = Shlex::quote('hello');
        $this->assertSame('hello', $result);
    }

    public function test_shlex_quote_with_space(): void
    {
        $result = Shlex::quote('hello world');
        $this->assertSame("'hello world'", $result);
    }

    public function test_shlex_quote_with_single_quote(): void
    {
        $result = Shlex::quote("hello'world");
        $this->assertSame("'hello'\"'\"'world'", $result);
    }

    public function test_shlex_quote_empty(): void
    {
        $result = Shlex::quote('');
        $this->assertSame("''", $result);
    }

    public function test_shlex_quote_special_chars(): void
    {
        $result = Shlex::quote('hello;world|test');
        $this->assertSame("'hello;world|test'", $result);
    }

    public function test_shlex_join(): void
    {
        $result = Shlex::join(['hello', 'world', 'foo bar']);
        $this->assertSame("hello world 'foo bar'", $result);
    }

    public function test_shlex_join_with_special_chars(): void
    {
        $result = Shlex::join(['cmd', '--option=value', 'arg with spaces', 'test;injection']);
        $this->assertSame("cmd --option=value 'arg with spaces' 'test;injection'", $result);
    }

    // ==================== Round-Trip Tests ====================

    public function test_roundtrip_simple(): void
    {
        $original = ['hello', 'world', 'test'];
        $joined = Shlex::join($original);
        $split = Shlex::split($joined);
        $this->assertSame($original, $split);
    }

    public function test_roundtrip_with_quotes(): void
    {
        $original = ['hello', 'world test', 'foo'];
        $joined = Shlex::join($original);
        $split = Shlex::split($joined);
        $this->assertSame($original, $split);
    }

    public function test_roundtrip_with_special_chars(): void
    {
        $original = ['cmd', 'arg;test', 'foo|bar', 'test&&evil'];
        $joined = Shlex::join($original);
        $split = Shlex::split($joined);
        $this->assertSame($original, $split);
    }

    // ==================== Security-Focused Integration Tests ====================

    /**
     * Test that even with malicious input, the parsing is safe and predictable.
     */
    public function test_security_full_injection_attempt(): void
    {
        // Test various injection attempts that are properly quoted
        $malicious = 'state:export --backend="test" ; rm -rf ./var';
        $result = Shlex::split($malicious);

        // Should parse into safe tokens
        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));

        // The semicolon and command should be separate tokens
        // but won't be executed as shell commands when used with Process array
        $this->assertContains('state:export', $result);
        $this->assertContains(';', $result);
    }

    public function test_security_no_shell_interpretation(): void
    {
        // These should all be parsed as literal strings, not shell commands
        $dangerous = [
            'test $(whoami)',
            'test `id`',
            'test | cat /etc/passwd',
            'test && malicious',
            'test ; rm -rf ./var',
            'test > /tmp/output',
            'test < /etc/passwd',
        ];

        foreach ($dangerous as $cmd) {
            $result = Shlex::split($cmd);
            $this->assertIsArray($result);

            // Should be split into tokens, but shell metacharacters are preserved as literals
            // When used with Process array constructor, they won't be interpreted
            $this->assertGreaterThan(0, count($result));
        }
    }

    /**
     * Test pathological cases that might cause issues.
     */
    public function test_pathological_cases(): void
    {
        $cases = [
            '""""""',  // Multiple empty quotes
            "''''''",  // Multiple empty single quotes
            '\\\\\\\\',  // Multiple backslashes
            '"""hello"""',  // Excessive quotes
            'a b c d e f g h i j k l m n o p',  // Many short args
        ];

        foreach ($cases as $case) {
            $result = Shlex::split($case);
            $this->assertIsArray($result);
        }
    }

    /**
     * Verify behavior matches expected output for common console commands.
     */
    public function test_real_world_console_commands(): void
    {
        $commands = [
            'state:export --backend=plex' => ['state:export', '--backend=plex'],
            'state:import --file="/tmp/backup.json"' => ['state:import', '--file=/tmp/backup.json'],
            'config:view -vvv' => ['config:view', '-vvv'],
            'state:export -v --dry-run' => ['state:export', '-v', '--dry-run'],
        ];

        foreach ($commands as $input => $expected) {
            $result = Shlex::split($input);
            $this->assertSame($expected, $result, "Failed for: {$input}");
        }
    }
}

