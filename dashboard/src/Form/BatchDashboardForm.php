<?php

namespace Drupal\dashboard\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class MyCustomForm.
 */
class BatchDashboardForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mymodule_my_custom_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $service = \Drupal::service('dashboard.manager');
     $form['#bas_stock']=  sizeof($service->getQueryStockBas()); 
     $form['#top_vente'] = $service->getQueryTopVenteArticleParMois();
     $form['#rupture_stock'] =  $service->getStockRuputure(); 
     $form['#dateProche'] =  $service->dateProche(); 
     $form['#dateProche'] =  $service->dateProche(); 
     $form['#total_vente_par_jour'] = $service->getTotalVenteParJour();
     $form['#benefice'] = $service->getBeneficeParJour();
     $form['#valuer_stock'] = $service->getStockValeur();
   
     
      $form['vente_par_mois'] = [
        '#type' => 'submit',
        '#value' => $this->t('Mettre à jour le graphique quotidien des ventes'),
        '#submit' => ['::submitFormOne'],
      ];
    // Text field example.
    $form['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Mettre à jour le graphique par année'),
     ];

      $form['#theme'] = 'dashboard_form_template';
      $form['#cache'] = [
        'max-age' => 0,  // Disable caching.
      ];
      
      return $form;
  }


  /**
   * Submit handler for the first button.
   */
  public function submitFormOne(array &$form, FormStateInterface $form_state) {
    $service = \Drupal::service('dashboard.manager');
    $rs = $service->getVenteParMois();
    $result =[];
    foreach ($rs as $val) {
        $montant = $val->field_total_vente_value;
        $date = $val->field_date_value; ;
        if(!isset($result[$date])){  $result[$date]  = 0 ;}
        $result[$date] =   $result[$date] + $montant ;                  
    }

    $days = $service->getDaysList();
    $final_vente = [];
    foreach ($days as $day) {
        if(!isset($result[$day])){
            $final_vente[$day] = 0 ;
        }else{
            $final_vente[$day] = $result[$day];
        }
    }
    $config = \Drupal::configFactory()->getEditable('dashboard.list');
    $config->set('venteParMois', $final_vente);
    $config->save();
  }



  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $service = \Drupal::service('dashboard.manager');
    $final_vente = $service-> getVenteParAnnee();
    $config = \Drupal::configFactory()->getEditable('dashboard.list');
    $config->set('venteParAnnulle', $final_vente);
    $config->save();
  }




}
