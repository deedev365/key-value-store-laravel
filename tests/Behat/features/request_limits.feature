Feature: Size and nesting limits on a write
  Every accepted body becomes a row that is never reclaimed — history is
  append-only — so these limits are the only thing bounding how fast the store
  can grow. Reads are unaffected by them.

  Scenario: A body at the size limit is accepted
    When I write a body of 65536 bytes
    Then the response status should be 201

  Scenario: A body one byte over the limit is refused
    When I write a body of 65537 bytes
    Then the response status should be 413
    And the response message should be "Request body must not exceed 65536 bytes."
    And the store should hold 0 records

  Scenario: A multi-megabyte body is refused
    When I write a body of 2097152 bytes
    Then the response status should be 413
    And the store should hold 0 records

  Scenario: An understated Content-Length does not buy a bigger body
    Content-Length is client-supplied, so the real body length is what decides.

    When I write a body of 2097152 bytes declaring a Content-Length of "10"
    Then the response status should be 413
    And the store should hold 0 records

  Scenario: An overstated Content-Length is refused on the header alone
    Checking the header first is free; it turns a lie away before the body is
    measured.

    When I write the body '{"k":"v"}' declaring a Content-Length of "10485760"
    Then the response status should be 413

  Scenario: The size limit is configurable
    Given the body size limit is 32 bytes
    When I write the body '{"k":"v"}'
    Then the response status should be 201
    When I write a body of 64 bytes
    Then the response status should be 413

  Scenario: Reads are not affected by the body limit
    Given the value "v" is stored under the key "k"
    When I read the key "k"
    Then the response status should be 200
    When I read the history of the key "k"
    Then the response status should be 200
    When I list all records
    Then the response status should be 200
    When I delete the key "k"
    Then the response status should be 204

  Scenario: An oversized body still carries the security headers
    When I write a body of 200000 bytes
    Then the response status should be 413
    And the response header "X-Content-Type-Options" should be "nosniff"
    And the response header "Content-Type" should be "application/json"

  Scenario: A value at the depth limit is accepted
    When I write a value nested 20 levels deep
    Then the response status should be 201

  Scenario: A value one level past the depth limit is refused
    When I write a value nested 21 levels deep
    Then the response status should be 422
    And the response should report a validation error for "value"
    And the store should hold 0 records

  Scenario: Deep nesting of objects is limited too
    When I write an object nested 21 levels deep
    Then the response status should be 422
    And the response should report a validation error for "value"

  Scenario: The depth limit is configurable
    Given the value depth limit is 3 levels
    When I write a value nested 3 levels deep
    Then the response status should be 201
    When I write a value nested 4 levels deep
    Then the response status should be 422

  Scenario: A depth violation is reported distinctly from malformed JSON
    A client cannot tell what to fix if "too deep" and "not JSON" arrive as
    the same error.

    When I write a value nested 21 levels deep
    Then the response status should be 422
    And the response should report a validation error for "value"
    When I write the body '{"k": '
    Then the response status should be 422
    And the response should report a validation error for "body"

  Scenario: A flat value is unaffected by the depth limit
    When I write the body '{"k":{"a":1,"b":[1,2,3],"c":{"d":"e"}}}'
    Then the response status should be 201
