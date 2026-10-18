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
6.  API Type: http
7.  API Url: https://api.mobilemessage.com.au/v1/messages
8.  In the **API Parameters** field, add:
    `From=YourSenderID`
    *   *Note: `YourSenderID` can be a mobile number (e.g., `0412345678`) or an Alphanumeric Sender ID (up to 11 characters).*
    *   *Tip: You can provide multiple numbers separated by a pipe (e.g., `From=0411111111|0422222222`) to rotate between them randomly.*
    To verify webhook signatures, add a second line:
    `SigningSecret=your_webhook_signing_secret`
    *   Generate the secret in the Mobile Message portal under **Settings > API > Webhook Signing Secret**.
    *   Optional rotation: add `SigningSecretPrevious=old_secret` (do not pipe-separate — the secret itself may contain `|`).
    *   Alternatively set the `MM_WEBHOOK_SECRET` environment variable. If any secret is configured, unsigned or invalid webhooks are rejected.

## Webhooks

To receive inbound messages, set your **Inbound Message Webhook** in the Mobile Message portal to:
`https://your-domain/civicrm/sms/callback?provider=org.civicrm.sms.mobilemessage`

You may leave **Message status** blank, or point it at the same URL — this extension
detects delivery-status JSON payloads and does not create Inbound SMS activities for them.

When a signing secret is configured, every webhook must include `X-MM-Timestamp` and
`X-MM-Signature`. See https://help.mobilemessage.com.au/api/verifying-webhook-signatures

Inbound ingest is **idempotent**: the provider `message_id` (or a stable hash of
sender/to/message/received_at) is stored on `civicrm_activity.result` and re-delivery
is a no-op. Australian `61…` sender numbers are normalized to `0…` before contact match
to avoid duplicate `@mobile.sms` contacts.

## Development

Helper unit tests (no Civi bootstrap):

```bash
php tests/InboundHelpersTest.php
php tests/WebhookSignatureTest.php
```

Manual regression steps: `tests/MANUAL_TEST_PLAN.md`.

Testing webhooks locally (unsigned; only works when no SigningSecret / MM_WEBHOOK_SECRET is set):

```bash
curl -d '{"sender": "61404040404", "message": "inbound message test", "type": "inbound", "received_at": "2026-07-27 01:00:00"}' -X POST 'http://localhost:18001/civicrm/sms/callback?provider=org.civicrm.sms.mobilemessage'
```

Signed request (secret `abc123`; replace the timestamp with the current unix time):

```bash
TS=$(date +%s)
BODY='{"sender":"61404040404","message":"inbound message test","type":"inbound","received_at":"2026-07-27 01:00:00"}'
SIG=$(php -r 'echo hash_hmac("sha256", $argv[1] . "." . $argv[2], "abc123");' "$TS" "$BODY")
curl -d "$BODY" -H "X-MM-Timestamp: $TS" -H "X-MM-Signature: $SIG" -X POST \
  'http://localhost:18001/civicrm/sms/callback?provider=org.civicrm.sms.mobilemessage'
```

A successful response is plain text: `OK`.
