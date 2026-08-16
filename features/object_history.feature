Feature: Reading a key's history
  GET /object/{key}/history returns every version ever written for one key,
  oldest first, and never leaks versions of another key.

  Scenario: The full history comes back oldest first
    Given the key "mykey" has the value "value1" recorded at 1000
    And the key "mykey" has the value "value2" recorded at 2000
    And the key "mykey" has the value "value3" recorded at 3000
    And the key "otherkey" has the value "ignored" recorded at 1500
    When I read the history of the key "mykey"
    Then the response status should be 200
    And the response should list 3 records
    And the record at position 0 should be "mykey" with the value "value1"
    And the record at position 1 should be "mykey" with the value "value2"
    And the record at position 2 should be "mykey" with the value "value3"

  Scenario: An unknown key has an empty history rather than a 404
    When I read the history of the key "does-not-exist"
    Then the response status should be 200
    And the response should be an empty array
