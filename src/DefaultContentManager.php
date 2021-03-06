<?php

/**
 * @file
 * Contains \Drupal\default_content\DefaultContentManager.
 */

namespace Drupal\default_content;

use Drupal\Core\Entity\EntityManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\rest\Plugin\Type\ResourcePluginManager;
use Gliph\Graph\DirectedAdjacencyList;
use Gliph\Traversal\DepthFirst;
use Symfony\Component\Serializer\Serializer;

/**
 * A service for handling import of default content.
 * @todo throw useful exceptions
 */
class DefaultContentManager implements DefaultContentManagerInterface {

  /**
   * The serializer service.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The rest resource plugin manager.
   *
   * @var \Drupal\rest\Plugin\Type\ResourcePluginManager
   */
  protected $resourcePluginManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The file system scanner.
   *
   * @var \Drupal\default_content\DefaultContentScanner
   */
  protected $scanner;

  /**
   * The tree resolver.
   *
   * @var \Gliph\Graph\DirectedAdjacencyList
   */
  protected $tree = FALSE;

  /**
   * A list of vertex objects keyed by their link.
   *
   * @var array
   */
  protected $vertexes = array();

  /**
   * Constructs the default content manager.
   *
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer service.
   * @param \Drupal\rest\Plugin\Type\ResourcePluginManager $resource_plugin_manager
   *   The rest resource plugin manager.
   * @param \Drupal\Core\Session|AccountInterface $current_user .
   *   The current user.
   * @param \Drupal\Core\Entity\EntityManager $entity_manager
   *   The entity manager service.
   */
  public function __construct(Serializer $serializer, ResourcePluginManager $resource_plugin_manager, AccountInterface $current_user, EntityManager $entity_manager) {
    $this->serializer = $serializer;
    $this->resourcePluginManager = $resource_plugin_manager;
    $this->entityManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function importContent($module) {
    $created = array();
    $folder = drupal_get_path('module', $module) . "/content";

    if (file_exists($folder)) {
      $file_map = array();
      foreach ($this->entityManager->getDefinitions() as $entity_type_id => $entity_type) {
        $reflection = new \ReflectionClass($entity_type->getClass());
        // We are only interested in importing content entities.
        if ($reflection->implementsInterface('\Drupal\Core\Config\Entity\ConfigEntityInterface')) {
          continue;
        }
        if (!file_exists($folder . '/' . $entity_type_id)) {
          continue;
        }
        $files = $this->scanner()->scan($folder . '/' . $entity_type_id);
        // Parse all of the files and sort them in order of dependency.
        foreach ($files as $file) {
          $contents = $this->parseFile($file);
          // Decode the file contents.
          $decoded = $this->serializer->decode($contents, 'hal_json');
          // Get the link to this entity.
          $self = $decoded['_links']['self']['href'];
          // Store the entity type with the file.
          $file->entity_type_id = $entity_type_id;
          // Store the file in the file map.
          $file_map[$self] = $file;
          // Create a vertex for the graph.
          $vertex = $this->getVertex($self);
          $this->tree()->addVertex($vertex);
          if (empty($decoded['_embedded'])) {
            // No dependencies to resolve.
            continue;
          }
          // Here we need to resolve our dependencies;
          foreach ($decoded['_embedded'] as $embedded) {
            foreach ($embedded as $item) {
              $this->tree()->addDirectedEdge($vertex, $this->getVertex($item['_links']['self']['href']));
            }
          }
        }
      }

      // @todo what if no dependencies?
      $sorted = $this->sortTree();
      foreach($sorted as $vertex) {
        if (!empty($file_map[$vertex->link])) {
          $file = $file_map[$vertex->link];
          $entity_type_id = $file->entity_type_id;
          $resource = $this->resourcePluginManager->getInstance(array('id' => 'entity:' . $entity_type_id));
          $definition = $resource->getPluginDefinition();
          $contents = $this->parseFile($file);
          $class = $definition['serialization_class'];
          $entity = $this->serializer->deserialize($contents, $class, 'hal_json', array('request_method' => 'POST'));
          $entity->enforceIsNew(TRUE);
          $entity->save();
          $created[] = $entity;
        }
      }
    }
    // Reset the tree.
    $this->resetTree();
    return $created;
  }

  /**
   * {@inheritdoc}
   */
  public function exportContent($entity_type_id, $entity_id) {
    $storage = $this->entityManager->getStorage($entity_type_id);
    $entity = $storage->load($entity_id);

    return $this->serializer->serialize($entity, 'hal_json', ['json_encode_options' => JSON_PRETTY_PRINT]);
  }

  /**
   * Utility to get a default content scanner
   *
   * @return \Drupal\default_content\DefaultContentScanner
   *   A system listing implementation.
   */
  protected function scanner() {
    if ($this->scanner) {
      return $this->scanner;
    }
    return new DefaultContentScanner();
  }

  /**
   * {@inheritdoc}
   */
  public function setScanner(DefaultContentScanner $scanner) {
    $this->scanner = $scanner;
  }

  /**
   * Parses content files
   */
  protected function parseFile($file) {
    return file_get_contents($file->uri);
  }

  protected function tree() {
    if (empty($this->tree)) {
      $this->tree = new DirectedAdjacencyList();
    }
    return $this->tree;
  }

  protected function resetTree() {
    $this->tree = FALSE;
    $this->vertexes = array();
  }

  protected function sortTree() {
    return DepthFirst::toposort($this->tree());
  }

  /**
   * Returns a vertex object for a given item link.
   *
   * Ensures that the same object is returned for the same item link.
   *
   * @param string $item_link
   *   The item link as a string.
   *
   * @return object
   *   The vertex object.
   */
  protected function getVertex($item_link) {
    if (!isset($this->vertexes[$item_link])) {
      $this->vertexes[$item_link] = (object) array('link' => $item_link);
    }
    return $this->vertexes[$item_link];
  }

}
