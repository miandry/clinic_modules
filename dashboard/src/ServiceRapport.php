<?php

/**
 * Created by PhpStorm.
 * User: admin
 * Date: 23/03/2018
 * Time: 14:49
 */

namespace Drupal\dashboard;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\node\Entity\Node;
use Drupal\Core\Database\Database;



class ServiceRapport
{
       function getQueryvaleurDuStock(){
        $entity_query = \Drupal::entityQuery('node');
        // Query for article nodes.
        return $entity_query->condition('type', 'article')
        ->execute();
        }
        function getStockValeur(){
            $query = Database::getConnection()->select('node_field_data', 'nfd');
            $query->join('node__field_quantite_stock', 'fqs', 'nfd.nid = fqs.entity_id');
            $query->join('node__field_prix_unitaire', 'u', 'nfd.nid = u.entity_id');
            $query->condition('nfd.type', 'article');  // Filter by content type 'article'.
    
            $query->addExpression('SUM(fqs.field_quantite_stock_value*u.field_prix_unitaire_value)', 'total_valeur'); // Sum of quantities.
            // Execute the query.
            $result = $query->execute();
            // Fetch the results as an associative array.
            $sum =  $result->fetchAll();
            if(!empty( $sum)){
              return  $sum[0]->total_valeur;
            }
            return 0 ;
        }
       function getStockRuputure(){
            $query = Database::getConnection()->select('node_field_data', 'nfd');
            $query->join('node__field_quantite_stock', 'fqs', 'nfd.nid = fqs.entity_id');
            $query->join('node__field_prix_unitaire', 'u', 'nfd.nid = u.entity_id');
            $query->condition('nfd.type', 'article');  // Filter by content type 'article'.
           // $query->condition('fqs.field_quantite_stock_value',0,'>'); 
            $query->addField('nfd', 'nid', 'id'); 
            $query->addField('nfd', 'title', 'name'); 
            $query->addField('fqs', 'field_quantite_stock_value', 'stock'); 
            // Execute the query.
            $result = $query->execute();
            // Fetch the results as an associative array.
            return sizeof($result->fetchAllAssoc('id'));
       }
       function getQueryStockBas(){
            $query = Database::getConnection()->select('node_field_data', 'nfd');
            $query->join('node__field_quantite_stock', 'fqs', 'nfd.nid = fqs.entity_id');
            $query->join('node__field_limite_stock', 'fls', 'nfd.nid = fls.entity_id');
            //$query->range(0,100);
            $query->condition('nfd.type', 'article');  // Filter by content type 'article'.
            $query->where('fqs.field_quantite_stock_value < fls.field_limite_stock_value');
            $query->where('fqs.field_quantite_stock_value >  0');
            $query->addField('nfd', 'nid', 'id'); 
            $query->addField('nfd', 'title', 'name'); 
            $query->addField('fqs', 'field_quantite_stock_value', 'stock'); 
            $query->addField('fls', 'field_limite_stock_value', 'limit'); 
            // Execute the query.
            $result = $query->execute();
            // Fetch the results as an associative array.
            return $result->fetchAllAssoc('id');
       }
       function defaultFilterTopVenteArticle(){
        $current_date = \Drupal::service('datetime.time')->getCurrentTime();
        $first_day_of_month = date('Y-m-d', strtotime('first day of this month', $current_date));
        $last_day_of_month = date('Y-m-d', strtotime('last day of this month', $current_date));
        return [
            'date_start' =>        $first_day_of_month,
            'date_end' =>   $last_day_of_month
        ];
       }
       function verifyDatesInterval($startDate,$endDate){
        // Create DateTime objects
            $date1 = \DateTime::createFromFormat('Y-m-d', $startDate);
            $date2 = \DateTime::createFromFormat('Y-m-d', $endDate);
            // Calculate the difference
            $interval = $date1->diff($date2);
            // Check if the difference is less than 30 days
            if ($interval->days > 32) {
                $message =  "The difference is 31 days or more";
                \Drupal::messenger()->addMessage( $message , 'error');
                return false ;
            }
            return  true ;
       }
       function getQueryTopVenteArticleParDate($dates = null ){

    

        $current_date = \Drupal::service('datetime.time')->getCurrentTime();
        $first_day_of_month = strtotime('first day of this month', $current_date);
        $last_day_of_month =  strtotime('last day of this month', $current_date);
       // $date_string = '2024/10/15';
       // $first_day_of_month = strtotime('2025-06-01');
       // $last_day_of_month = strtotime( '2025-06-07');
        if(isset($dates['date_start'])  &&  $dates['date_start'] !=''){
            
            $first_day_of_month = strtotime($dates['date_start']);
        }
        if(isset($dates['date_end'])  &&  $dates['date_end'] !='' ){
            $last_day_of_month = strtotime($dates['date_end']);
        }
        if(isset($dates['date_start'])  &&  $dates['date_start'] !='' && 
           isset($dates['date_end'])  &&  $dates['date_end'] !=''){
            $status =$this->verifyDatesInterval($dates['date_start'], $dates['date_end']);
            if($status === false ){
               return false ;
            }
        }
      //  kint([$first_day_of_month, $last_day_of_month]);


                // Step 1: Build the database query on 'node_field_data' table.
                $query = Database::getConnection()->select('paragraph__field_article', 'article');
                // Join the paragraph table for 'field_quantite' (quantity of articles).
                $query->join('paragraph__field_quantite', 'qt', 'article.entity_id = qt.entity_id');

                // Join the paragraph table for 'field_article' (referenced article).
                $query->join('paragraphs_item_field_data', 'data', 'article.entity_id = data.id ');
                $query->join('node__field_status', 'status', 'data.parent_id = status.entity_id ');
                
                $query->join('paragraph__field_prix_unitaire', 'pu', 'article.entity_id = pu.entity_id ');
      
                // // Add conditions to filter the right nodes.
                $query->condition('article.bundle', 'commande');  // Filter by content type 'commande'.
                // //$query->condition('nfd.status', 1);  // Only published nodes.
               
                //if($first_day_of_month == $last_day_of_month){
                   // $query->condition('data.created', $first_day_of_month,'>=');
                   // $query->condition('data.created', $last_day_of_month,'<=');
              //  }else{
                    $query->condition('data.created', [$first_day_of_month, $last_day_of_month], 'BETWEEN');
              //  }
   

                 if(isset( $dates['article_id']) &&  $dates['article_id'] !=''){
                     $query->condition('article.field_article_target_id', $dates['article_id']);
                 }
                 $query->condition('status.field_status_value', 'payed');
  
                // Group by the referenced article ID.
               $query->groupBy('article.field_article_target_id');
                $query->addExpression('SUM(qt.field_quantite_value)', 'total_quantity'); // Sum of quantities.
                $query->addExpression('SUM(pu.field_prix_unitaire_value*qt.field_quantite_value)', 'total_vente'); // Sum of quantities.
                 // Add an expression to count the number of articles (distinct).
                $query->addExpression('COUNT(article.entity_id)', 'repeat_count'); // Count occurrences of the same article.

            

                
              //  $query->addField('data', 'created', 'date'); // Article ID as 'article_nid'.
               
                $query->addField('article', 'field_article_target_id', 'article_nid'); // Article ID as 'article_nid'.
         
              
                // Order by the total quantity sold in descending order.
                $query->orderBy('total_quantity', 'DESC');

                // Limit the query to the top 5 results.
              //  $query->range(0, 50);
                // Execute the query.
                $result = $query->execute();
         
                // Fetch the results as an associative array.
                $rows = array_values($result->fetchAll());
       
                $items = [];
                if(!empty($rows)){
                    $service = \Drupal::service('entity_parser.manager');
                    foreach($rows as $r){
                        $article = $service->node_parser($r->article_nid);
                        if(isset($article['title'])){
                            $items[] = [
                                'title' => $article['title'],
                                'article_nid' => $r->article_nid,   
                                'total_quantity' => $r->total_quantity,                            
                                'repeat_count' => $r->repeat_count,    
                                'total_vente' => $r->total_vente,     
                                       
                            ]; 
                        }                
                    }
                }
               
                return  $items ;



       }
       function getQueryTopVenteArticleParMois(){

    

        $current_date = \Drupal::service('datetime.time')->getCurrentTime();
        $first_day_of_month = strtotime('first day of this month', $current_date);
        $last_day_of_month =  strtotime('last day of this month', $current_date);
    
                // Step 1: Build the database query on 'node_field_data' table.
                $query = Database::getConnection()->select('paragraph__field_article', 'article');
                // Join the paragraph table for 'field_quantite' (quantity of articles).
                $query->join('paragraph__field_quantite', 'qt', 'article.entity_id = qt.entity_id');

                // Join the paragraph table for 'field_article' (referenced article).
                $query->join('paragraphs_item_field_data', 'data', 'article.entity_id = data.id ');
                $query->join('node__field_status', 'status', 'data.parent_id = status.entity_id ');
                
                $query->join('paragraph__field_prix_unitaire', 'pu', 'article.entity_id = pu.entity_id ');
      
                // // Add conditions to filter the right nodes.
                $query->condition('article.bundle', 'commande');  // Filter by content type 'commande'.

                $query->condition('data.created', [$first_day_of_month, $last_day_of_month], 'BETWEEN');
           

                 $query->condition('status.field_status_value', 'payed');
  
                // Group by the referenced article ID.
                 $query->groupBy('article.field_article_target_id');
                $query->addExpression('SUM(qt.field_quantite_value)', 'total_quantity'); // Sum of quantities.      
              //  $query->addField('data', 'created', 'date'); // Article ID as 'article_nid'.
                $query->addField('article', 'field_article_target_id', 'article_nid'); // Article ID as 'article_nid'.
         
              
                // Order by the total quantity sold in descending order.
                $query->orderBy('total_quantity', 'DESC');

                // Limit the query to the top 5 results.
              //  $query->range(0, 50);
                // Execute the query.
                $result = $query->execute();
         
                // Fetch the results as an associative array.
                return array_values($result->fetchAll());
       }

       function getDaysList(){
                    // Get the current year and month
            $currentYear = date('Y');
            $currentMonth = date('m');

            // Get the number of days in the current month
            $numDaysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);

            // Initialize an empty array to store date strings
            $dateArray = array();

            // Loop through each day of the month
            for ($day = 1; $day <= $numDaysInMonth; $day++) {
                // Format the day and month with leading zeros if necessary
                $formattedDay = str_pad($day, 2, '0', STR_PAD_LEFT);
                $formattedMonth = str_pad($currentMonth, 2, '0', STR_PAD_LEFT);
                // Construct the date string
                $date = $currentYear . '-' . $formattedMonth . '-' . $formattedDay;
                // Add the date string to the array
                $dateArray[] = $date;
            }
            return  $dateArray;
       }
       function getMoisList(){
                    // Get the current year
            $currentYear = date('Y');

            // Initialize an empty array to store year-month strings
            $yearMonthArray = array();

            // Loop through each month of the current year
            for ($month = 1; $month <= 12; $month++) {
                // Format the month with leading zero if necessary
                $formattedMonth = str_pad($month, 2, '0', STR_PAD_LEFT);
                // Construct the year-month string
                $yearMonth = $currentYear . '-' . $formattedMonth;
                // Add the year-month string to the array
                $yearMonthArray[] = $yearMonth;
            }
            return  $yearMonthArray ;
       }
       function venteParAnnulle(){
            $current_year = date('Y');
            $start_date = new DrupalDateTime("{$current_year}-01-01T00:00:00");
            $start_timestamp = $start_date->getTimestamp();

            $entity_query = \Drupal::entityQuery('node');
            // Query for article nodes.
            $nids = $entity_query->condition('type', 'commande')
            ->condition('field_status','payed', '=')
            ->condition('field_date', $start_timestamp, '>=')
            ->execute();     
                // Load the nodes.
            $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);
            $resultat=[];
            $resultat_achat=[];
            // Output the node titles.
            foreach ($nodes as $commande) {
                $dateString =  $commande->field_date->value ;
                $date = new \DateTime($dateString);
                $yearAndMonth = $date->format('Y-m');
                if(!isset($resultat[$yearAndMonth])){$resultat[$yearAndMonth] = 0 ;}
                if(!isset($resultat_achat[$yearAndMonth])){$resultat_achat[$yearAndMonth] = 0 ;}
                $resultat[$yearAndMonth] = $resultat[$yearAndMonth] + $commande->field_total_vente->value ;             
                $resultat_achat[$yearAndMonth] = $resultat_achat[$yearAndMonth] + $commande->field_total_achat->value ;      
            }
            $months = $this->getMoisList();
            $final_vente = [];
            $final_achat = [];
            foreach ($months as $mt) {
                if(!isset($resultat[$mt])){
                    $final_vente[$mt] = 0 ;
                }else{
                    $final_vente[$mt] = $resultat[$mt];
                }
                if(!isset($resultat_achat[$mt])){
                    $final_achat[$mt] = 0 ;
                }else{
                    $final_achat[$mt] = $resultat_achat[$mt];
                }
            }
            return [
                'vente' =>  $final_vente ,
                'achat' => $final_achat
            ];
       }
       function getBeneficeParJour(){

        $current_date = \Drupal::service('datetime.time')->getCurrentTime();
        $day = date('Y-m-d', $current_date);
       // $day = "2024-10-15";
        //kint($day);
    
        // Start the select query from node_field_data.
        $query = Database::getConnection()->select('node_field_data', 'nfd');

        // Join field table for 'field_date' and 'field_status' (assuming they are fieldable entity fields).
        $query->join('node__field_date', 'fd', 'nfd.nid = fd.entity_id');
        $query->join('node__field_status', 'fs', 'nfd.nid = fs.entity_id');
        $query->join('node__field_total_vente', 'tt', 'nfd.nid = tt.entity_id');
        $query->join('node__field_total_achat', 'ta', 'nfd.nid = ta.entity_id');

        $query->addExpression('SUM(tt.field_total_vente_value) - SUM(ta.field_total_achat_value)', 'benefice'); // Sum of quantities.
        
        // Add conditions.
        $query->condition('nfd.type', 'commande');  // Filter by content type 'commande'.
        $query->condition('fd.field_date_value', $day, '=');  // Filter by date range.
        $query->condition('fs.field_status_value', 'payed');  // Only nodes with status 'payed'.
        // Select the fields you need (node ID in this case).
      //  $query->fields('nfd', ['nid']);
      //  $query->fields('tt', ['field_total_vente_value']);

        // Execute the query.
        $result = $query->execute();
        // Fetch the node IDs.
        $sum =  $result->fetchAll();
        if(!empty( $sum)){
          return  $sum[0]->benefice;
        }
        return 0 ;
}
       function getTotalVenteParJour(){

                $current_date = \Drupal::service('datetime.time')->getCurrentTime();
                $day = date('Y-m-d', $current_date);
             //   $day = "2024-10-15";
              //  kint($day);
            
                // Start the select query from node_field_data.
                $query = Database::getConnection()->select('node_field_data', 'nfd');

                // Join field table for 'field_date' and 'field_status' (assuming they are fieldable entity fields).
                $query->join('node__field_date', 'fd', 'nfd.nid = fd.entity_id');
                $query->join('node__field_status', 'fs', 'nfd.nid = fs.entity_id');
                $query->join('node__field_total_vente', 'tt', 'nfd.nid = tt.entity_id');
                $query->addExpression('SUM(tt.field_total_vente_value)', 'total_vente'); // Sum of quantities.

                // Add conditions.
                $query->condition('nfd.type', 'commande');  // Filter by content type 'commande'.
                $query->condition('fd.field_date_value', $day, '=');  // Filter by date range.
                $query->condition('fs.field_status_value', 'payed');  // Only nodes with status 'payed'.
                // Select the fields you need (node ID in this case).
              //  $query->fields('nfd', ['nid']);
              //  $query->fields('tt', ['field_total_vente_value']);

                // Execute the query.
                $result = $query->execute();
                // Fetch the node IDs.
                $sum =  $result->fetchAll();
                if(!empty( $sum)){
                  return  $sum[0]->total_vente;
                }
                return 0 ;
       }
       function getVenteParAnnee(){

  
        $current_year = date('Y');
        $start_date = new DrupalDateTime("{$current_year}-01-01T00:00:00");
        $start_timestamp = $start_date->getTimestamp();
        $first_day_of_year = date('Y-m-d 00:00:00', $start_timestamp);

        // Start the select query from node_field_data.
        $query = Database::getConnection()->select('node_field_data', 'nfd');

        // Join field table for 'field_date' and 'field_status' (assuming they are fieldable entity fields).
        $query->join('node__field_date', 'fd', 'nfd.nid = fd.entity_id');
        $query->join('node__field_status', 'fs', 'nfd.nid = fs.entity_id');
        $query->join('node__field_total_vente', 'tt', 'nfd.nid = tt.entity_id');
        $query->join('node__field_total_achat', 'ta', 'nfd.nid = ta.entity_id');
        // Add conditions.
        $query->condition('nfd.type', 'commande');  // Filter by content type 'commande'.
        $query->condition('fd.field_date_value',$first_day_of_year, '>=');  // Filter by date range.
        $query->condition('fs.field_status_value', 'payed');  // Only nodes with status 'payed'.
        $query->addExpression("DATE_FORMAT(fd.field_date_value, '%Y-%m')", 'formatted_date');
        $query->addExpression("SUM(tt.field_total_vente_value)", 'total_vente');
        $query->addExpression("SUM(ta.field_total_achat_value)", 'total_achat');
        $query->orderBy('formatted_date', 'ASC');
        $query->groupBy('formatted_date');
        $result = $query->execute();
        $months = $this->getMoisList();
      
        // Fetch the node IDs.
        $resultat = $result->fetchAllAssoc('formatted_date');
        $final_vente = [];
        $final_achat = [];
        foreach ($months as $mt) {
            if(!isset($resultat[$mt])){
                $final_vente[$mt] = 0 ;
            }else{
                $final_vente[$mt] = $resultat[$mt]->total_vente;
            }
            if(!isset($resultat[$mt])){
                $final_achat[$mt] = 0 ;
            }else{
                $final_achat[$mt] = $resultat[$mt]->total_achat;
            }
        }
        return [
            'vente' =>  $final_vente ,
            'achat' => $final_achat
        ];
   }
       function getVenteParMois(){

            $current_date = \Drupal::service('datetime.time')->getCurrentTime();
            $first_day_of_month = date('Y-m-d 00:00:00', strtotime('first day of this month', $current_date));
            $last_day_of_month = date('Y-m-d 23:59:59', strtotime('last day of this month', $current_date));
       
            // Start the select query from node_field_data.
            $query = Database::getConnection()->select('node_field_data', 'nfd');

            // Join field table for 'field_date' and 'field_status' (assuming they are fieldable entity fields).
            $query->join('node__field_date', 'fd', 'nfd.nid = fd.entity_id');
            $query->join('node__field_status', 'fs', 'nfd.nid = fs.entity_id');
            $query->join('node__field_total_vente', 'tt', 'nfd.nid = tt.entity_id');

            // Add conditions.
            $query->condition('nfd.type', 'commande');  // Filter by content type 'commande'.
            $query->condition('fd.field_date_value', [$first_day_of_month, $last_day_of_month], 'BETWEEN');  // Filter by date range.
            $query->condition('fs.field_status_value', 'payed');  // Only nodes with status 'payed'.

           // $query->range(0,10000);  // Only nodes with status 'payed'.


            // Select the fields you need (node ID in this case).
            $query->fields('nfd', ['nid']);
            $query->fields('tt', ['field_total_vente_value']);
            $query->fields('fd', ['field_date_value']);

            // Execute the query.
            $result = $query->execute();

            // Fetch the node IDs.
            return $result->fetchAllAssoc('nid');
       }
  
       function dateProche(){
                // Get the current timestamp
            $currentTimestamp = time();

            // Calculate the timestamp for 5 days ago
            $fiveDaysAgoTimestamp = strtotime('-10 days', $currentTimestamp);

            // Convert the timestamp to a DrupalDateTime object
            $fiveDaysAgoDateTime = new DrupalDateTime('@' . $fiveDaysAgoTimestamp);

            $entity_query = \Drupal::entityQuery('node');
            // Query for article nodes.
            $nids = $entity_query->condition('type', 'stock')
            ->condition('field_peremption', $fiveDaysAgoDateTime->format('Y-m-d\TH:i:s'), '>=')
            ->execute();   
            $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);
            $result = [];
            foreach ($nodes as $stock) {
                if($stock->field_article && $stock->field_article->entity){
                   $result[$stock->id()] = $stock->field_article->entity->label();
                }
            }
             return $result ;
       }
    

}
