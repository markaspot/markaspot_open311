<?php
/**
 * @file
 * Several methods for mapping drupal entities and georeport specification.
 */

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\taxonomy\Entity\Term;


class GeoreportProcessor {

  protected $config;

  function  __construct (){
    $this->config = \Drupal::configFactory()->getEditable('markaspot_open311.settings');
  }


  /**
   * Process errors with http status codes.
   *
   * @param string $message
   *   The error message
   *
   * @param int $code
   *   The http status/error code
   */
  public function process_services_error($message, $code) {
    throw new \Exception($message, $code);
  }

  /**
   * Get Taxononmy Term Fields
   * 
   * @param int $tid
   * @param string $field_name
   * @return mixed
   */
  public function get_term($tid, $field_name) {
    // var_dump(Term::load(4)->get('name')->value);
    // http://drupalapi.de/api/drupal/drupal%21core%21modules%21taxonomy%21taxonomy.module/function/taxonomy_term_load/drupal-8
    if (isset($tid) && Term::load($tid) != '') {
      return Term::load($tid)->get($field_name)->value;
    }
  }

  /**
   * @param $node
   * @param $extended
   * @return mixed
   */
  public function node_map_request($node, $extended) {
    $request['sevicerequest_id'] = $node->uuid->value;
    $request['title'] = $node->title->value;
    $request['description'] = $node->body->value;

    $request['lat'] = floatval($node->field_geolocation->lat);
    $request['long'] = floatval($node->field_geolocation->lng);

    $request['status'] = $this->tax_map_status($node->field_status->target_id);
    $request['service_name'] = $this->get_term($node->field_category->target_id, 'name');

    // Media Url:
    if (isset($node->field_image->fid)) {
      $image_uri = file_create_url($node->field_image->entity->getFileUri());
      $request['media_url'] = $image_uri;
    }

    $service_code = $this->get_term($node->field_category->target_id, 'field_service_code');
    $request['service_code'] = isset($service_code) ? $service_code : Null;

    if (in_array('anonymous', $extended)) {
      if(\Drupal::moduleHandler()->moduleExists('service_request')){
        $request['extended_attributes']['markaspot'] = $extended;
        $request['extended_attributes']['markaspot']['category_hex'] = Term::load($node->field_category->target_id)->field_category_hex->color;
        $request['extended_attributes']['markaspot']['category_icon'] = Term::load($node->field_category->target_id)->field_category_icon->value;
        $request['extended_attributes']['markaspot']['status_hex'] = Term::load($node->field_category->target_id)->field_category_hex->color;
        $request['extended_attributes']['markaspot']['status_icon'] = Term::load($node->field_category->target_id)->field_category_icon->value;
      }
    }

    if ($extended == array('anonymous', 'role')) {
      $request['extended_attributes']['author'] = $node->author;
    }
    return $request;
  }


  /**
   * Prepare Node properties 
   * 
   * @param array $request_data
   *    Georeport Request data via form urlencoded
   * @return array
   *    values to be saved via entity api
   */
  public function request_map_node($request_data) {

    $values['type']                     = 'service_request';
    $values['title']                    = $request_data['service_code'];
    $values['body']                     = $request_data['description'];
    $values['field_email']              = $request_data['email'];
    $values['field_geolocation']['lat'] = $request_data['lat'];
    $values['field_geolocation']['lng'] = $request_data['long'];
    $values['field_category']['target_id'] = $this->service_map_tax($request_data['service_code']);

    // File Handling:
    if (isset($request_data['media_url']) && strstr($request_data['media_url'], "http")) {
      $managed = TRUE;
      $file = system_retrieve_file($request_data['media_url'], 'public://', $managed, FILE_EXISTS_REPLACE);

      $values['field_image'] = array(
        'target_id' => $file->id(),
      );

    }

    return $values;
  }

  /**
   * Returns renderable array of taxonomy terms from Categories vocabulary in
   * hierarchical structure ready to be rendered as html list.
   *
   * @param int $parent
   *   The ID of the parent taxonomy term.
   *
   * @param int $max_depth
   *   The max depth up to which to look up children.
   *
   * @param string $route_name
   *   The name of the route to be used for link generation.
   *   Taxonomy term(ID) will be provided as route parameter.
   *
   * @return array
   */
  function get_taxonomy_tree($vocabulary = "tags", $parent = 0, $max_depth = NULL) {
    // Load terms
    $tree = \Drupal::entityManager()->getStorage('taxonomy_term')->loadTree($vocabulary, $parent, $max_depth);

    $entity_type_id = 'taxonomy_term';
    $bundle = $vocabulary;
    foreach (\Drupal::entityManager()->getFieldDefinitions($entity_type_id, $bundle) as $field_name => $field_definition) {
      if (!empty($field_definition->getTargetBundle())) {
        $bundleFields[$entity_type_id][$field_name]['type'] = $field_definition->getType();
        $bundleFields[$entity_type_id][$field_name]['label'] = $field_definition->getLabel();
      }
    }

    // var_dump($bundleFields);



    // Make sure there are terms to work with.
    if (empty($tree)) {
      return [];
    }

    foreach ($tree AS $term) {
      // var_dump($term);
      $services[] = $this->tax_map_service($term->tid);
    }

    return $services;
  }

  /**
   * Mapping taxonomies to services.
   *
   * @param object $taxonomy_term
   *   The taxonomy term.
   *
   * @return object
   *   $service: The service object
   */
  function tax_map_service($tid) {

    // Load all field for this taxonomy term:
    $service_category = \Drupal::entityManager()->getStorage('taxonomy_term')->load($tid);

    $service['service_code'] = $service_category->field_service_code->value;
    $service['service_name'] = $service_category->name;
    $service['metadata'] = "false";
    $service['type'] = 'realtime';
    $service['description'] = $service_category->description->value;
    if (isset($service_category->field_keywords)){
      $service['keywords'] = $service_category->field_keywords->value;
    } else {
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
  public function service_map_tax($service_code) {

    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(array('field_service_code' => $service_code));
      $term = reset($terms);
    if ($term != FALSE) {
      $tid = $term->tid->value;
      return $tid;
    } else {
      $this->process_services_error('Servicecode not found', 404);
    }
  }

  /**
   * Mapping taxonomy to status.
   *
   * geoReport v2 has only open and closed status
   */
  function tax_map_status($taxonomy_id) {
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