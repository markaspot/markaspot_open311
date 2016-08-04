<?php

namespace Drupal\markaspot_open311\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure georeport settings for this site.
 */
class MarkaspotOpen311SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_open311_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_open311.settings');

    $form['markaspot_open311'] = array(
      '#type' => 'fieldset',
      '#title' => t('Open311 Settings and Service Discovery'),
      '#collapsible' => TRUE,
      '#description' => t('Configure the Open311 Server Settings (status, publishing settings and Georeport v2 Service Discovery). See http://wiki.open311.org/Service_Discovery. This service discovery specification is meant to be read-only and can be provided either dynamically or using a manually edited static file.'),
      '#group' => 'settings',
    );

    $form['markaspot_open311']['bundle'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_request',
      '#description' => t('Match the service request to a Drupal content-type (machine_name) of your choice')
    );

    $form['markaspot_open311']['tax_category'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_category',
      '#description' => t('Match the request category to a Drupal vocabulary (machine_name) of your choice')
    );

    $form['markaspot_open311']['tax_status'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_status',
      '#description' => t('Match the request status to a Drupal vocabulary (machine_name) of your choice')
    );

    $form['markaspot_open311']['contact'] = array(
      '#type' => 'textarea',
      '#default_value' => $config->get('contact'),
      '#title' => t('Open311 Contact Details'),
    );
    $form['markaspot_open311']['type'] = array(
      '#type' => 'textarea',
      '#default_value' => $config->get('type'),
      '#title' => t('Open311 Server type. Either "production" or "test" defines whether the information is live and will be acted upon'),
    );
    $form['markaspot_open311']['key_service'] = array(
      '#type' => 'textarea',
      '#default_value' => $config->get('key_service'),
      '#title' => t('Human readable information on how to get an API key'),
    );
    $form['markaspot_open311']['changeset'] = array(
      '#type' => 'textfield',
      '#default_value' => $config->get('changeset'),
      '#title' => t('Sortable field that specifies the last time this document was updated'),
    );
    $form['markaspot_open311']['node_options_status'] = array(
      '#type' => 'radios',
      '#default_value' => $config->get('node_options_status'),
      '#options' => array(0 => t('Unpublished'), 1 => t('Published')),
      '#title' => t('Choose the publish status of incoming reports'),
    );

    $form['markaspot_open311']['status_open_start'] = array(
      '#type' => 'select',
      '#multiple' => FALSE,
      '#options' => self::get_taxonomy_term_options(
        $this->config('markaspot_open311.settings')->get('tax_status')),
      '#default_value' => $config->get('status_open_start'),
      '#title' => t('Choose the status that gets applied when creating reports by third party apps'),
    );

    $form['markaspot_open311']['status_open'] = array(
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::get_taxonomy_term_options(
        $this->config('markaspot_open311.settings')->get('tax_status')),
      '#default_value' => $config->get('status_open'),
      '#title' => t('Please choose the status for open reports'),
    );

    $form['markaspot_open311']['status_closed'] = array(
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::get_taxonomy_term_options(
        $this->config('markaspot_open311.settings')->get('tax_status')),
      '#default_value' => $config->get('status_closed'),
      '#title' => t('Please choose the status for closed reports'),
    );

    $form['markaspot_open311']['status_open'] = array(
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::get_taxonomy_term_options(
        $this->config('markaspot_open311.settings')->get('tax_status')),
      '#default_value' => $config->get('status_open'),
      '#title' => t('Please choose the status for open reports'),
    );

    $form['markaspot_open311']['nid-limit'] = array(
      '#type' => 'textfield',
      '#title' => t('Limit settings'),
      '#default_value' => $config->get('nid-limit'),
      '#description' => t('Set the maximum number of requests by nids.'),
    );


    return parent::buildForm($form, $form_state);
  }

  /**
   * Helper function to get taxonomy term options for select widget.
   *
   * @parameter string $machine_name
   *   Taxonomy machine name.
   *
   * @return array
   *   Select options for form
   */
  function get_taxonomy_term_options($machine_name) {
    $options = array();

    // $vid = taxonomy_vocabulary_machine_name_load($machine_name)->vid;
    $vid = $machine_name;
    $options_source = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree($vid);


    foreach ($options_source as $item) {
      $key = $item->tid;
      $value = $item->name;
      $options[$key] = $value;
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $this->config('markaspot_open311.settings')
      ->set('status_open', $values['status_open'])
      ->set('status_closed', $values['status_closed'])
      ->set('status_open_start', $values['status_open_start'])
      ->set('node_options_status', $values['node_options_status'])
      ->set('changeset', $values['changeset'])
      ->set('key_service', $values['key_service'])
      ->set('type', $values['type'])
      ->set('contact', $values['contact'])
      ->set('bundle', $values['bundle'])
      ->set('tax_category', $values['tax_category'])
      ->set('tax_status', $values['tax_status'])
      ->set('nid-limit', $values['nid-limit'])

      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_open311.settings',
    ];
  }
}

