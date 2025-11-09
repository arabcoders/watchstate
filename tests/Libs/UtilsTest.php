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

    // ==================== validateServersData() Tests ====================

    public function test_validateServersData_with_valid_configuration(): void
    {
        $data = [
            'plex_main' => [
                'name' => 'plex_main',
                'type' => 'plex',
                'url' => 'http://localhost:32400',
                'token' => 'abc123',
                'uuid' => 'server-uuid',
                'user' => '1',
                'export' => ['enabled' => true, 'lastSync' => 1234567890],
                'import' => ['enabled' => true, 'lastSync' => 1234567890],
                'options' => [
                    'LIBRARY_SEGMENT' => 500,
                    'ignore' => 'Library1,Library2'
                ]
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Valid configuration should pass validation');
        $this->assertArrayNotHasKey('errors', $result, 'Valid configuration should not have errors');
    }

    public function test_validateServersData_with_multiple_backends(): void
    {
        $data = [
            'plex_server' => [
                'name' => 'plex_server',
                'type' => 'plex',
                'url' => 'http://plex:32400',
                'token' => 'token1'
            ],
            'jellyfin_server' => [
                'name' => 'jellyfin_server',
                'type' => 'jellyfin',
                'url' => 'http://jellyfin:8096',
                'token' => 'token2'
            ],
            'emby_server' => [
                'name' => 'emby_server',
                'type' => 'emby',
                'url' => 'http://emby:8096',
                'token' => 'token3'
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Multiple valid backends should pass validation');
    }

    public function test_validateServersData_with_invalid_backend_name(): void
    {
        $data = [
            'Invalid Name!' => [
                'name' => 'test',
                'type' => 'plex',
            ]
        ];

        $result = validateServersData($data);

        $this->assertFalse($result['valid'], 'Invalid backend name should fail validation');
        $this->assertArrayHasKey('errors', $result, 'Should return errors array');
        $this->assertCount(1, $result['errors'], 'Should have exactly one error');
        $this->assertStringContainsString('Invalid Name!', $result['errors'][0], 'Error should mention invalid name');
        $this->assertStringContainsString(
            'lowercase a-z, 0-9, _',
            $result['errors'][0],
            'Error should explain valid format'
        );
    }

    public function test_validateServersData_with_invalid_backend_type(): void
    {
        $data = [
            'my_backend' => [
                'name' => 'my_backend',
                'type' => 'kodi',
            ]
        ];

        $result = validateServersData($data);

        $this->assertFalse($result['valid'], 'Invalid backend type should fail validation');
        $this->assertArrayHasKey('errors', $result, 'Should return errors array');
        $this->assertStringContainsString('type', $result['errors'][0], 'Error should mention type field');
        $this->assertStringContainsString(
            'plex, emby, jellyfin',
            $result['errors'][0],
            'Error should list valid types'
        );
    }

    public function test_validateServersData_with_unknown_field(): void
    {
        $data = [
            'my_backend' => [
                'name' => 'my_backend',
                'type' => 'plex',
                'unknown_field' => 'value'
            ]
        ];

        $result = validateServersData($data);

        $this->assertFalse($result['valid'], 'Unknown field should fail validation');
        $this->assertArrayHasKey('errors', $result, 'Should return errors array');
        $this->assertStringContainsString('unknown_field', $result['errors'][0], 'Error should mention unknown field');
        $this->assertStringContainsString(
            'Unknown field',
            $result['errors'][0],
            'Error should indicate field is unknown'
        );
    }

    public function test_validateServersData_with_custom_validation_failure(): void
    {
        $data = [
            'my_plex' => [
                'name' => 'my_plex',
                'type' => 'plex',
                'options' => [
                    'LIBRARY_SEGMENT' => 250  // Should fail (< 300)
                ]
            ]
        ];

        $result = validateServersData($data);

        $this->assertFalse($result['valid'], 'LIBRARY_SEGMENT below 300 should fail validation');
        $this->assertArrayHasKey('errors', $result, 'Should return errors array');
        $this->assertStringContainsString(
            'LIBRARY_SEGMENT',
            $result['errors'][0],
            'Error should mention LIBRARY_SEGMENT'
        );
        $this->assertStringContainsString('300', $result['errors'][0], 'Error should mention minimum value');
    }

    public function test_validateServersData_with_custom_validation_success(): void
    {
        $data = [
            'my_plex' => [
                'name' => 'my_plex',
                'type' => 'plex',
                'options' => [
                    'LIBRARY_SEGMENT' => 500  // Should pass (>= 300)
                ]
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'LIBRARY_SEGMENT >= 300 should pass validation');
    }

    public function test_validateServersData_with_nested_options(): void
    {
        $data = [
            'my_backend' => [
                'name' => 'my_backend',
                'type' => 'jellyfin',
                'url' => 'http://localhost:8096',
                'token' => 'test_token',
                'options' => [
                    'client' => [
                        'timeout' => 30,
                        'http_version' => 2.0,
                        'verify_host' => true
                    ]
                ]
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Nested options should pass validation');
    }

    public function test_validateServersData_with_type_coercion_boolean(): void
    {
        $data = [
            'my_backend' => [
                'name' => 'my_backend',
                'type' => 'plex',
                'export' => ['enabled' => 1],  // Int instead of bool
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Int 1 should be coerced to boolean true');
    }

    public function test_validateServersData_with_type_coercion_integer(): void
    {
        $data = [
            'my_backend' => [
                'name' => 'my_backend',
                'type' => 'plex',
                'import' => ['lastSync' => '1234567890'],  // String number
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Numeric string should be coerced to integer');
    }

    public function test_validateServersData_with_invalid_boolean_type(): void
    {
        $data = [
            'my_backend' => [
                'name' => 'my_backend',
                'type' => 'plex',
                'export' => ['enabled' => 'not_a_boolean'],
            ]
        ];

        $result = validateServersData($data);

        $this->assertFalse($result['valid'], 'Invalid boolean value should fail validation');
        $this->assertStringContainsString('boolean', $result['errors'][0], 'Error should mention boolean type');
    }

    public function test_validateServersData_with_invalid_integer_type(): void
    {
        $data = [
            'my_backend' => [
                'name' => 'my_backend',
                'type' => 'plex',
                'import' => ['lastSync' => 'not_a_number'],
            ]
        ];

        $result = validateServersData($data);

        $this->assertFalse($result['valid'], 'Invalid integer value should fail validation');
        $this->assertStringContainsString('integer', $result['errors'][0], 'Error should mention integer type');
    }

    public function test_validateServersData_with_non_array_backend_data(): void
    {
        $data = [
            'my_backend' => 'not_an_array'
        ];

        $result = validateServersData($data);

        $this->assertFalse($result['valid'], 'Non-array backend data should fail validation');
        $this->assertArrayHasKey('errors', $result, 'Should return errors array');
        $this->assertStringContainsString(
            'must be an array',
            $result['errors'][0],
            'Error should indicate array is required'
        );
    }

    public function test_validateServersData_with_empty_data(): void
    {
        $data = [];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Empty data should be considered valid');
    }

    public function test_validateServersData_with_multiple_errors(): void
    {
        $data = [
            'Invalid Backend!' => [  // Invalid name
                'name' => 'test',
                'type' => 'kodi',  // Invalid type
                'options' => [
                    'LIBRARY_SEGMENT' => 100,  // Too small
                    'unknown_option' => 'value'  // Unknown field
                ]
            ]
        ];

        $result = validateServersData($data);

        $this->assertFalse($result['valid'], 'Multiple errors should fail validation');
        $this->assertArrayHasKey('errors', $result, 'Should return errors array');
        $this->assertGreaterThan(0, count($result['errors']), 'Should have at least one error');
    }

    public function test_validateServersData_with_webhook_configuration(): void
    {
        $data = [
            'my_plex' => [
                'name' => 'my_plex',
                'type' => 'plex',
                'url' => 'http://localhost:32400',
                'token' => 'token',
                'uuid' => 'test-uuid',
                'user' => '1',
                'export' => ['enabled' => true],
                'import' => ['enabled' => true],
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Valid backend configuration should pass validation');
    }

    public function test_validateServersData_with_complex_nested_structure(): void
    {
        $data = [
            'complex_backend' => [
                'name' => 'complex_backend',
                'type' => 'emby',
                'url' => 'http://emby:8096',
                'token' => 'token',
                'uuid' => 'emby-uuid',
                'user' => '1',
                'export' => [
                    'enabled' => true,
                    'lastSync' => 1234567890
                ],
                'import' => [
                    'enabled' => true,
                    'lastSync' => 1234567890
                ],
                'options' => [
                    'ignore' => 'Library1,Library2,Library3',
                    'LIBRARY_SEGMENT' => 1000,
                    'client' => [
                        'timeout' => 60,
                        'http_version' => 1.1,
                        'verify_host' => false
                    ]
                ]
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Complex nested structure should pass validation');
    }

    public function test_validateServersData_accumulates_all_errors(): void
    {
        $data = [
            'backend1' => [
                'name' => 'backend1',
                'type' => 'invalid_type',
                'unknown_field1' => 'value1'
            ],
            'backend2' => [
                'name' => 'backend2',
                'type' => 'another_invalid',
                'unknown_field2' => 'value2'
            ]
        ];

        $result = validateServersData($data);

        $this->assertFalse($result['valid'], 'Multiple backend errors should fail validation');
        $this->assertArrayHasKey('errors', $result, 'Should return errors array');
        $this->assertGreaterThanOrEqual(2, count($result['errors']), 'Should accumulate errors from multiple backends');
    }

    public function test_validateServersData_with_minimal_valid_configuration(): void
    {
        $data = [
            'minimal' => [
                'name' => 'minimal',
                'type' => 'plex',
                'url' => 'http://localhost:32400',
                'token' => 'token'
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Minimal valid configuration should pass validation');
    }

    public function test_validateServersData_returns_array_structure(): void
    {
        $data = [
            'test_backend' => [
                'name' => 'test_backend',
                'type' => 'plex'
            ]
        ];

        $result = validateServersData($data);

        $this->assertIsArray($result, 'Should return an array');
        $this->assertArrayHasKey('valid', $result, 'Should have valid key');
        $this->assertIsBool($result['valid'], 'Valid key should be boolean');
    }

    public function test_validateServersData_error_structure(): void
    {
        $data = [
            'Invalid!' => [
                'name' => 'test',
                'type' => 'plex'
            ]
        ];

        $result = validateServersData($data);

        $this->assertFalse($result['valid'], 'Invalid data should fail');
        $this->assertArrayHasKey('errors', $result, 'Should have errors key');
        $this->assertIsArray($result['errors'], 'Errors should be an array');
        $this->assertNotEmpty($result['errors'], 'Errors array should not be empty');
        $this->assertIsString($result['errors'][0], 'Each error should be a string');
    }

    public function test_validateServersData_with_nullable_field_null_value(): void
    {
        $data = [
            'test_backend' => [
                'name' => 'test_backend',
                'type' => 'plex',
                'export' => [
                    'enabled' => true,
                    'lastSync' => null  // nullable field with null value
                ]
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Nullable field with null value should pass validation');
    }

    public function test_validateServersData_with_nullable_field_valid_value(): void
    {
        $data = [
            'test_backend' => [
                'name' => 'test_backend',
                'type' => 'plex',
                'export' => [
                    'enabled' => true,
                    'lastSync' => 1234567890  // nullable field with valid value
                ]
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Nullable field with valid value should pass validation');
    }

    public function test_validateServersData_with_nullable_field_invalid_type(): void
    {
        $data = [
            'test_backend' => [
                'name' => 'test_backend',
                'type' => 'plex',
                'export' => [
                    'enabled' => true,
                    'lastSync' => 'invalid_string'  // nullable int field with string value
                ]
            ]
        ];

        $result = validateServersData($data);

        $this->assertFalse($result['valid'], 'Nullable field with invalid type should fail validation');
        $this->assertArrayHasKey('errors', $result, 'Should have errors');
        $this->assertStringContainsString('lastSync', $result['errors'][0], 'Error should mention the field');
        $this->assertStringContainsString('integer', $result['errors'][0], 'Error should mention expected type');
    }

    public function test_validateServersData_with_multiple_nullable_fields(): void
    {
        $data = [
            'test_backend' => [
                'name' => 'test_backend',
                'type' => 'jellyfin',
                'export' => [
                    'enabled' => false,
                    'lastSync' => null
                ],
                'import' => [
                    'enabled' => true,
                    'lastSync' => null
                ]
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Multiple nullable fields with null values should pass validation');
    }

    public function test_validateServersData_nullable_field_mixed_with_non_nullable(): void
    {
        $data = [
            'test_backend' => [
                'name' => 'test_backend',
                'type' => 'plex',
                'url' => 'http://localhost:32400',  // non-nullable
                'token' => 'test_token',  // non-nullable
                'export' => [
                    'enabled' => true,  // non-nullable
                    'lastSync' => null  // nullable
                ],
                'import' => [
                    'enabled' => false,  // non-nullable
                    'lastSync' => 1234567890  // nullable with value
                ]
            ]
        ];

        $result = validateServersData($data);

        $this->assertTrue($result['valid'], 'Mix of nullable and non-nullable fields should work correctly');
    }
}


