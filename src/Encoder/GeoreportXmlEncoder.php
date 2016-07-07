<?php

/**
 * @file
 * Contains \Drupal\serialization\Encoder\XmlEncoder.
 */

namespace Drupal\markaspot_open311\Encoder;

use Drupal\serialization\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Encoder\EncoderInterface;

/**
 * Adds XML support for serializer.
 *
 * This acts as a wrapper class for Symfony's XmlEncoder so that it is not
 * implementing NormalizationAwareInterface, and can be normalized externally.
 */
class GeoreportXmlEncoder extends XmlEncoder implements  EncoderInterface {

  /**
   * Gets the base encoder instance.
   *
   * @return \Symfony\Component\Serializer\Encoder\XmlEncoder
   *   The base encoder.
   */
  public function getBaseEncoder() {
    if (!isset($this->baseEncoder)) {
      $this->baseEncoder = new XmlEncoder();
    }
    return $this->baseEncoder;
  }


  /**
   * {@inheritdoc}
   */
  public function encode($data, $format, array $context = array()){

    // Checking passed data for keywords resulting in different root_nodes.
    if (NULL !=  array_key_exists('error', $data)) {
      $context['xml_root_node_name'] = "errors";
    } elseif (array_key_exists('metadata', $data[0])){
      $context['xml_root_node_name'] = "services";
    } else {
      $context['xml_root_node_name'] = "service_requesta";
    }

    return $this->getBaseEncoder()->encode($data, $format, $context);
  }


  /**
   * Selects the type of node to create and appends it to the parent.
   *
   * @param \DOMNode     $parentNode
   * @param array|object $data
   * @param string       $nodeName
   * @param string       $key
   *
   * @return bool
   */
  function appendNode(\DOMNode $parentNode, $data, $nodeName, $key = null)
  {
    $node = $this->dom->createElement($nodeName);
    if (null !== $key) {
      $node->setAttribute('mykey', $key);
    }
    $appendNode = $this->selectNodeType($node, $data);
    // we may have decided not to append this node, either in error or if its $nodeName is not valid
    if ($appendNode) {
      $parentNode->appendChild($node);
    }

    return $appendNode;
  }

}
