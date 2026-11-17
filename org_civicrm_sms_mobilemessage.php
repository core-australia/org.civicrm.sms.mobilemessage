<?php

/*
 * CIB cache override — installed via cache/installfiles.lock1.
 *
 * Baseline: mobilemessage 1.0.1 (lab.civicrm.org/extensions/mobilemessage).
 * vs vanilla: verify webhook HMAC (X-MM-Timestamp / X-MM-Signature) when a
 * signing secret is configured; route JSON status payloads to callback().
 * See https://help.mobilemessage.com.au/api/verifying-webhook-signatures
 */

class org_civicrm_sms_mobilemessage extends CRM_SMS_Provider
{
  /**
   * Cached raw webhook body + decoded JSON for this request.
   *
   * php://input can only be read once, and both inbound() and callback() need it.
   *
   * @var array|null
   */
  private static $_webhookPayload = null;

  /**
   * provider details
   * @var string
   */
  protected $_providerInfo = [];

  public $_apiURL = 'https://api.mobilemessage.com.au/v1/messages';

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  private static $_singleton = [];

  /**
   * Constructor
   *
   * @return void
   */
  function __construct($provider = [], $skipAuth = true)
  {
    $this->_providerInfo = $provider;

    if ($skipAuth) {
      return true;
    }

    $this->authenticate();
  }

  /**
   * singleton function used to manage this object
   *
   * @return object
   */
  static function &singleton($providerParams = [], $force = false)
  {
    $providerID = $providerParams['provider_id'] ?? null;
    $skipAuth = $providerID ? false : true;
    $cacheKey = (int) $providerID;

    if (!isset(self::$_singleton[$cacheKey]) || $force) {
      $provider = [];
      if ($providerID) {
        $provider = CRM_SMS_BAO_Provider::getProviderInfo($providerID);
      }
      self::$_singleton[$cacheKey] = new org_civicrm_sms_mobilemessage($provider, $skipAuth);
    }
    return self::$_singleton[$cacheKey];
  }

  /**
   * Authenticate to the Mobile Message Server.
   * @return boolean TRUE
   * @access public
   */
  function authenticate()
  {
    return true;
  }

  /**
   * Send an SMS Message via the Mobile Message API Server
   *
   * @param array $recipients
   * @param array $header
   * @param string $message
   * @param int $jobID
   * @param int $userID
   *
   * @return mixed response on success or PEAR_Error object
   * @access public
   */
  function send($recipients, $header, $message, $jobID = null, $userID = null)
  {
    $username = $this->_providerInfo['username'] ?? '';
    $password = $this->_providerInfo['password'] ?? '';

    $from = '';
    if (isset($this->_providerInfo['api_params']['From'])) {
      $from = $this->_providerInfo['api_params']['From'];
      if (preg_match('/\|/', $from)) {
        $froms = explode('|', $from);
        $key = array_rand($froms);
        $from = $froms[$key];
      }
    }

    $messages = [];
    // CiviCRM usually calls send() for each recipient, but $recipients can be an array in some contexts.
    // However, the standard signature is often one-by-one.
    // In CRM_SMS_BAO_Provider::send, it's called in a loop.
    // $header['To'] contains the current recipient.

    $to = $header['To'] ?? '';
    if (empty($to)) {
      return PEAR::raiseError('No recipient specified', 0, PEAR_ERROR_RETURN);
    }

    $data = [
      'enable_unicode' => true,
      'messages' => [
        [
          'to' => $to,
          'message' => $message,
          'sender' => $from,
          'custom_ref' => $jobID ? "job_$jobID" : null,
        ],
      ],
    ];

    $ch = curl_init($this->_apiURL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_POST, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
      return PEAR::raiseError("CURL Error: $err", 0, PEAR_ERROR_RETURN);
    }

    $result = json_decode($response, true);
    if ($httpCode >= 400 || (isset($result['status']) && $result['status'] === 'error')) {
      $errMsg = $result['message'] ?? $response;
      return PEAR::raiseError("Mobile Message API Error: $errMsg", $httpCode, PEAR_ERROR_RETURN);
    }

    // Capture first message_id for CiviCRM
    $messageID = '';
    if (!empty($result['results'][0]['message_id'])) {
      $messageID = $result['results'][0]['message_id'];
    }

    $this->createActivity($messageID, $message, $header, $jobID, $userID);
    return $messageID;
  }

  function callback()
  {
    $data = $this->readWebhookPayload();

    if (!$data) {
      return false;
    }

    $messageID = $data['message_id'] ?? '';
    $status = $data['status'] ?? '';

    // CiviCRM doesn't have a standard built-in way to handle outbound delivery receipts in activity logs easily without custom tables,
    // but we can at least log it or update the activity if we had the ID mapping.
    // For now, return true as it's processed.
    return true;
  }

  function inbound()
  {
    $data = $this->readWebhookPayload();

    if (!$data) {
      return false;
    }

    $fromPhone = $data['sender'] ?? '';
    $body = $data['message'] ?? '';
    // Do not default type to inbound before this check: status payloads have
    // `status` and no `type`, and $_REQUEST is empty for JSON POSTs, so Civi's
    // callback page routes them here.
    $type = $data['type'] ?? '';
    if ($type !== 'inbound' && $type !== 'unsubscribe' && isset($data['status']) && $data['status'] !== '' && $data['status'] !== null) {
      return $this->callback();
    }
    if ($type === '') {
      $type = 'inbound';
    }

    // If Mobile Message identifies this as an unsubscribe event,
    // ensure the body contains a keyword CiviCRM recognizes if it's otherwise empty.
    if ($type === 'unsubscribe' && empty($body)) {
      $body = 'STOP';
    }

    // Use message_id as the primary external identifier for this inbound message.
    // original_message_id is the ID of the message this is replying to.
    $externalID = $data['message_id'] ?? ($data['original_message_id'] ?? null);

    // Pass to=null (vanilla). Core processInbound uses $to to set activity
    // source_contact_id; a non-matching `to` leaves source null instead of
    // falling back to fromContactID, and a loose LIKE match can mis-attribute.
    return parent::processInbound($fromPhone, $body, null, $externalID);
  }

  /**
   * Read the raw webhook body once, verify HMAC if a secret is configured, decode JSON.
   *
   * @return array|false
   */
  protected function readWebhookPayload()
  {
    if (self::$_webhookPayload !== null) {
      return self::$_webhookPayload['data'] ?? false;
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false) {
      $rawBody = '';
    }

    $this->verifyWebhookSignature($rawBody);

    $data = json_decode($rawBody, true);
    self::$_webhookPayload = [
      'raw' => $rawBody,
      'data' => is_array($data) ? $data : false,
    ];
    return self::$_webhookPayload['data'];
  }

  /**
   * Verify X-MM-Timestamp / X-MM-Signature when a signing secret is configured.
   *
   * Signing is optional unless MM_WEBHOOK_REQUIRE_SECRET is set (1/true).
   * HMAC is SHA-256 of "{timestamp}.{raw_body}" with the secret as the key.
   * See https://help.mobilemessage.com.au/api/verifying-webhook-signatures
   *
   * @param string $rawBody
   */
  protected function verifyWebhookSignature($rawBody)
  {
    $headers = $this->webhookSignatureHeaders();
    $secrets = $this->getWebhookSigningSecrets();
    if (empty($secrets)) {
      if ($this->webhookSigningRequired()) {
        $this->rejectWebhook(401, 'Mobile Message webhook signing required but no SigningSecret / MM_WEBHOOK_SECRET is configured');
      }
      if ($headers['timestamp'] !== '' || $headers['signature'] !== '') {
        $this->logWebhook('Mobile Message webhook has signature headers but no SigningSecret / MM_WEBHOOK_SECRET is configured; skipping verification');
      }
      return;
    }

    $timestamp = $headers['timestamp'];
    $signature = $headers['signature'];

    if ($timestamp === '' || $signature === '') {
      $this->rejectWebhook(400, 'Mobile Message webhook missing signature headers');
    }

    if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
      $this->rejectWebhook(400, 'Mobile Message webhook timestamp out of range');
    }

    $matched = false;
    foreach ($secrets as $secret) {
      if ($this->webhookSignatureMatches($secret, $timestamp, $rawBody, $signature)) {
        $matched = true;
        break;
      }
    }
    if (!$matched) {
      $this->rejectWebhook(401, 'Mobile Message webhook signature mismatch');
    }

    // Reject replays of the same signed request within the timestamp window.
    $this->rejectWebhookReplay($timestamp, $signature);
  }

  /**
   * True when unsigned webhooks must be rejected (MM_WEBHOOK_REQUIRE_SECRET=1|true).
   *
   * @return bool
   */
  protected function webhookSigningRequired()
  {
    $flag = getenv('MM_WEBHOOK_REQUIRE_SECRET');
    if (!is_string($flag)) {
      return false;
    }
    $flag = strtolower(trim($flag));
    return $flag === '1' || $flag === 'true' || $flag === 'yes';
  }

  /**
   * Remember accepted (timestamp, signature) pairs for 5 minutes to block replays.
   *
   * @param string $timestamp
   * @param string $signature
   */
  protected function rejectWebhookReplay($timestamp, $signature)
  {
    $key = 'mm_webhook_sig_' . hash('sha256', $timestamp . '.' . strtolower($signature));
    try {
      if (class_exists('Civi') && method_exists('Civi', 'cache')) {
        $cache = Civi::cache('short');
        if ($cache->get($key)) {
          $this->rejectWebhook(409, 'Mobile Message webhook replay rejected');
        }
        $cache->set($key, 1, 300);
      }
    }
    catch (Exception $e) {
      // Cache failures must not fail open for auth — signature already matched.
      $this->logWebhook('Mobile Message webhook replay cache unavailable: ' . $e->getMessage());
    }
  }

  /**
   * @return array{timestamp: string, signature: string}
   */
  protected function webhookSignatureHeaders()
  {
    $timestamp = trim((string) ($_SERVER['HTTP_X_MM_TIMESTAMP'] ?? ''));
    $signature = trim((string) ($_SERVER['HTTP_X_MM_SIGNATURE'] ?? ''));
    if (($timestamp === '' || $signature === '') && function_exists('getallheaders')) {
      $headers = getallheaders();
      $headers = array_change_key_case(is_array($headers) ? $headers : [], CASE_LOWER);
      if ($timestamp === '') {
        $timestamp = trim((string) ($headers['x-mm-timestamp'] ?? ''));
      }
      if ($signature === '') {
        $signature = trim((string) ($headers['x-mm-signature'] ?? ''));
      }
    }
    return [
      'timestamp' => $timestamp,
      'signature' => $signature,
    ];
  }

  /**
   * Constant-time HMAC compare against the raw body. Signature is lowercase hex.
   *
   * @param string $secret
   * @param string $timestamp
   * @param string $rawBody
   * @param string $signature
   * @return bool
   */
  protected function webhookSignatureMatches($secret, $timestamp, $rawBody, $signature)
  {
    $signature = strtolower($signature);
    if (strlen($signature) !== 64 || !ctype_xdigit($signature)) {
      return false;
    }
    $given = hex2bin($signature);
    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, (string) $secret, true);
    return is_string($given) && is_string($expected) && hash_equals($expected, $given);
  }

  /**
   * Signing secrets for this webhook's SMS provider and/or MM_WEBHOOK_SECRET.
   *
   * Only the provider instance handling the request is used (not every
   * org.civicrm.sms.mobilemessage row). Provider lookup failures fail closed.
   *
   * API Parameters (one per line; do not pipe-separate — secrets may contain |):
   *   SigningSecret=...
   *   SigningSecretPrevious=...   (optional, for rotation)
   *
   * @return string[]
   */
  protected function getWebhookSigningSecrets()
  {
    $secrets = [];

    if (!empty($this->_providerInfo['api_params'])) {
      $secrets = array_merge($secrets, $this->secretsFromApiParams($this->_providerInfo['api_params']));
    }
    elseif (class_exists('CRM_SMS_BAO_Provider')) {
      // Fallback when _providerInfo is empty: load by name, but only if a
      // single mobilemessage provider exists (avoid accepting another row's secret).
      try {
        $filter = ['name' => 'org.civicrm.sms.mobilemessage'];
        $providers = CRM_SMS_BAO_Provider::getProviders(null, $filter, true);
        if (!is_array($providers)) {
          $providers = [];
        }
        if (count($providers) === 1) {
          $provider = reset($providers);
          $secrets = array_merge($secrets, $this->secretsFromApiParams($provider['api_params'] ?? ''));
        }
        elseif (count($providers) > 1) {
          $this->logWebhook('Mobile Message webhook has multiple SMS providers; configure SigningSecret on the provider handling this URL or set MM_WEBHOOK_SECRET');
        }
      }
      catch (Exception $e) {
        // Fail closed: a transient DB error must not disable verification.
        $this->rejectWebhook(503, 'Mobile Message could not load SMS providers for webhook secrets: ' . $e->getMessage());
      }
    }

    $env = getenv('MM_WEBHOOK_SECRET');
    if (is_string($env)) {
      $env = trim($env);
      if ($env !== '') {
        $secrets[] = $env;
      }
    }

    return array_values(array_unique($secrets));
  }

  /**
   * @param array|string $apiParams
   * @return string[]
   */
  protected function secretsFromApiParams($apiParams)
  {
    if (is_string($apiParams)) {
      $parsed = [];
      foreach (explode("\n", str_replace("\r", '', $apiParams)) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=') === false) {
          continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $parsed[trim($key)] = trim($val);
      }
      $apiParams = $parsed;
    }

    if (!is_array($apiParams)) {
      return [];
    }

    $secrets = [];
    foreach (['SigningSecret', 'SigningSecretPrevious'] as $key) {
      if (!isset($apiParams[$key]) || !is_string($apiParams[$key])) {
        continue;
      }
      $secret = trim($apiParams[$key]);
      if ($secret !== '') {
        $secrets[] = $secret;
      }
    }
    return $secrets;
  }

  /**
   * @param string $message
   */
  protected function logWebhook($message)
  {
    if (class_exists('Civi')) {
      Civi::log()->warning($message);
    }
  }

  /**
   * @param int $status
   * @param string $message
   */
  protected function rejectWebhook($status, $message)
  {
    $status = (int) $status;
    $this->logWebhook($message . (headers_sent($file, $line) ? " (headers already sent at {$file}:{$line})" : ''));

    // WP/Civi often start the session before the SMS callback runs, which can
    // leave the SAPI status stuck at 200 if we only call http_response_code().
    // Prefer WP status_header, clear buffers, skip civiExit (session write /
    // shutdown can re-assert 200), then exit hard.
    while (ob_get_level() > 0) {
      ob_end_clean();
    }
    if (function_exists('status_header')) {
      status_header($status);
    }
    if (!headers_sent()) {
      http_response_code($status);
      header('Content-Type: text/plain; charset=UTF-8', true);
    }
    echo $message;
    exit;
  }
}
