<?php

/**
 * @file
 * Contains Drupal\lilbacon_spotify\Utility\LilbaconSpotifyUtility
 *
 * @author Adam Terchin <adam.terchin@gmail.com>
 *
 */

namespace Drupal\lilbacon_spotify\Utility;

use Drupal\lilbacon_spotify\SpotifySession;
use Drupal\lilbacon_spotify\SpotifyWebAPI;
use Drupal\lilbacon_spotify\SpotifyWebAPIException;

class LilbaconSpotifyUtility {

  /**
   * Gets Spotify public user profile
   *
   * @param $userId string
   *
   * @return object|FALSE
   */
  public function getSpotifyPublicProfile($userId) {
    $profile = FALSE;
    $session = $this->createSession();
    $session->requestCredentialsToken();

    $webapi = new SpotifyWebAPI();
    $webapi->setAccessToken($session->getAccessToken());
    try {
      $profile = $webapi->getUser($userId);
    }
    catch (SpotifyWebAPIException $ex) {
      //$response = $ex->getResponse();
      //echo $response->getStatusCode(); // 404
      //echo $response->getReasonPhrase(); // "Not Found"
      watchdog_exception('lilbacon_spotify', $ex, $ex->getMessage());
    }

    return $profile;
  }

  /**
   * Gets Album information from Spotify
   *
   * @param $albumId
   *
   * @return object|FALSE
   */
  public function getSpotifyAlbum($albumId) {
    $album = FALSE;

    $session = $this->createSession();
    $session->requestCredentialsToken();

    $webapi = new SpotifyWebAPI();
    $webapi->setAccessToken($session->getAccessToken());
    $webapi->getAlbum($albumId);

    $response = $webapi->getLastResponse();

    if($response['status'] == 200) {
      $album = $response['body'];
    }

    return $album;
  }

  /**
   * Creates a Spotify session, using parameters from the module's form
   *
   * @return \Drupal\lilbacon_spotify\SpotifySession
   */
  public function createSession() {
    $auth_config = \Drupal::config('lilbacon_spotify.auth');

    $session = new SpotifySession(
      $auth_config->get('client_id'),
      $auth_config->get('client_secret'),
      $auth_config->get('callback_url')
    );

    return $session;
  }
}