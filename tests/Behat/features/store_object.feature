Feature: Writing a value
  POST /object accepts a single-property JSON object whose property name is
  the storage key. Every accepted write inserts a new version rather than
  replacing the previous one.

  Scenario: A write returns the created record
    When I store the value "value1" under the key "mykey"
    Then the response status should be 201
    And the response should be the record "mykey" with the value "value1"
    And the response should have the fields "key, value, timestamp"
    And the store should hold 1 records

  Scenario: A JSON object is accepted as a value
    When I store the JSON value '{"nested":{"a":1,"b":[true,false,null]}}' under the key "mykey"
    Then the response status should be 201
    And the response JSON value should be '{"nested":{"a":1,"b":[true,false,null]}}'

  Scenario Outline: Falsy values survive the round trip
    Values that are falsy in PHP must stay distinguishable from each other and
    from a missing key. null in particular reaches the column unencoded by the
    JSON cast, so it exercises the value column's nullability.

    When I store the JSON value <value> under the key "mykey"
    Then the response status should be 201
    And the response JSON value should be <value>
    When I read the key "mykey"
    Then the response status should be 200
    And the response JSON value should be <value>

    Examples:
      | value   |
      | 'null'  |
      | 'false' |
      | '0'     |
      | '""'    |
      | '[]'    |

  Scenario: String values are stored verbatim
    Laravel's global TrimStrings and ConvertEmptyStringsToNull middleware are
    skipped for the API, so surrounding whitespace must survive.

    When I store the value "  spaced  " under the key "mykey"
    Then the response status should be 201
    And the response value should be "  spaced  "
    When I read the key "mykey"
    Then the response value should be "  spaced  "

  Scenario: A null value stays distinct from a key that was never written
    When I store the JSON value 'null' under the key "nullkey"
    Then the response status should be 201
    When I read the key "nullkey"
    Then the response status should be 200
    And the response should be the record "nullkey" with the JSON value 'null'
    When I read the key "neverwritten"
    Then the response status should be 404

  Scenario: A numeric-looking key round-trips as a string
    json_decode turns a numeric object property into a PHP integer array key;
    the key must still come back as the string it was written as.

    When I store the value "value1" under the key "123"
    Then the response status should be 201
    And the response should be the record "123" with the value "value1"
    When I read the key "123"
    Then the response status should be 200
    And the response should be the record "123" with the value "value1"

  Scenario: A body with more than one property is refused
    When I write the body '{"a":1,"b":2}'
    Then the response status should be 422
    And the response should report a validation error for "body"

  Scenario: An empty JSON object is refused
    Distinct from the empty body in injection_safety.feature: this one parses
    cleanly and fails on the "exactly one property" rule instead.

    When I write the body '{}'
    Then the response status should be 422
    And the response should report a validation error for "body"

  Scenario: A JSON array body is refused
    When I write the body '[1,2,3]'
    Then the response status should be 422
    And the response should report a validation error for "body"

  Scenario: An empty key is refused
    When I write the body '{"":"value1"}'
    Then the response status should be 422
    And the response should report a validation error for "key"

  Scenario: A key outside the allowed character set is refused
    When I write the body '{"bad key!":"value1"}'
    Then the response status should be 422
    And the response should report a validation error for "key"

  Scenario: A key claimed by a literal route is refused
    '/object/get_all_records' belongs to the listing route, which is matched
    before the {key} wildcard. Without this guard the write succeeded and the
    record could never be read back through its own URL.

    When I store the value "value1" under the key "get_all_records"
    Then the response status should be 422
    And the response should report a validation error for "key"
    And the store should hold 0 records

  Scenario: The reserved-key message names the key
    When I store the value "value1" under the key "get_all_records"
    Then the response message should be "Key 'get_all_records' is reserved by the API."

  Scenario: A key that merely resembles the reserved one is accepted
    When I store the value "value1" under the key "get_all_records_2"
    Then the response status should be 201
    When I read the key "get_all_records_2"
    Then the response status should be 200
    And the response should be the record "get_all_records_2" with the value "value1"

  Scenario: The listing route still answers on the reserved path
    Given the value "value1" is stored under the key "mykey"
    When I list all records
    Then the response status should be 200
    And the response should list the keys "mykey"

  Scenario: A second write adds a version rather than overwriting
    Given the value "value1" is stored under the key "mykey"
    When I store the value "value2" under the key "mykey"
    Then the response status should be 201
    And the key "mykey" should have 2 versions
