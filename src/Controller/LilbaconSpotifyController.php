<?php

/**
 * @file
 * Contains Drupal\lilbacon_spotify\Controller\LilbaconSpotifyController
 *
 * @author Adam Terchin <adam.terchin@gmail.com>
 *
 */

namespace Drupal\lilbacon_spotify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionManager;
use Drupal\user\PrivateTempStoreFactory;

use Drupal\Component\Utility\Html;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Routing\TrustedRedirectResponse;

use Drupal\Core\Url;
use Drupal\node\Entity\Node;

use Drupal\lilbacon_spotify\SpotifySession;
use Drupal\lilbacon_spotify\SpotifyWebAPI;
use Drupal\lilbacon_spotify\SpotifyWebAPIException;

class LilbaconSpotifyController extends ControllerBase {

  /**
   * Drupal\user\PrivateTempStoreFactory definition.
   *
   * @var Drupal\user\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Drupal\Core\Session\SessionManager definition.
   *
   * @var Drupal\Core\Session\SessionManager
   */
  protected $sessionManager;

  /**
   * Drupal\Core\Session\AccountInterface definition.
   *
   * @var Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Spotify API requirements
   */
  protected $client_id;
  protected $client_secret;
  protected $callback_url;

  /**
   * Store session data
   */
  private $store;

  /**
   * {@inheritdoc}
   */
  public function __construct(
      PrivateTempStoreFactory $temp_store_factory,
      SessionManager $session_manager,
      AccountInterface $current_user) {

    global $base_url;

    $this->sessionManager = $session_manager;
    $this->currentUser = $current_user;
    $this->tempStoreFactory = $temp_store_factory;
    $this->store = $this->tempStoreFactory->get('lilbacon_spotify');

    $config = $this->config('lilbacon_spotify.auth');
    $this->client_id = $config->get('client_id');
    $this->client_secret = $config->get('client_secret');
    $this->callback_url = $base_url . $config->get('callback_url');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user.private_tempstore'),
      $container->get('session_manager'),
      $container->get('current_user')
    );
  }

  // Starts session for anonymous users (so the session is not
  // initialized at every request for them).
  // drupal.stackexchange.com/questions/197576/storing-data-session-for-anonymous-user/200168
  public function setFingerprint() {
    if ($this->currentUser->isAnonymous() && !isset($_SESSION['session_started'])) {
      // start() did not work. regenerate() new session id did though.
      $this->sessionManager->start();
      $this->sessionManager->regenerate();
      $_SESSION['session_started'] = TRUE;
    }
  }

  public function api() {
    $this->setFingerprint();
    $this->register();

    $tokens = $this->store->get('tokens');
    $api = new SpotifyWebAPI();
    $api->setAccessToken($tokens['access_token']);

    return $api;
  }

  public function register() {
    $session = new SpotifySession($this->client_id, $this->client_secret, $this->callback_url);
    $tokens = $this->store->get('tokens');    
    $authorized = FALSE;

    if ($tokens !== NULL && isset($tokens['access_token'])) {
      if ($tokens['expiration'] < time()) {
        if ($tokens['refresh_token'] !== '') {
          if ($session->refreshAccessToken($tokens['refresh_token'])) {
            $tokens['access_token'] = $session->getAccessToken();
            $tokens['expiration'] = $session->getTokenExpiration();
            $this->store->set('tokens', $tokens);
            $authorized = TRUE;
          }
        }
      }
      else {
        //under an hour, still authorized
        $authorized = TRUE;
      }
    }

    if ($authorized === FALSE) {
      // check if there is an incoming destination, i.e.
      // url is /spotify/user/[userID] or something we will need
      // to redirect to after authorization URL sends to callback
      $route = \Drupal::routeMatch()->getRouteName();
      if ($route !== 'lilbacon_spotify.overview' || $route !== 'lilbacon_spotify.unregister') {
        $this->store->set('destination', \Drupal::request()->getRequestUri());
      }
      $params = [
        'scope' => [
            'playlist-read-private',
            'user-read-private',
            'user-top-read',
            'playlist-modify-private',
            'playlist-modify-public',
            'playlist-read-private'
        ]
      ];
      $url = $session->getAuthorizeUrl($params);
      if ($url) {
        $response = new TrustedRedirectResponse($url);
        $response->send();
      }
    }
  }

  public function unregister() {
    $this->store->set('tokens', NULL);
    $response = new RedirectResponse('http://www.spotify.com/logout');
    $response->send();
  }

  public function callback() {
    $session = new SpotifySession($this->client_id, $this->client_secret, $this->callback_url);

    $code = '';
    $request = Request::createFromGlobals();
    if ($request->headers->has('code')) {
      $code = Html::escape($request->headers->get('code'));
    }
    elseif ($request->query->has('code')) {
      $code = Html::escape($request->query->get('code'));
    }
    if (!empty($code)) {
      $session->requestAccessToken($code);
      $tokens = [
          'access_token' => $session->getAccessToken(),
          'expiration' => $session->getTokenExpiration(),
          'refresh_token' => $session->getRefreshToken()
      ];
      $this->store->set('tokens', $tokens);

      // check for presence of destination parameter in storage
      $destination = $this->store->get('destination');
      $destination = $destination !== NULL ? $destination : '/spotify';
      return new RedirectResponse($destination);
    }

    return new Response('You were not redirected from the authorization URL. Visit <a href="/spotify">/spotify</a>.');
  }

  public function overview() {
    $api = $this->api();
    $spotify_user_ids = $this->getSpotifyUserIds();

    // get user ids from static array and add...
    $ids = $this->config('lilbacon_spotify.user')->get('ids', []);
    $ids_array = explode("\n", $ids);
    $ids_array = array_map('trim', $ids_array);

    foreach ($ids_array as $id) {
      if (!in_array($id, $spotify_user_ids, TRUE)) {
        if ($profile = $this->apiGetProfile($api, $id)) {
          $this->createSpotifyUser($id, $profile);
          $spotify_user_ids[] = $id;
        }
        else {
          // get rid of the foul dim witted little git!
          //$ids = str_replace("$id\r\n", '', $ids);
        }
      }
    }

    // filter out MY profile
    $my_profile = $api->me();
    $my_profile->me = TRUE;
    $my_id = $my_profile->id;
    $profiles[] = [
        'profile' => $my_profile,
    ];    
    $other_user_ids = array_filter($spotify_user_ids,
      function($v) use($my_id) {
        return $v !== $my_id;
      }
    );
    // randomize display profiles
    shuffle($other_user_ids);
    foreach ($other_user_ids as $userId) {
      if ($profile = $this->apiGetProfile($api, $userId)) {
        $profiles[] = [
          'profile' => $profile
        ]; 
      }
    }

    // update vars as needed
    foreach ($profiles as $i => $profile) {
      if (is_null($profile['profile']->display_name)) {
        $profiles[$i]['profile']->display_name = $profile['profile']->id;
      }
    }
    return [
      '#theme' => 'lilbacon_spotify_overview',
      '#profiles' => $profiles,
    ];
  }

  /**
   * Returns the user detail page.
   *
   * @param str $user_id
   *   Spotify User ID
   *
   * @return render array
   */
  public function usersPage($user_id) {
    $api = $this->api();
    $my_profile = $api->me();
    if ($profile = $this->apiGetProfile($api, $user_id)) {
      // making array to keep the profile template vars the same
      // as for overview page.
      $profiles[] = [
        'profile' => $profile
      ]; 
    }

    // update vars as needed
    if (is_null($profiles[0]['profile']->display_name)) {
      $profiles[0]['profile']->display_name = $profiles[0]['profile']->id;
    }
    if ($profiles[0]['profile']->id === $my_profile->id) {
      $profiles[0]['profile']->me = TRUE;
    }

    // Spotify's API currently only attaches playlist description data
    // if you query the playlist by itself.  So we have to get the
    // playlist IDs we want first then get those playlists.  Spotify WTF.
    $ids = [];
    $temp = $api->getUserPlaylists($user_id);
    foreach ($temp->items as $item) {
      if (strpos($item->name, 'LBB') === 0 && $item->owner->id === $user_id) {

        $ids[] = $item->id;
      }
    }

    //global $base_url;
    $playlists = [];
    foreach ($ids as $playlist_id) {
      $playlist = $api->getUserPlaylist($user_id, $playlist_id);
      $playlist->permalink = Url::fromRoute('lilbacon_spotify.users.id',
        ['user_id' => $profiles[0]['profile']->id],
        ['query' => ['playlist' => $playlist_id], 'absolute' => TRUE]
      );
      $playlists[] = $playlist;
    
    }
    // sort by name ASC
    usort($playlists, function($a, $b) {
      return strcmp($a->name, $b->name);
    });

    return [
      '#theme' => 'lilbacon_spotify_user',
      '#profiles' => $profiles,
      '#playlists' => $playlists,
    ];
  }

  public function apiGetProfile($api, $userId) {
    try {
      $profile = $api->getUser($userId);
      if (is_object($profile)) {
        return $profile;
      }
    }
    catch (SpotifyWebAPIException $ex) {
      if ($ex->getMessage() === 'NOT FOUND') {
        $message = t('%userid does not exist in the Spotify database or is not public.', ['%userid' => $id]);
        watchdog_exception('lilbacon_spotify', $ex, $message);
        drupal_set_message($message, 'error');
      }
    }

    return FALSE;
  }

  /**
   * Creates new content of type 'spotify_user'
   * @param $userId string, Spotify username
   * @param $profile object Spotify user profile
   *
   * @return int, node id of new user
   */
  public function createSpotifyUser($userId, $profile = NULL) {
    $node = Node::create([
      'type' => 'spotify_user',
      'title' => $userId,
      'uid' => '1',
    ]);
    $this->updateSpotifyUser($node, $profile);
    $node->save();

    return $node->id();
  }

  /**
   * Updates 'spotify_user' content type
   *
   * @param $node obj, either newly created or existing
   *
   */
  public function updateSpotifyUser(&$node, $profile = NULL) {
    $userId = $node->title->value;
    
    $api = $this->api();
    if (is_null($profile)) {
      $profile = $api->getUser($userId);
    }
    if (!is_null($profile->display_name)) {
      $node->field_spotify_display_name->setValue($profile->display_name);
    }
    $playlists = $api->getUserPlaylists($userId);
    if (!empty($playlists->items)) {
      foreach ($playlists->items as $i => $item) {
        $node->field_spotify_playlist_id->set($i, $item->id);
      }
    }
  }  

  /**
   * Get title (spotify user id) of all 'spotify_user' nodes
   *
   * @return $user_ids array, nodes
   */
  public function getSpotifyUserIds() {
    $ids = [];
    try {
      $db = \Drupal::database();
      $query = $db->select('node', 'n');
      $query->fields('n', ['nid']);
      $query->fields('nfd', ['title']);
      $query->join('node_field_data', 'nfd', 'nfd.vid = n.vid');
      $query->condition('nfd.status', 1);
      $query->condition('nfd.type', 'spotify_user', '=');
      $query->isNotNull('nfd.title');
      $ids = $query->execute()->fetchAllKeyed();
    }
    catch (\Drupal\Core\Database\DatabaseExceptionWrapper $ex) {
      watchdog_exception('lilbacon_spotify', $ex, 'Database error in getSpotifyUserIds(). Message: ' . $ex->getMessage());
      echo 'Database error in getSpotifyUserIds(). Please contact the webmaster.';
      exit;
    }

    return $ids;
  }

  /**
   * Gets node of type 'spotify_user'
   * @param $userId string, (title of node)
   *
   * @return obj
   */
  public function loadSpotifyUser($userId) {
    $node = FALSE;
    try {
      $db = \Drupal::database();
      $query = $db->select('node', 'n');
      $query->fields('n', ['nid']);
      $query->join('node_field_data', 'nfd', 'nfd.vid = n.vid');
      $query->condition('nfd.status', 1);
      $query->condition('nfd.type', 'spotify_user', '=');
      $query->condition('nfd.title', $userId, '=');
      if ($query->countQuery()->execute()->fetchField() > 0) {
        $nid = $query->execute()->fetchField();
        $node = Node::load($nid);
      }
    }
    catch (\Drupal\Core\Database\DatabaseExceptionWrapper $ex) {
      watchdog_exception('lilbacon_spotify', $ex, 'Database error in loadSpotifyUser(). Message: ' . $ex->getMessage());
      echo 'Database error in loadSpotifyUser(). Please contact the webmaster.';
      exit;
    }

    return $node;
  }

}
