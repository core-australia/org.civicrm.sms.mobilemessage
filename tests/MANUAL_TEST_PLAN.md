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
