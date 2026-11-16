# Manual regression: duplicate inbound SMS

## Context

Inbound SMS previously created two "Inbound SMS" activities when the Mobile Message
webhook was configured. Root causes addressed in this fork:

1. **Status webhooks mis-routed as inbound** — core `CRM_SMS_Page_Callback` only
   checks `$_REQUEST['status']`, but Mobile Message puts `status` in the JSON body.
   Delivery receipts posted to the same callback URL were processed by `inbound()`
   and created extra activities / `@mobile.sms` contacts.
2. **No idempotency** — webhook retries (up to 10) re-created activities; `activity.result`
   was set but never consulted.
3. **AU phone mismatch** — inbound `sender` is often `61…` while contacts store `04…`,
   so one path matched by phone and another created `<digits>@mobile.sms`.

There is **no** polling job in this extension; inbound is webhook-only. Leave the
**Message status** webhook blank, or point it at the same callback (now safe).

## Automated

```bash
php tests/InboundHelpersTest.php
```

## Manual (local or staging site with Mobile Message)

1. Ensure a contact exists with mobile `0412345678` (national AU form).
2. In Mobile Message → Settings → API, set **Inbound Message Webhook** to:
   `https://<site>/civicrm/sms/callback?provider=org.civicrm.sms.mobilemessage`
3. Optionally set **Message status** webhook to the same URL (should no longer create activities).
4. From phone `0412345678` / `61412345678`, reply to the site's dedicated number with a unique body, e.g. `dedupe-test-001`.
5. In CiviCRM search Activities:
   - Exactly **one** activity of type **Inbound SMS** with that body.
   - Target contact is the existing phone-matched contact (not a new `61412345678@mobile.sms` / `0412345678@mobile.sms`).
6. Re-POST the same webhook payload (simulate retry):

```bash
curl -sS -X POST 'https://<site>/civicrm/sms/callback?provider=org.civicrm.sms.mobilemessage' \
  -H 'Content-Type: application/json' \
  -d '{
    "to": "61400000010",
    "sender": "61412345678",
    "message": "dedupe-test-001",
    "received_at": "2026-07-27 01:00:00",
    "type": "inbound",
    "original_message_id": "outbound-abc"
  }'
```

Expect response body `OK` and still exactly **one** Inbound SMS activity.

7. POST a status payload to the same URL:

```bash
curl -sS -X POST 'https://<site>/civicrm/sms/callback?provider=org.civicrm.sms.mobilemessage' \
  -H 'Content-Type: application/json' \
  -d '{
    "to": "61412345678",
    "sender": "MyBrand",
    "message": "outbound text",
    "status": "delivered",
    "message_id": "outbound-abc",
    "received_at": "2026-07-27 01:00:01"
  }'
```

Expect `OK` and **no** new Inbound SMS activity.

# Manual regression: outbound send result handling

## Context

Mobile Message answers HTTP 200 for a rejected message and reports the outcome per
message in `results[].status` (`success`, `blocked`, `error`). `send()` now treats a
send as accepted only when `results[0].status` is `success` **and** a `message_id` is
present; anything else throws `CRM_Core_Exception` and creates **no** "SMS delivery"
activity. Throwing (not returning PEAR) is required so Mass SMS and the SMS form skip
marking the recipient delivered — those paths ignore PEAR returns.

## Automated

```bash
php tests/SendResultTest.php
```

## Manual (staging site with a real Mobile Message account — you will send real SMS)

Use test handsets you own. Do not use real constituent numbers.

| #   | Recipient                                                     | Expected                                                                                                                                  |
| --- | ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | A valid mobile that has not opted out                         | Send succeeds, handset receives the text, exactly **one** "SMS delivery" activity with `result` = the Mobile Message `message_id`.        |
| 2   | A handset that has replied **STOP** (unsubscribed)            | Send fails with "Message not sent: the recipient has unsubscribed from SMS." No text delivered, **no** new "SMS delivery" activity.       |
| 3   | An invalid number (e.g. `0412 345` or a non-existent country) | Send fails with "Message not sent: <API error text>" (or the generic rejected message). No text delivered, **no** new activity created.   |

Notes:

- `ignore_unsubscribes` is deliberately not set, so case 2 relies on Mobile Message's
  default filtering.
- Mass SMS: failed recipients must **not** appear under Delivered; the reason is in the
  Civi debug log (`Failed to send SMS message…`), not on the mailing report UI.
- Single SMS form: expect an on-screen / returned error with the same message text.
