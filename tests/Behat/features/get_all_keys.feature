Feature: Listing the keys
  GET /object/get_all_records/keys answers the name of every key that has
  something published, alphabetically — what the page's key selector offers.
  It is a sub-path of the listing, so no second name has to be reserved.

  Scenario: Every key is listed once, in order
    Given the key "charlie" has the value "v" recorded at 1000
    And the key "alpha" has the value "v1" recorded at 1000
    And the key "alpha" has the value "v2" recorded at 2000
    When I list all keys
    Then the response status should be 200
    And the response should be exactly:
      """
      ["alpha","charlie"]
      """

  Scenario: An empty store answers an empty list
    When I list all keys
    Then the response status should be 200
    And the response should be an empty array

  Scenario: A key with nothing published yet is not offered
    Given the clock is at 1000
    And the key "live" has the value "v" recorded at 900
    And the key "queued" has the value "v" recorded at 900 and published at 5000
    When I list all keys
    Then the response status should be 200
    And the response should be exactly:
      """
      ["live"]
      """

  Scenario: The paged listing still answers on its own path
    Given the key "mykey" has the value "v" recorded at 1000
    When I list all records
    Then the response status should be 200
    And the response should list the keys "mykey"
