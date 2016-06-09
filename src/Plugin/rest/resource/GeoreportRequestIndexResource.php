<?php

/**
 * @file
 * Contains \Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestRestResouce.
 */

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Drupal\Component\Utility\UrlHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "georeport_request_index_resource",
 *   label = @Translation("Georeport requests index"),
 *   serialization_class = "Drupal\Core\Entity\Entity",
 *   uri_paths = {
 *     "canonical" = "/georeport/v2/requests",
 *     "https://www.drupal.org/link-relations/create" = "/georeport/v2/requests",
 *     "defaults"  = {"_format": "json"},
 *   }
 * )
 */
class GeoreportRequestIndexResource extends ResourceBase {
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
    $this->config = \Drupal::configFactory()->getEditable('markaspot_open311.settings');
    $this->currentUser = $current_user;
  }



  /**
   * {@inheritdoc}
   */
  public function routes() {
    $collection = new RouteCollection();

    $definition = $this->getPluginDefinition();
    $canonical_path = isset($definition['uri_paths']['canonical']) ? $definition['uri_paths']['canonical'] : '/' . strtr($this->pluginId, ':', '/');
    $create_path = isset($definition['uri_paths']['https://www.drupal.org/link-relations/create']) ? $definition['uri_paths']['https://www.drupal.org/link-relations/create'] : '/' . strtr($this->pluginId, ':', '/');
    $route_name = strtr($this->pluginId, ':', '.');

    $methods = $this->availableMethods();
    foreach ($methods as $method) {
      $route = $this->getBaseRoute($canonical_path, $method);
      switch ($method) {
        case 'POST':
          $georeport_formats = array('json', 'xml');
          foreach ($georeport_formats as $format) {
            $format_route = clone $route;

            $format_route->setPath($create_path  . '.' . $format);
            $format_route->setRequirement('_access_rest_csrf', 'FALSE');

            // Restrict the incoming HTTP Content-type header to the known
            // serialization formats.

            $format_route->addRequirements(array('_content_type_format' => implode('|', $this->serializerFormats)));
            $collection->add("$route_name.$method.$format", $format_route);
          }
          break;
        case 'GET':
          // Restrict GET and HEAD requests to the media type specified in the
          // HTTP Accept headers.
          foreach ($this->serializerFormats as $format) {
            $georeport_formats = array('json', 'xml');
            foreach ($georeport_formats as $format) {

              // Expose one route per available format.
              $format_route = clone $route;
              // create path with format.name
              $format_route->setPath($format_route->getPath() . '.' . $format);
              $collection->add("$route_name.$method.$format", $format_route);
            }

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
   * Responds to GET requests.
   *
   * Returns a list of bundles for specified entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function get() {

    /*
     * todo: Check if permission check is needed

    $permission = 'access GET georeport resource';
    if(!$this->currentUser->hasPermission($permission)) {
      throw new AccessDeniedHttpException("Unauthorized can't proceed with create_request.");
    }
    */
    $parameters = UrlHelper::filterQueryParameters(\Drupal::request()->query->all());

    // Filtering the configured content type.
    $bundle = $this->config->get('bundle');
    $bundle = (isset($bundle)) ? $bundle : 'service_request';
    $query = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('changed', REQUEST_TIME, '<')
      ->condition('type', $bundle);

    $query->sort('changed', 'desc');
     // Checking for a limit parameter:


    $is_admin = ($parameters['key'] == \Drupal::state()->get('system.cron_key'));
    // Handle limit parameters for user one and other users
    $limit = ($is_admin) ? NULL : 25;
    $query_limit = $parameters['limit'];
    $limit = (isset($query_limit) &&  $query_limit <= 50) ? $query_limit : $limit;
    if ($limit) {
      $query->pager($limit);
    }

    // Process params to Drupal.
    $map = new GeoreportProcessor;

    // Checking for service_code and map the code with taxonomy terms:
    if (isset($parameters['service_code'])) {
      // Get the service of the current node:
      $tid = $map->service_map_tax($parameters['service_code']);
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

    foreach ($nodes as $node) {
      $service_requests[] = $map->node_map_request($node, $extensions);
    }
    if (!empty($service_requests)) {
      $response = new ResourceResponse($service_requests, 200);
      $response->addCacheableDependency($service_requests);

      return $response;
    } else {
      throw  new \Exception("No Service requests found", 404);
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
  public function post($request_data) {
    if(!$this->validate($request_data)){
      return false;
    }

    try {

      $map = new GeoreportProcessor();
      $values = $map->request_map_node($request_data);

      $node = \Drupal\node\Entity\Node::create($values);
      $node->validate();
      $node->save();

      $uuid = $node->uuid();
      $service_request = [];
      if (isset($node)) {
        $service_request['service_requests']['request']['service_request_id'] =  $uuid;
      }

      $this->logger->notice('Created entity %type with ID %uuid.', array('%type' => $node->getEntityTypeId(), '%uuid' => $node->uuid()));

      // 201 Created responses return the newly created entity in the response
      // body.
      // $url = $entity->urlInfo('canonical', ['absolute' => TRUE])->toString(TRUE);
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
   *
   */
  protected function validate($request_data) {
    $violations = NULL;

    // Remove violations of inaccessible fields as they cannot stem from our
    // changes.

    // var_dump(count($violations));
    if (count($violations) > 0) {
      $message = "Unprocessable Entity: validation failed.\n";
      $this->process_services_error(400, $message);
    } else {
      return TRUE;
    }
  }








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

}
