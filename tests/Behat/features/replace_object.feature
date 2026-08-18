Feature: Editing a stored version
  PUT /object/{key} corrects one version: the version the same URL would read
  is removed and a corrected one is appended, in a single transaction. No row is
  ever updated, and the key's other versions are left alone.

  Scenario: Editing the current value replaces it
    Given the key "mykey" has the value "value1" recorded at 1000
    And the key "mykey" has the value "typo" recorded at 2000
    When I replace the key "mykey" with the value "corrected"
    Then the response status should be 200
    And the response should have the fields "key, value, timestamp"
    And the key "mykey" should have 2 versions
    And the stored value for the key "mykey" should be "corrected"
    When I read the history of the key "mykey"
    Then the response should list the values "value1, corrected"
    And the response should not contain the value "typo"

  Scenario: Editing the version that was current at a moment leaves the newer one alone
    Given the key "mykey" has the value "value1" recorded at 1000
    And the key "mykey" has the value "typo" recorded at 2000
    And the key "mykey" has the value "value3" recorded at 3000
    When I replace the key "mykey" at timestamp 2500 with the value "corrected"
    Then the response status should be 200
    And the key "mykey" should have 3 versions
    When I read the history of the key "mykey"
    Then the response should list the values "value1, value3, corrected"

  Scenario: A correction becomes the key's current value
    # Unavoidable in an append-only store: the newest row wins, so correcting an
    # older version also makes the correction current.
    Given the key "mykey" has the value "typo" recorded at 1000
    And the key "mykey" has the value "value2" recorded at 2000
    When I replace the key "mykey" at timestamp 1500 with the value "corrected"
    And I read the key "mykey"
    Then the response status should be 200
    And the response value should be "corrected"

  Scenario: A version that is not published yet cannot be edited
    Given the clock is at 1000
    And the key "mykey" has the value "queued" recorded at 900 and published at 5000
    When I replace the key "mykey" with the value "sneaky"
    Then the response status should be 404
    And the key "mykey" should have 1 versions
    And the stored value for the key "mykey" should be "queued"

  Scenario: Editing a key that was never written is a 404
    When I replace the key "does-not-exist" with the value "value1"
    Then the response status should be 404
    And the response should carry a message
    And the store should hold 0 records

  Scenario: Editing before the first version is a 404
    Given the key "mykey" has the value "value1" recorded at 1000
    When I replace the key "mykey" at timestamp 500 with the value "corrected"
    Then the response status should be 404
    And the response should carry a message
    And the key "mykey" should have 1 versions

  Scenario: The body key must be the key in the URL
    Given the key "mykey" has the value "value1" recorded at 1000
    When I replace the key "mykey" with the body '{"otherkey":"corrected"}'
    Then the response status should be 422
    And the response should report a validation error for "key"
    And the key "mykey" should have 1 versions
    And the key "otherkey" should have 0 versions

  Scenario: The body must still be exactly one pair
    Given the key "mykey" has the value "value1" recorded at 1000
    When I replace the key "mykey" with the body '{"mykey":"a","other":"b"}'
    Then the response status should be 422
    And the response should report a validation error for "body"
    And the key "mykey" should have 1 versions

  Scenario: A correction keeps the schedule of the version it replaces
    Given the clock is at 5000
    And the key "mykey" has the value "typo" recorded at 1000 and published at 2000
    When I replace the key "mykey" with the value "corrected"
    Then the response status should be 200
    And the response should have the fields "key, value, timestamp, publish_time"
    And the stored value for the key "mykey" should be "corrected"

  Scenario: A correction can be given a schedule of its own
    Given the clock is at 5000
    And the key "mykey" has the value "typo" recorded at 1000 and published at 2000
    When I replace the key "mykey" with the value "corrected" published at 4000
    Then the response status should be 200
    And the response value should be "corrected"

  Scenario: A correction scheduled forward is not readable until its time
    # The version it replaces is gone, so the key has nothing live in between.
    Given the clock is at 5000
    And the key "mykey" has the value "live" recorded at 1000
    When I replace the key "mykey" with the value "corrected" published at 9000
    Then the response status should be 200
    When I read the key "mykey"
    Then the response status should be 404
    When the clock reaches 9001
    And I read the key "mykey"
    Then the response status should be 200
    And the response value should be "corrected"
