<?php

namespace Civi\Mobilemessage;

/**
 * Pure helper that interprets a Mobile Message send response.
 *
 * Kept free of Civi/cURL so unit tests can run with plain php.
 *
 * The API can answer HTTP 200 while rejecting the message: per-message outcomes
 * live in results[].status (success|blocked|error). A send only counts as
 * accepted when results[0].status is `success` AND a message_id is present.
 */
class SendResult {

  /**
   * @param int $httpCode
   *   HTTP status from the API call.
   * @param string|bool|null $body
   *   Raw response body (cURL returns FALSE on failure).
   *
   * @return array
   *   ['ok' => TRUE, 'message_id' => string] when accepted, otherwise
   *   ['ok' => FALSE, 'error' => string] with a volunteer-readable reason.
   */
  public static function interpret(int $httpCode, $body): array {
    $result = is_string($body) ? json_decode($body, TRUE) : NULL;

    if ($httpCode >= 400) {
      $detail = (is_array($result) && !empty($result['message']) && is_string($result['message']))
        ? $result['message']
        : substr(trim((string) $body), 0, 200);
      return self::fail("Mobile Message API Error: $detail");
    }
    if (!is_array($result)) {
      return self::fail('Mobile Message returned an unreadable response. The message was not sent.');
    }
    if (($result['status'] ?? NULL) === 'error') {
      $detail = (!empty($result['message']) && is_string($result['message'])) ? $result['message'] : 'unknown error';
      return self::fail("Mobile Message API Error: $detail");
    }

    $first = $result['results'][0] ?? NULL;
    if (!is_array($first)) {
      return self::fail('Mobile Message returned no result for this message. It was not sent.');
    }

    $status = $first['status'] ?? NULL;
    if ($status === 'blocked') {
      return self::fail('Message not sent: the recipient has unsubscribed from SMS.');
    }
    if ($status === 'error') {
      $detail = (!empty($first['error']) && is_string($first['error'])) ? $first['error'] : '';
      return self::fail($detail !== ''
        ? "Message not sent: $detail"
        : 'Message not sent: Mobile Message rejected it (for example an invalid number or message too long).');
    }
    if ($status !== 'success') {
      return self::fail('Mobile Message returned an unexpected status for this message. It was not sent.');
    }

    $messageID = $first['message_id'] ?? '';
    if (!is_string($messageID) || $messageID === '') {
      return self::fail('Mobile Message did not confirm the message (no message ID). It may not have been sent.');
    }
    return ['ok' => TRUE, 'message_id' => $messageID];
  }

  private static function fail(string $error): array {
    return ['ok' => FALSE, 'error' => $error];
  }

}
