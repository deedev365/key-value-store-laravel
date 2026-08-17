Feature: Reading a key's history
  GET /object/{key}/history returns every published version of one key, oldest
  first, and never leaks versions of another key. A version still waiting for
  its publish time is absent — the log is public, so listing a queued campaign
  here would announce it early.

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

  Scenario: A version still waiting for its publish time is not in the history
    Given the clock is at 4000
    And the key "route.bangkok-chiang-mai.banner" has the value "current banner" recorded at 1000
    And the key "route.bangkok-chiang-mai.banner" has the value "campaign banner" recorded at 2000 and published at 5000
    When I read the history of the key "route.bangkok-chiang-mai.banner"
    Then the response status should be 200
    And the response should list 1 records
    And the record at position 0 should be "route.bangkok-chiang-mai.banner" with the value "current banner"

  Scenario: It joins the history once its time has passed
    Given the clock is at 5001
    And the key "route.bangkok-chiang-mai.banner" has the value "current banner" recorded at 1000
    And the key "route.bangkok-chiang-mai.banner" has the value "campaign banner" recorded at 2000 and published at 5000
    When I read the history of the key "route.bangkok-chiang-mai.banner"
    Then the response status should be 200
    And the response should list 2 records
    And the record at position 1 should be "route.bangkok-chiang-mai.banner" with the value "campaign banner"
