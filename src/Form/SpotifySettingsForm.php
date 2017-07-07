<?php

/**
 * @file
 * Contains \Drupal\lilbacon_spotify\Form\SpotifySettingsForm.
 */
 
namespace Drupal\lilbacon_spotify\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\ConfigFormBase;

/**
 * Implements the SpotifySettingsForm form controller.
 *
 * @see \Drupal\Core\Form\ConfigFormBase
 */
class SpotifySettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'spotify_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'lilbacon_spotify.auth',
      'lilbacon_spotify.user',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $auth_config = $this->config('lilbacon_spotify.auth');
    $user_config = $this->config('lilbacon_spotify.user');

    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $auth_config->get('client_id'),
    ];
    $form['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret'),
      '#default_value' => $auth_config->get('client_secret'),
    ];
    $form['callback_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Callback URL'),
      '#default_value' => $auth_config->get('callback_url'),
    ];
    $form['user_ids'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional User Ids (one per line)'),
      '#rows' => 30,
      '#default_value' => $user_config->get('ids'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('lilbacon_spotify.auth')
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('client_secret', $form_state->getValue('client_secret'))
      ->set('callback_url', $form_state->getValue('callback_url'))
      ->save();
    $this->config('lilbacon_spotify.user')
      ->set('ids', $form_state->getValue('user_ids'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
