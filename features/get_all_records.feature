Feature: Listing every key
  GET /object/get_all_records answers with the current value of every key —
  one entry per key, never one per version.

  Scenario: Only the latest version of each key is listed
    Given the key "a" has the value "a1" recorded at 1000
    And the key "a" has the value "a2" recorded at 2000
    And the key "b" has the value "b1" recorded at 1500
    When I list all records
    Then the response status should be 200
    And the response should list 2 records
    And the response should contain the record "a" with the value "a2"
    And the response should contain the record "b" with the value "b1"
    And the response should not contain the value "a1"

  Scenario: An empty store lists an empty array
    When I list all records
    Then the response status should be 200
    And the response should be an empty array
