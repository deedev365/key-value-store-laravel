Feature: Reading a value
  GET /object/{key} answers with the current value. With a timestamp it
  answers with the value that was current at that moment, which is what makes
  the store version-controlled rather than a plain map.

  Scenario: The latest version wins
    Given the value "value1" is stored under the key "mykey"
    When I store the value "value2" under the key "mykey"
    And I read the key "mykey"
    Then the response status should be 200
    And the response should be the record "mykey" with the value "value2"

  Scenario: An unknown key is a 404 with a message
    When I read the key "does-not-exist"
    Then the response status should be 404
    And the response should carry a message

  Scenario: A timestamp selects the version that was current then
    Given the key "mykey" has the value "value1" recorded at 1440568800
    And the key "mykey" has the value "value2" recorded at 1440569100
    When I read the key "mykey" at timestamp "1440568980"
    Then the response status should be 200
    And the response should be the record "mykey" with the value "value1"

  Scenario: The worked example from the brief
    Reproduces the brief verbatim: write value1 at 6pm, write value2 at
    6.05pm, then read at 6.03pm and get value1 back.

    Given the value "value1" is stored under the key "mykey"
    And every version of the key "mykey" was recorded at 1440568800
    And the value "value2" is stored under the key "mykey"
    And the last version of the key "mykey" was recorded at 1440569100
    When I read the key "mykey"
    Then the response should be the record "mykey" with the value "value2"
    When I read the key "mykey" at timestamp "1440568980"
    Then the response should be the record "mykey" with the value "value1"

  Scenario: A timestamp landing exactly on a write returns that write
    Given the key "mykey" has the value "value1" recorded at 1000
    And the key "mykey" has the value "value2" recorded at 2000
    When I read the key "mykey" at timestamp "2000"
    Then the response status should be 200
    And the response value should be "value2"

  Scenario: A timestamp before the first write is a 404
    Given the key "mykey" has the value "value1" recorded at 2000
    When I read the key "mykey" at timestamp "1000"
    Then the response status should be 404

  Scenario: A non-integer timestamp is refused
    Given the key "mykey" has the value "value1" recorded at 1000
    When I read the key "mykey" at timestamp "not-a-number"
    Then the response status should be 422
