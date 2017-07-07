<?php
  
/**
 * @file
 * Contains Drupal\lilbacon_spotify\Utility\LilbaconSpotifyUtility
 *
 * @author Adam Terchin <adam.terchin@gmail.com>
 *
 */

namespace Drupal\lilbacon_spotify\Utility;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class LilbaconSpotifyUtility {

  /**
   * Gets Spotify public user profile
   *
   * @param $userId string
   *
   * @return obj|FALSE
   */
  public function getSpotifyPublicProfile($userId) {
    $profile = FALSE;

    $client = new Client(['base_uri' => \Drupal\lilbacon_spotify\SpotifyRequest::API_URL]);
    try {
      $response = $client->get('/v1/users/' . $userId, [
          'headers' => [
              'Accept' => 'application/json'
          ]
      ]);
      $data = $response->getBody();
      $profile = json_decode($data);
    }
    catch (RequestException $ex) {
      //$response = $ex->getResponse();
      //echo $response->getStatusCode(); // 404
      //echo $response->getReasonPhrase(); // "Not Found"
      watchdog_exception('lilbacon_spotify', $ex, $ex->getMessage());
    }

    return $profile;
  }

}