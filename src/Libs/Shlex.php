<?php

declare(strict_types=1);

namespace App\Libs;

use InvalidArgumentException;

/**
 * A lexical analyzer class for simple shell-like syntaxes.
 *
 * Provides shell-style parsing and tokenization of strings with support
 * for quotes, escaping, and punctuation characters.
 *
 * This class mimics the behavior of POSIX shell parsing, allowing strings
 * to be split into tokens while respecting quotes, escape characters, and
 * special punctuation.
 *
 * @see https://docs.python.org/3/library/shlex.html
 */
final class Shlex
{
    private const string DEFAULT_WHITESPACE = " \t\r\n";
    private const string DEFAULT_QUOTES = "'\"";
    private const string DEFAULT_ESCAPE = "\\";
    private const string WORDCHARS_BASE = 'abcdfeghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';
    private const string WORDCHARS_UNICODE = 'ßàáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞ';
    private const string WORDCHARS_EXTRA = '-./:=@~*?;&|$()[]{}!<>`';

    private readonly string $input;
    private int $pos = 0;
    private readonly bool $posix;
    private readonly string $wordchars;
    private readonly string $whitespace;
    private readonly string $quotes;
    private readonly string $escape;
    private readonly string $escapedquotes;
    private string|null $state = ' ';
    private string $token = '';

    /**
     * Initialize the lexical analyzer.
     *
     * @param string $input String to parse
     * @param bool $posix Enable POSIX mode (proper quote/escape handling)
     */
    public function __construct(string $input, bool $posix = true)
    {
        $this->input = $input;
        $this->posix = $posix;
        $this->whitespace = self::DEFAULT_WHITESPACE;
        $this->quotes = self::DEFAULT_QUOTES;
        $this->escape = self::DEFAULT_ESCAPE;
        $this->escapedquotes = '"';
        $this->wordchars = self::WORDCHARS_BASE . self::WORDCHARS_EXTRA . ($posix ? self::WORDCHARS_UNICODE : '');
    }

    /**
     * Get the next character from input.
     *
     * @return string The character or empty string at EOF
     */
    private function readChar(): string
    {
        if ($this->pos >= strlen($this->input)) {
            return '';
        }
        return $this->input[$this->pos++];
    }

    /**
     * Get the next token from the input.
     *
     * @return string|null The next token or null at EOF
     * @throws InvalidArgumentException On unclosed quotes or incomplete escape sequences
     */
    public function nextToken(): string|null
    {
        $quoted = false;
        $escapedstate = ' ';

        while (true) {
            $nextchar = $this->readChar();

            if (null === $this->state) {
                $this->token = '';
                break;
            }

            if (' ' === $this->state) {
                if ('' === $nextchar) {
                    $this->state = null;
                    break;
                }

                if ($this->inCharset($nextchar, $this->whitespace)) {
                    if ('' !== $this->token || ($this->posix && $quoted)) {
                        break;
                    }
                    continue;
                }

                if ($this->posix && $this->inCharset($nextchar, $this->escape)) {
                    $escapedstate = 'a';
                    $this->state = $nextchar;
                    continue;
                }

                if ($this->inCharset($nextchar, $this->wordchars)) {
                    $this->token = $nextchar;
                    $this->state = 'a';
                    continue;
                }

                if ($this->inCharset($nextchar, $this->quotes)) {
                    if (!$this->posix) {
                        $this->token = $nextchar;
                    }
                    $this->state = $nextchar;
                    continue;
                }

                $this->token = $nextchar;
                $this->state = 'a';
                continue;
            }

            if ($this->inCharset($this->state, $this->quotes)) {
                $quoted = true;
                if ('' === $nextchar) {
                    throw new InvalidArgumentException('No closing quotation');
                }

                if ($nextchar === $this->state) {
                    if (!$this->posix) {
                        $this->token .= $nextchar;
                        $this->state = ' ';
                        break;
                    }
                    $this->state = 'a';
                    continue;
                }

                if (
                    $this->posix &&
                    $this->inCharset($nextchar, $this->escape) &&
                    $this->inCharset($this->state, $this->escapedquotes)
                ) {
                    $escapedstate = $this->state;
                    $this->state = $nextchar;
                    continue;
                }

                $this->token .= $nextchar;
                continue;
            }

            if ($this->inCharset($this->state, $this->escape)) {
                if ('' === $nextchar) {
                    throw new InvalidArgumentException('No closing quotation');
                }

                if (
                    $this->inCharset($escapedstate, $this->quotes) &&
                    $nextchar !== $this->state &&
                    $nextchar !== $escapedstate
                ) {
                    $this->token .= $this->state;
                }

                $this->token .= $nextchar;
                $this->state = $escapedstate;
                continue;
            }

            if ('a' === $this->state) {
                if ('' === $nextchar) {
                    $this->state = null;
                    break;
                }

                if ($this->inCharset($nextchar, $this->whitespace)) {
                    $this->state = ' ';
                    if ('' !== $this->token || ($this->posix && $quoted)) {
                        break;
                    }
                    continue;
                }

                if ($this->posix && $this->inCharset($nextchar, $this->quotes)) {
                    $this->state = $nextchar;
                    continue;
                }

                if ($this->posix && $this->inCharset($nextchar, $this->escape)) {
                    $escapedstate = 'a';
                    $this->state = $nextchar;
                    continue;
                }

                if ($this->inCharset($nextchar, $this->wordchars) || $this->inCharset($nextchar, $this->quotes)) {
                    $this->token .= $nextchar;
                    continue;
                }

                // Non-word character, push back and end token
                $this->pos--;
                $this->state = ' ';
                if ('' !== $this->token || ($this->posix && $quoted)) {
                    break;
                }
                // Will pick up the character on next iteration
                $this->pos++;
                $this->token = $nextchar;
                $this->state = 'a';
                continue;
            }
        }

        $result = $this->token;
        $this->token = '';

        if ($this->posix && !$quoted && '' === $result) {
            return null;
        }

        return $result;
    }

    /**
     * Check if a character exists in a character set.
     *
     * @param string $ch The character to check
     * @param string $set The character set to search in
     * @return bool True if character is in set, false otherwise
     */
    private function inCharset(string $ch, string $set): bool
    {
        if ('' === $ch) {
            return false;
        }
        return str_contains($set, $ch);
    }

    /**
     * Split the string using shell-like syntax.
     *
     * This is the main entry point for secure command parsing.
     *
     * @param string $s The string to split
     * @param bool $posix Whether to use POSIX mode (default: true)
     * @return array<int, string> Array of parsed tokens
     * @throws InvalidArgumentException On unclosed quotes or incomplete escape sequences
     */
    public static function split(string $s, bool $posix = true): array
    {
        $lex = new self($s, $posix);
        $out = [];

        while (true) {
            $tok = $lex->nextToken();
            if (null === $tok) {
                break;
            }
            $out[] = $tok;
        }

        return $out;
    }

    /**
     * Join an array of strings into a shell-escaped command line.
     *
     * @param array<int, string> $split_command Array of command arguments
     * @return string The joined and properly escaped command line
     */
    public static function join(array $split_command): string
    {
        return implode(' ', array_map(self::quote(...), $split_command));
    }

    /**
     * Return a shell-escaped version of the string (POSIX-ish).
     *
     * Safe characters (alphanumeric, underscore, etc.) are returned as-is.
     * Other strings are single-quoted with proper handling of embedded quotes.
     *
     * @param string $s The string to quote
     * @return string The shell-escaped string
     */
    public static function quote(string $s): string
    {
        if ('' === $s) {
            return "''";
        }

        // If ASCII and only safe chars: no quoting needed
        // Safe chars: %+,-./0-9:=@A-Z_a-z
        if (1 === preg_match('~^[%+\-,./0-9:=@A-Z_a-z]+$~', $s)) {
            return $s;
        }

        // Single-quote strategy: ' becomes '"'"'
        return "'" . str_replace("'", "'\"'\"'", $s) . "'";
    }
}

