<?php

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "georeport_request_resource",
 *   label = @Translation("Georeport request"),
 *   serialization_class = "Drupal\Core\Entity\Entity",
 *   uri_paths = {
 *     "canonical" = "/georeport/v2/requests/{id}.json",
 *     "https://www.drupal.org/link-relations/create" = "/georeport/v2/requests/{id}/post",
 *     "defaults"  = {"id": ""},
 *   }
 * )
 */
class GeoreportRequestResource extends ResourceBase {
  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a Drupal\rest\Plugin\ResourceBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->config = \Drupal::configFactory()
      ->getEditable('markaspot_open311.settings');
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('markaspot_open311'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function routes() {
    $collection = new RouteCollection();

    $definition = $this->getPluginDefinition();
    $canonical_path = isset($definition['uri_paths']['canonical']) ? $definition['uri_paths']['canonical'] : '/' . strtr($this->pluginId, ':', '/') . '/{id}';
    $route_name = strtr($this->pluginId, ':', '.');

    $methods = $this->availableMethods();
    foreach ($methods as $method) {
      $route = $this->getBaseRoute($canonical_path, $method);
      switch ($method) {

        case 'POST':
          foreach ($this->serializerFormats as $format_name) {
            $format_route = clone $route;

            #$format_route->setPath($create_path . '.' . $format_name);
            $format_route->setRequirement('_access_rest_csrf', 'FALSE');

            // Restrict the incoming HTTP Content-type header to the known
            // serialization formats.
            $format_route->addRequirements(array('_content_type_format' => implode('|', $this->serializerFormats)));
            $collection->add("$route_name.$method.$format_name", $format_route);
          }
          break;
        case 'GET':
          // Restrict GET and HEAD requests to the media type specified in the
          // HTTP Accept headers.
          foreach ($this->serializerFormats as $format_name) {

            // Expose one route per available format.
            $format_route = clone $route;
            $format_route->addOptions(array('_format' => $format_name));
            $collection->add("$route_name.$method.$format_name", $format_route);

          }
          break;

        default:
          $collection->add("$route_name.$method", $route);
          break;
      }
    }

    return $collection;
  }

  /**
   *
   */
  protected function getBaseRoute($canonical_path, $method) {
    $lower_method = strtolower($method);

    $route = new Route($canonical_path, array(
      '_controller' => 'Drupal\markaspot_open311\GeoreportRequestHandler::handle',
      // Pass the resource plugin ID along as default property.
      '_plugin' => $this->pluginId,
    ), array(
      '_permission' => "restful $lower_method $this->pluginId",
    ),
      array(),
      '',
      array(),
      // The HTTP method is a requirement for this route.
      array($method)
    );
    return $route;
  }

  /**
   * Responds to GET requests.
   *
   * Returns a list of bundles for specified entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function get($id) {

    // var_dump($this->currentUser->getRoles());
    // $request = \Drupal::request()->getRequestFormat();
    // $queryString = \Drupal::request()->getQueryString();
    $parameters = UrlHelper::filterQueryParameters(\Drupal::request()->query->all());
    // $limit = UrlHelper::parse(\Drupal::request()->getQueryString();
    // $_POST parameters
    // $request->request->get('name');.
    // Filtering the configured content type.
    //  $bundle = $this->config->get('bundle');.
    $query = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('changed', REQUEST_TIME, '<');
    // ->condition('type', $bundle);.
    $query->sort('changed', 'desc');
    $params = explode('.', $id);
    $id = $params[0];

    if ($id != "") {
      $query->condition('uuid', $id);
    }

    $map = new GeoreportProcessor();

    // Checking for service_code and map the code with taxonomy terms:
    if (isset($parameters['service_code'])) {
      // Get the service of the current node:
      $tid = $map->markaspot_open311_serviceMapTax($parameters['service_code']);
      $query->condition('field_category.entity.tid', $tid);
    }

    // Checking for service_code and map the code with taxonomy terms:
    if (isset($parameters['id'])) {
      // Get the service of the current node:
      $query->condition('uuid', $parameters['id']);
    }

    $nids = $query->execute();

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($nids);

    // Extensions.
    $extensions = [];
    if (isset($parameters['extensions'])) {
      $extendend_permission = 'access open311 extension';
      if ($this->currentUser->hasPermission($extendend_permission)) {
        $extensions = array('anonymous', 'role');
      }
      else {
        $extensions = array('anonymous');
      }
    }

    // Building requests array.
    $service_requests = [];

    foreach ($nodes as $node) {
      $status = "closed";
      $service_requests[] = $map->nodeMapRequest($node, $extensions, $id);
    }
    if (!empty($service_requests)) {
      $response = new ResourceResponse($service_requests, 200);
      $response->addCacheableDependency($service_requests);

      return $response;
    }
    else {
      throw new HttpException(404, "No Service requests found");
    }
  }


  /**
   * Responds to POST requests.
   *
   * Returns a list of bundles for specified entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function post($id, $request_data) {
    try {

      $map = new GeoreportProcessor();
      $request_data['service_request_id'] = $this->getRequestId($id);
      $values = $map->requestMapNode($request_data);

      $node = \Drupal::entityTypeManager()->getStorage('node')->create($values);

      // Make sure it's a content entity.
      if ($node instanceof ContentEntityInterface) {
        if ($this->validate($node)) {
          // Add an intitial paragraph on valid post.
          $status_open = array_values($this->config->get('status_open_start'));
          // todo: put this in config.
          $status_note_initial = t('The service request has been created.');

          $paragraph = Paragraph::create([
            'type' => 'status',
            'field_status_note' => array(
              "value"  => $status_note_initial,
              "format" => "full_html",
            ),
            'field_status_term' => array(
              "target_id"  => $status_open[0],
            ),
          ]);
          $paragraph->save();
        }

      }

      $node->field_status_notes = array(
        array(
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ),
      );

      // Save the node and prepare response;.
      $node->save();
      // Get the UUID to put it into the response.
      $uuid = $node->uuid();

      $service_request = [];
      if (isset($node)) {
        $service_request['service_requests']['request']['service_request_id'] = $uuid;
      }

      $this->logger->notice('Created entity %type with ID %uuid.', array(
        '%type' => $node->getEntityTypeId(),
        '%uuid' => $node->uuid(),
      ));

      // 201 Created responses return the newly created entity in the response.
      // $url = $entity->urlInfo('canonical', [
      //  'absolute' => TRUE])->toString(TRUE);
      $response = new ResourceResponse($service_request, 201);
      $response->addCacheableDependency($service_request);

      // Responses after creating an entity are not cacheable, so we add no
      // cacheability metadata here.
      return $response;
    }
    catch (EntityStorageException $e) {
      throw new HttpException(500, 'Internal Server Error', $e);
    }

  }

  /**
   * Verifies that the whole entity does not violate any validation constraints.
   * Those are defined in markaspot_validate module.
   */
  protected function validate($node) {
    $violations = NULL;
    // Remove violations of inaccessible fields as they cannot stem from our
    // changes.
    /*
    if (!\Drupal::service('email.validator')->isValid($request_data['email'])){
    $this->processsServicesError('E-mail not valid', 400);
    }
     */
    $violations = $node->validate();
    // var_dump(count($violations));
    if (count($violations) > 0) {
      $message = '';
      foreach ($violations as $violation) {
        $message .= $violation->getMessage() . '\n';
      }
      throw new BadRequestHttpException($message);
    }
    else {
      return TRUE;
    }
  }


  /**
   * Return the service_request_id
   *
   * @param $id_param
   *
   * @return string if
   */
  public function getRequestId($id_param) {
    $param = explode('.', $id_param);
    $id = $param[0];
    return $id;
  }

}
