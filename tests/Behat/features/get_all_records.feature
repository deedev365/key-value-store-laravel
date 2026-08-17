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

  # A version carrying a publish_time is listed only once that time arrives.

  Scenario: A campaign prepared for later is withheld
    Given the clock is at 4000
    And the key "route.bangkok-chiang-mai.banner" has the value "campaign" recorded at 1000 and published at 5000
    When I list all records
    Then the response status should be 200
    And the response should be an empty array

  Scenario: The notice on air keeps showing while its replacement waits
    Given the clock is at 4000
    And the key "operator.srt.booking_notice" has the value "current notice" recorded at 1000
    And the key "operator.srt.booking_notice" has the value "campaign notice" recorded at 2000 and published at 5000
    When I list all records
    Then the response status should be 200
    And the response should list 1 records
    And the response should contain the record "operator.srt.booking_notice" with the value "current notice"
    And the response should not contain the value "campaign notice"

  Scenario: The replacement takes over when its time comes, with nothing running in between
    Given the clock is at 4000
    And the key "operator.srt.booking_notice" has the value "current notice" recorded at 1000
    And the key "operator.srt.booking_notice" has the value "campaign notice" recorded at 2000 and published at 5000
    When the clock reaches 5001
    And I list all records
    Then the response status should be 200
    And the response should list 1 records
    And the response should contain the record "operator.srt.booking_notice" with the value "campaign notice"

  Scenario: A version with no publish time is live from the moment it was written
    Given the clock is at 0
    And the key "country.th.payment_message" has the value "always on" recorded at 1000
    When I list all records
    Then the response status should be 200
    And the response should list 1 records
    And the response should contain the record "country.th.payment_message" with the value "always on"
