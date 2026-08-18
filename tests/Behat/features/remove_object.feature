Feature: Removing a key
  DELETE /object/{key} is the one operation that removes a key outright: it
  drops every version at once. (PUT removes a row too, but only the single
  version it appends a correction for — see replace_object.feature.)

  Scenario: Deleting a key removes all of its versions
    Given the key "mykey" has the value "value1" recorded at 1000
    And the key "mykey" has the value "value2" recorded at 2000
    When I delete the key "mykey"
    Then the response status should be 200
    And the response message should be "Key 'mykey' and all its versions were deleted."
    And the key "mykey" should have 0 versions
    When I read the key "mykey"
    Then the response status should be 404

  Scenario: Deleting a key that was never written is a 404
    When I delete the key "does-not-exist"
    Then the response status should be 404
    And the response should carry a message
