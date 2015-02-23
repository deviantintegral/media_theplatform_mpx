<?php

class MpxApiException extends Exception {
  public $response;

  public function __construct($response, $message) {
    $this->response = $response;
    parent::__construct($message);
  }
}

class MpxApi {

  /**
   * Make an authenticated mpx thePlatform API HTTP request.
   *
   * @param MpxAccount $account
   *   The mpx account making the request.
   * @param $url
   *   The URL of the API request.
   * @param array $params
   *   (optional) An array of query parameters to add to the request.
   * @param array $options
   *   (optional) An array of additional options to pass through to
   *   drupal_http_request().
   *
   * @return mixed
   *   The data from the request if successful.
   *
   * @throws MpxApiException
   * @throws Exception
   */
  public static function authenticatedRequest(MpxAccount $account, $url, array $params = array(), array $options = array()) {
    try {
      $duration = isset($options['timeout']) ? $options['timeout'] : NULL;
      $params['token'] = $account->acquireToken($duration);
      return static::request($url, $params, $options);
    }
    catch (MpxApiException $e) {
      if (!empty($e->response->data['description']) && $e->response->data['description'] == 'Invalid security token.') {
        $params['token']->delete();
      }
      throw $e;
    }
  }

  /**
   * Make an mpx thePlatform API HTTP request.
   *
   * @param $url
   *   The URL of the API request.
   * @param array $params
   *   (optional) An array of query parameters to add to the request.
   * @param array $options
   *   (optional) An array of additional options to pass through to
   *   drupal_http_request().
   *
   * @return mixed
   *   The data from the request if successful.
   *
   * @throws MpxApiException
   * @throws Exception
   */
  public static function request($url, array $params = array(), array $options = array()) {
    // Allow for altering the URL before making the request.
    drupal_alter('media_theplatform_mpx_api_request', $url, $params, $options);

    if (!empty($params)) {
      $url .= (strpos($url, '?') !== FALSE ? '&' : '?') . drupal_http_build_query($params);
    }

    if (isset($options['method']) && $options['method'] === 'POST' && isset($options['data']) && is_array($options['data'])) {
      $options['data'] = http_build_query($options['data']);
    }

    $response = drupal_http_request($url, $options);
    $response->url = $url;
    $response->params = $params;
    if (!empty($response->error)) {
      throw new MpxApiException($response, "Error $response->code on request to $url: $response->error");
    }
    elseif (empty($response->data)) {
      throw new MpxApiException($response, "Empty response from request to $url.");
    }

    $response->url = $url;
    $response->params = $params;
    if (isset($params['form']) && in_array($params['form'], array('json', 'cjson'))) {
      return static::processJsonResponse($response);
    }

    return $response->data;
  }

  /**
   * Process the data from an API response which contains JSON.
   *
   * @param object $response
   *   The response object from drupal_http_request().
   *
   * @return array
   *   The JSON-decoded array of data on success.
   *
   * @throws MpxApiException
   */
  public static function processJsonResponse($response) {
    $data = json_decode($response->data, TRUE);
    if ($data === NULL && json_last_error() !== JSON_ERROR_NONE) {
      if (function_exists('json_last_error_msg')) {
        throw new MpxApiException($response, "Unable to decode JSON response from request to {$response->url}: " . json_last_error_msg());
      }
      else {
        throw new MpxApiException($response, "Unable to decode JSON response from request to {$response->url}");
      }
    }

    $response->data = $data;
    if (!empty($data['responseCode']) && !empty($data['isException'])) {
      throw new MpxApiException($response, "Error {$data['responseCode']} on request to {$response->url}: {$data['description']}");
    }
    else {
      return $data;
    }
  }

}