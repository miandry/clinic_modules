<?php

namespace Drupal\dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Class DefaultController.
 */
class DefaultController extends ControllerBase {

  /**
   * Topvente.
   *
   * @return string
   *   Return Hello string.
   */
  public function topvente() {
    $service = \Drupal::service('dashboard.manager');
    $service_helper = \Drupal::service('drupal.helper');
    $params = $service_helper->helper->get_parameter();

    $filter = $service->defaultFilterTopVenteArticle();
    if(isset($params['date_start'])){
      $filter['date_start'] = $params['date_start'];
    }
    if(isset($params['date_end'])){
      $filter['date_end'] = $params['date_end'];
    }
    $items = $service->getQueryTopVenteArticleParDate( $params);
    return [
      '#theme' => 'dashboard_topvente', // The theme hook defined in hook_theme.
      '#items' => ['data' => $items , 'filter' =>   $filter ],
      '#cache' => [
        'max-age' => 0,  // Disable caching.
      ]
    ];
  }

}
