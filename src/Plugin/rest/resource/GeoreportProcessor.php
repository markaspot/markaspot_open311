<?php

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\taxonomy\Entity\Term;

/**
 * Class GeoreportProcessor parsing.
 *
 * @package Drupal\markaspot_open311\Plugin\rest\resource
 */
class GeoreportProcessor {

  /**
   * Load Open311 config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * GeoreportProcessor constructor.
   */
  public function __construct() {
    $this->config = \Drupal::configFactory()
      ->getEditable('markaspot_open311.settings');
  }

  /**
   * Process errors with http status codes.
   *
   * @param string $message
   *   The error message.
   * @param int $code
   *   The http status/error code.
   *
   * @throws \Exception
   *   Throwing an exception which is reformatted by event subscriber.
   */
  public function processsServicesError($message, $code) {
    throw new \Exception($message, $code);
  }

  /**
   * Get Taxononmy Term Fields.
   *
   * @param int $tid
   *    The Term id.
   * @param string $field_name
   *    The field name.
   *
   * @return mixed
   *    returns the term.
   */
  public function getTerm($tid, $field_name) {
    // var_dump(Term::load(4)->get('name')->value);
    // http://drupalapi.de/api/drupal/drupal%21core%21modules%21taxonomy%21taxonomy.module/function/taxonomy_term_load/drupal-8
    if (isset($tid) && Term::load($tid) != '') {
      return Term::load($tid)->get($field_name)->value;
    }
  }

  /**
   * Map the node object as georeport request.
   *
   * @param object $node
   *    The node object.
   * @param string $extended
   *    The extended parameter allows rendering additional fields.
   * @param boolean $uuid
   *    Using node-uuid or node-nid
   *
   * @return array
   *    Return the $request array.
   */
  public function nodeMapRequest($node, $extended, $uuid) {
    if ($uuid !== FALSE) {
      $id = $node->uuid->value;
    }
    else {
      $id = $node->nid->value;
    }
    $request['sevicerequest_id'] = $id;

    $request['title'] = $node->title->value;
    $request['description'] = $node->body->value;

    $request['lat'] = floatval($node->field_geolocation->lat);
    $request['long'] = floatval($node->field_geolocation->lng);

    $request['status'] = $this->taxMapStatus($node->field_status->target_id);
    $request['service_name'] = $this->getTerm($node->field_category->target_id, 'name');

    $request['requested_datetime'] = date('c', $node->created->value);
    $request['updated_datetime'] = date('c', $node->changed->value);

    // Media Url:
    if (isset($node->field_image->fid)) {
      $image_uri = file_create_url($node->field_image->entity->getFileUri());
      $request['media_url'] = $image_uri;
    }

    $service_code = $this->getTerm($node->field_category->target_id, 'field_service_code');
    $request['service_code'] = isset($service_code) ? $service_code : NULL;

    if (in_array('anonymous', $extended)) {
      if (\Drupal::moduleHandler()->moduleExists('service_request')) {
        $request['extended_attributes']['markaspot'] = $extended;
        $request['extended_attributes']['markaspot']['nid'] = $node->nid->value;
        $request['extended_attributes']['markaspot']['category_hex'] = Term::load($node->field_category->target_id)->field_category_hex->color;
        $request['extended_attributes']['markaspot']['category_icon'] = Term::load($node->field_category->target_id)->field_category_icon->value;
        $request['extended_attributes']['markaspot']['status_hex'] = Term::load($node->field_status->target_id)->field_status_hex->color;
        $request['extended_attributes']['markaspot']['status_icon'] = Term::load($node->field_status->target_id)->field_status_icon->value;
      }
    }

    if ($extended == array('anonymous', 'role')) {
      $request['extended_attributes']['author'] = $node->author;
      $request['extended_attributes']['author'] = $node->field_e_mail->value;
    }
    return $request;
  }

  public function validate($request_data) {

    if (!\Drupal::service('email.validator')->isValid($request_data['email'])){
      $this->processsServicesError('E-mail not valid', 400);
    }

    // todo: More validation like jurisdoction, bbox here.

    return $request_data;

  }

  /**
   * Prepare Node properties.
   *
   * @param array $request_data
   *    Georeport Request data via form urlencoded.
   *
   * @return array
   *    values to be saved via entity api.
   */
  public function requestMapNode($request_data) {

    // validate some form properties.
    $request_data = $this->validate($request_data);

    $values['type'] = 'service_request';
    $values['title'] = $request_data['service_code'];
    $values['body'] = $request_data['description'];
    $values['field_e_mail'] = $request_data['email'];
    $values['field_geolocation']['lat'] = $request_data['lat'];
    $values['field_geolocation']['lng'] = $request_data['long'];
    $values['field_address'] = $request_data['address_string'];
    // Get Category by service_code.
    $values['field_category']['target_id'] = $this->serviceMapTax($request_data['service_code']);
    // Status when inserting.
    $status_open = array_values($this->config->get('status_open_start'));
    $values['field_status']['target_id'] = $status_open[0];

    // File Handling:
    if (isset($request_data['media_url']) && strstr($request_data['media_url'], "http")) {
      $managed = TRUE;
      $file = system_retrieve_file($request_data['media_url'], 'public://', $managed, FILE_EXISTS_RENAME);

      $field_keys['image'] = 'field_request_image';

      if ($file !== FALSE) {
        $values[$field_keys['image']] = array(
          'target_id' => $file->id(),
          'alt' => 'Open311 File',
        );
      }

    }

    return $values;
  }

  /**
   * Returns renderable array of taxonomy terms from Categories vocabulary.
   *
   * @param string $vocabulary
   *   The taxonomy vocabulary.
   * @param int $parent
   *   The ID of the parent taxonomy term.
   * @param int $max_depth
   *   The max depth up to which to look up children.
   *
   * @return array $services
   *   Return the drupal taxonomy term as a georeport service.
   */
  public function getTaxonomyTree($vocabulary = "tags", $parent = 0, $max_depth = NULL) {
    // Load terms.

    $tree = \Drupal::service('entity_type.manager')
      ->getStorage("taxonomy_term")
      ->loadTree($vocabulary, $parent, $max_depth, $load_entities = FALSE);

    // Make sure there are terms to work with.
    if (empty($tree)) {
      return [];
    }

    foreach ($tree as $term) {
      // var_dump($term);
      $services[] = $this->taxMapService($term->tid);
    }

    return $services;
  }

  /**
   * Mapping taxonomies to services.
   *
   * @param object $tid
   *   The taxonomy term id.
   *
   * @return object
   *   $service: The service object
   */
  public function taxMapService($tid) {

    // Load all field for this taxonomy term:
    $service_category = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->load($tid);

    $service['service_code'] = $service_category->field_service_code->value;
    $service['service_name'] = $service_category->name;
    $service['metadata'] = "false";
    $service['type'] = 'realtime';
    $service['description'] = $service_category->description->value;
    if (isset($service_category->field_keywords)) {
      $service['keywords'] = $service_category->field_keywords->value;
    }
    else {
      $service['keywords'] = "";
    }
    foreach ($service_category as $key => $value) {
      $service['extended_attributes'][$key] = $value;
    }
    return $service;
  }

  /**
   * Mapping requested service_code to drupal taxonomy.
   *
   * @param string $service_code
   *   Open311 Service code (can be Code0001)
   *
   * @return int
   *   The TaxonomyId
   */
  public function serviceMapTax($service_code) {

    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(array('field_service_code' => $service_code));
    $term = reset($terms);
    if ($term != FALSE) {
      $tid = $term->tid->value;
      return $tid;
    }
    else {
      $this->processsServicesError('Servicecode not found', 404);
    }
    return FALSE;
  }

  /**
   * Mapping requested status to drupal taxonomy.
   *
   * @param string $status_sub
   *   Custom Service status (can be open, closed, or foreign translated term name).
   *
   * @return int
   *   The tid
   */
  public function statusMapTax($status_sub) {
    //
    // todo: Maap this for update method.
    //
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(array('field_status_name' => $status_sub));
    $term = reset($terms);
    if ($term != FALSE) {
      $tid = $term->tid->value;
      return $tid;
    }
    else {
      $this->processsServicesError('Status not found', 404);
      return FALSE;
    }
  }

  /**
   * Mapping taxonomy to status. GeoReport v2 has only open and closed status.
   *
   * @param int $taxonomy_id
   *   The Drupal Taxonomy ID.
   *
   * @return string $status
   *   Return Open or Closed Status according to specification.
   */
  public function taxMapStatus($taxonomy_id) {
    // Mapping Status to Open311 Status (open/closed)
    $status_open = array_values($this->config->get('status_open'));
    if (in_array($taxonomy_id, $status_open)) {
      $status = 'open';
    }
    else {
      $status = 'closed';
    }

    return $status;
  }

}
