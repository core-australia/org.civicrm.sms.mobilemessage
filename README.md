# Mobile Message SMS Provider

Mobile Message and CiviCRM integration allows delivering single and mass short message service (SMS) messages through its Mobile Message Gateway to mobile phone users.

Installation instructions: https://docs.civicrm.org/user/en/latest/sms-text-messaging/set-up/

## Configuration

To set up Mobile Message in CiviCRM:

1.  Go to **Administer > System Settings > SMS Providers**.
2.  Click **Add SMS Provider**.
3.  Set **Name** to `Mobile Message`.
4.  Set **SMS Provider Name** to `MobileMessage`.
5.  Enter your **Username** and **Password** from your Mobile Message API account.
6.  In the **API Parameters** field, add:
    `From=YourSenderID`
    *   *Note: `YourSenderID` can be a mobile number (e.g., `0412345678`) or an Alphanumeric Sender ID (up to 11 characters).*
    *   *Tip: You can provide multiple numbers separated by a pipe (e.g., `From=0411111111|0422222222`) to rotate between them randomly.*

## Webhooks

To receive inbound messages, set your **Inbound URL** in the Mobile Message portal to:
`https://your-site.org/?page=CiviCRM&q=civicrm%2Fsms%2Fcallback&provider=org.civicrm.sms.mobilemessage`


## Development

Testing webhooks:

curl  -d '{"sender": "61404040404", "message": "inbound message test", "type": "inbound"}' -X POST -v 'http://localhost/?page=CiviCRM&q=civicrm%2Fsms%2Fcallback&provider=org.civicrm.sms.mobilemessage&provider=org.civicrm.sms.mobilemessage' 

