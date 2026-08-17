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

  # A version carrying a publish_time is served only once that time arrives,
  # and among those already published, the one written last is on air.

  Scenario: Of two versions already due, the one saved later is on air
    Given the clock is at 9000
    And the key "route.bangkok-chiang-mai.banner" has the value "morning banner" recorded at 1000 and published at 3000
    And the key "route.bangkok-chiang-mai.banner" has the value "afternoon banner" recorded at 1100 and published at 4000
    When I read the key "route.bangkok-chiang-mai.banner"
    Then the response status should be 200
    And the response value should be "afternoon banner"

  Scenario: A correction saved afterwards is not overridden by an earlier schedule
    Given the clock is at 9000
    And the key "route.bangkok-chiang-mai.banner" has the value "afternoon banner" recorded at 1000 and published at 4000
    And the key "route.bangkok-chiang-mai.banner" has the value "corrected banner" recorded at 2000 and published at 3000
    When I read the key "route.bangkok-chiang-mai.banner"
    Then the response status should be 200
    And the response value should be "corrected banner"

  Scenario: A campaign prepared for later is not served yet
    Given the clock is at 4000
    And the key "route.bangkok-chiang-mai.banner" has the value "current banner" recorded at 1000
    And the key "route.bangkok-chiang-mai.banner" has the value "campaign banner" recorded at 2000 and published at 5000
    When I read the key "route.bangkok-chiang-mai.banner"
    Then the response status should be 200
    And the response value should be "current banner"

  Scenario: The campaign goes live once its time has passed, with nothing running in between
    Given the clock is at 4000
    And the key "route.bangkok-chiang-mai.banner" has the value "current banner" recorded at 1000
    And the key "route.bangkok-chiang-mai.banner" has the value "campaign banner" recorded at 2000 and published at 5000
    When the clock reaches 5001
    And I read the key "route.bangkok-chiang-mai.banner"
    Then the response status should be 200
    And the response value should be "campaign banner"

  Scenario: The second the campaign names is still too early
    Given the clock is at 4000
    And the key "route.bangkok-chiang-mai.banner" has the value "current banner" recorded at 1000
    And the key "route.bangkok-chiang-mai.banner" has the value "campaign banner" recorded at 2000 and published at 5000
    When the clock reaches 5000
    And I read the key "route.bangkok-chiang-mai.banner"
    Then the response status should be 200
    And the response value should be "current banner"

  Scenario: A future timestamp cannot be used to read a campaign early
    Given the clock is at 4000
    And the key "route.bangkok-chiang-mai.banner" has the value "current banner" recorded at 1000
    And the key "route.bangkok-chiang-mai.banner" has the value "campaign banner" recorded at 2000 and published at 5000
    When I read the key "route.bangkok-chiang-mai.banner" at timestamp "999999"
    Then the response status should be 200
    And the response value should be "current banner"

  Scenario: A key with nothing published yet is a 404, like one that does not exist
    Given the clock is at 4000
    And the key "route.bangkok-chiang-mai.banner" has the value "campaign banner" recorded at 1000 and published at 5000
    When I read the key "route.bangkok-chiang-mai.banner"
    Then the response status should be 404
    And the response should carry a message
