<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\TestCase;

class UtilsTest extends TestCase
{
    public function test_flatArray_with_empty_array(): void
    {
        $result = flatArray([]);
        $this->assertSame([], $result, 'Empty array should return empty array');
    }

    public function test_flatArray_with_simple_array(): void
    {
        $input = [
            'name' => 'John',
            'age' => 30,
            'active' => true,
        ];

        $result = flatArray($input);

        $expected = [
            'name' => 'John',
            'age' => 30,
            'active' => true,
        ];

        $this->assertSame($expected, $result, 'Simple flat array should remain unchanged');
    }

    public function test_flatArray_with_nested_array(): void
    {
        $input = [
            'user' => [
                'name' => 'John',
                'age' => 30,
            ],
            'active' => true,
        ];

        $result = flatArray($input);

        $expected = [
            'user.name' => 'John',
            'user.age' => 30,
            'active' => true,
        ];

        $this->assertSame($expected, $result, 'Nested array should be flattened with dot notation');
    }

    public function test_flatArray_with_deeply_nested_array(): void
    {
        $input = [
            'company' => [
                'department' => [
                    'team' => [
                        'lead' => 'Alice',
                    ],
                ],
            ],
        ];

        $result = flatArray($input);

        $expected = [
            'company.department.team.lead' => 'Alice',
        ];

        $this->assertSame($expected, $result, 'Deeply nested array should be flattened');
    }

    public function test_flatArray_with_empty_nested_array(): void
    {
        $input = [
            'user' => [],
            'name' => 'John',
        ];

        $result = flatArray($input);

        $expected = [
            'user' => [],
            'name' => 'John',
        ];

        $this->assertSame($expected, $result, 'Empty nested array should be included as-is');
    }

    public function test_flatArray_with_mixed_types(): void
    {
        $input = [
            'string' => 'value',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'array' => [
                'nested' => 'data',
            ],
        ];

        $result = flatArray($input);

        $expected = [
            'string' => 'value',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'array.nested' => 'data',
        ];

        $this->assertSame($expected, $result, 'Mixed types should be handled correctly');
    }

    public function test_flatArray_with_object(): void
    {
        $obj = new \stdClass();
        $obj->name = 'John';
        $obj->age = 30;

        $input = [
            'user' => $obj,
        ];

        $result = flatArray($input);

        $expected = [
            'user.name' => 'John',
            'user.age' => 30,
        ];

        $this->assertSame($expected, $result, 'Object should be flattened like array');
    }

    public function test_flatArray_with_nested_objects(): void
    {
        $innerObj = new \stdClass();
        $innerObj->city = 'New York';

        $userObj = new \stdClass();
        $userObj->name = 'John';
        $userObj->address = $innerObj;

        $input = [
            'user' => $userObj,
        ];

        $result = flatArray($input);

        $expected = [
            'user.name' => 'John',
            'user.address.city' => 'New York',
        ];

        $this->assertSame($expected, $result, 'Nested objects should be flattened');
    }

    public function test_flatArray_with_custom_prefix(): void
    {
        $input = [
            'name' => 'John',
            'email' => 'john@example.com',
        ];

        $result = flatArray($input, 'user');

        $expected = [
            'user.name' => 'John',
            'user.email' => 'john@example.com',
        ];

        $this->assertSame($expected, $result, 'Custom prefix should be prepended');
    }

    public function test_flatArray_with_prefix_and_nested(): void
    {
        $input = [
            'profile' => [
                'name' => 'John',
                'age' => 30,
            ],
        ];

        $result = flatArray($input, 'user');

        $expected = [
            'user.profile.name' => 'John',
            'user.profile.age' => 30,
        ];

        $this->assertSame($expected, $result, 'Prefix should be combined with nested keys');
    }

    public function test_flatArray_with_multiple_nested_levels(): void
    {
        $input = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'value' => 'deep',
                    ],
                ],
                'other' => 'sibling',
            ],
            'top' => 'level',
        ];

        $result = flatArray($input);

        $expected = [
            'level1.level2.level3.value' => 'deep',
            'level1.other' => 'sibling',
            'top' => 'level',
        ];

        $this->assertSame($expected, $result, 'Multiple nested levels should be flattened correctly');
    }

    public function test_flatArray_with_array_containing_empty_object(): void
    {
        $input = [
            'user' => new \stdClass(),
            'name' => 'John',
        ];

        $result = flatArray($input);

        $this->assertArrayHasKey('user', $result, 'Empty object should be included');
        $this->assertInstanceOf(\stdClass::class, $result['user'], 'Empty object should remain as object');
        $this->assertSame('John', $result['name'], 'Name should be in result');
    }

    public function test_flatArray_with_zero_values(): void
    {
        $input = [
            'count' => 0,
            'ratio' => 0.0,
            'nested' => [
                'zero' => 0,
            ],
        ];

        $result = flatArray($input);

        $expected = [
            'count' => 0,
            'ratio' => 0.0,
            'nested.zero' => 0,
        ];

        $this->assertSame($expected, $result, 'Zero values should be preserved');
    }

    public function test_flatArray_with_false_values(): void
    {
        $input = [
            'active' => false,
            'settings' => [
                'enabled' => false,
            ],
        ];

        $result = flatArray($input);

        $expected = [
            'active' => false,
            'settings.enabled' => false,
        ];

        $this->assertSame($expected, $result, 'False values should be preserved');
    }

    public function test_flatArray_with_empty_strings(): void
    {
        $input = [
            'name' => '',
            'details' => [
                'description' => '',
            ],
        ];

        $result = flatArray($input);

        $expected = [
            'name' => '',
            'details.description' => '',
        ];

        $this->assertSame($expected, $result, 'Empty strings should be preserved');
    }

    public function test_flatArray_with_numeric_keys(): void
    {
        $input = [
            'items' => [
                0 => 'first',
                1 => 'second',
                2 => 'third',
            ],
        ];

        $result = flatArray($input);

        $expected = [
            'items.0' => 'first',
            'items.1' => 'second',
            'items.2' => 'third',
        ];

        $this->assertSame($expected, $result, 'Numeric keys should be included in flattened keys');
    }

    public function test_flatArray_with_special_characters_in_keys(): void
    {
        $input = [
            'user_name' => 'John',
            'user-email' => 'john@example.com',
            'nested' => [
                'key_with_underscore' => 'value',
            ],
        ];

        $result = flatArray($input);

        $expected = [
            'user_name' => 'John',
            'user-email' => 'john@example.com',
            'nested.key_with_underscore' => 'value',
        ];

        $this->assertSame($expected, $result, 'Special characters in keys should be preserved');
    }

    public function test_flatArray_with_array_values_in_nested(): void
    {
        $input = [
            'config' => [
                'tags' => ['tag1', 'tag2'],
                'name' => 'test',
            ],
        ];

        $result = flatArray($input);

        $expected = [
            'config.tags.0' => 'tag1',
            'config.tags.1' => 'tag2',
            'config.name' => 'test',
        ];

        $this->assertSame($expected, $result, 'Array values should also be flattened');
    }

    public function test_flatArray_with_custom_separator(): void
    {
        $input = [
            'user' => [
                'name' => 'John',
                'age' => 30,
            ],
            'active' => true,
        ];

        $result = flatArray($input, '', '_');

        $expected = [
            'user_name' => 'John',
            'user_age' => 30,
            'active' => true,
        ];

        $this->assertSame($expected, $result, 'Custom separator should be used instead of dot');
    }

    public function test_flatArray_with_separator_and_prefix(): void
    {
        $input = [
            'profile' => [
                'name' => 'John',
            ],
        ];

        $result = flatArray($input, 'user', '_');

        $expected = [
            'user_profile_name' => 'John',
        ];

        $this->assertSame($expected, $result, 'Separator should work with prefix');
    }

    public function test_flatArray_with_hyphen_separator(): void
    {
        $input = [
            'company' => [
                'department' => [
                    'team' => 'engineering',
                ],
            ],
        ];

        $result = flatArray($input, '', '-');

        $expected = [
            'company-department-team' => 'engineering',
        ];

        $this->assertSame($expected, $result, 'Hyphen separator should work');
    }

    public function test_flatArray_with_double_colon_separator(): void
    {
        $input = [
            'app' => [
                'config' => [
                    'debug' => true,
                ],
            ],
        ];

        $result = flatArray($input, '', '::');

        $expected = [
            'app::config::debug' => true,
        ];

        $this->assertSame($expected, $result, 'Multi-character separator should work');
    }

    public function test_flatArray_with_separator_deeply_nested(): void
    {
        $input = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'value' => 'deep',
                    ],
                ],
            ],
        ];

        $result = flatArray($input, '', '|');

        $expected = [
            'level1|level2|level3|value' => 'deep',
        ];

        $this->assertSame($expected, $result, 'Separator should be used at all nesting levels');
    }

    public function test_flatArray_default_separator_is_dot(): void
    {
        $input = [
            'user' => [
                'name' => 'John',
            ],
        ];

        $result = flatArray($input);

        $this->assertArrayHasKey('user.name', $result, 'Default separator should be dot');
        $this->assertSame('John', $result['user.name']);
    }

    public function test_flatArray_with_empty_string_separator(): void
    {
        $input = [
            'a' => [
                'b' => 'value',
            ],
        ];

        $result = flatArray($input, '', '');

        $expected = [
            'ab' => 'value',
        ];

        $this->assertSame($expected, $result, 'Empty string separator should concatenate keys');
    }

    public function test_flatArray_separator_with_mixed_types(): void
    {
        $input = [
            'string' => 'text',
            'nested' => [
                'int' => 42,
                'bool' => false,
                'deep' => [
                    'null' => null,
                ],
            ],
        ];

        $result = flatArray($input, '', '/');

        $expected = [
            'string' => 'text',
            'nested/int' => 42,
            'nested/bool' => false,
            'nested/deep/null' => null,
        ];

        $this->assertSame($expected, $result, 'Separator should work with mixed types');
    }

    public function test_flatArray_separator_with_numeric_keys(): void
    {
        $input = [
            'items' => [
                0 => 'first',
                1 => 'second',
            ],
        ];

        $result = flatArray($input, '', '-');

        $expected = [
            'items-0' => 'first',
            'items-1' => 'second',
        ];

        $this->assertSame($expected, $result, 'Separator should work with numeric keys');
    }

    public function test_flatArray_separator_with_prefix_and_nested(): void
    {
        $input = [
            'config' => [
                'database' => [
                    'host' => 'localhost',
                ],
            ],
        ];

        $result = flatArray($input, 'app', '::');

        $expected = [
            'app::config::database::host' => 'localhost',
        ];

        $this->assertSame($expected, $result, 'Separator should work with both prefix and nesting');
    }
}

