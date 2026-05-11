<?php

namespace Drupal\rag_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * RAG API Controller for AI database access.
 */
class RagController extends ControllerBase
{

  /**
   * Search patients by name, phone, email, or other criteria.
   */
  public function searchPatients(Request $request)
  {
    $params = $this->getParams($request);
    $query = $params['query'] ?? '';
    $limit = (int) ($params['limit'] ?? 20);
    $offset = (int) ($params['offset'] ?? 0);
    $filters = $params['filters'] ?? [];

    $connection = Database::getConnection();
    $queryBuilder = $connection->select('node_field_data', 'n')
      ->fields('n', ['nid', 'title', 'created'])
      ->condition('n.type', 'client')
      ->condition('n.status', 1);

    // Join with patient fields
    $queryBuilder->leftJoin('node__field_age', 'age', 'age.entity_id = n.nid');
    $queryBuilder->leftJoin('node__field_sexe', 'sexe', 'sexe.entity_id = n.nid');
    $queryBuilder->leftJoin('node__field_phone', 'phone', 'phone.entity_id = n.nid');
    $queryBuilder->leftJoin('node__field_email', 'email', 'email.entity_id = n.nid');
    $queryBuilder->leftJoin('node__field_adresse', 'adresse', 'adresse.entity_id = n.nid');
    $queryBuilder->leftJoin('node__field_allergies', 'allergies', 'allergies.entity_id = n.nid');
    $queryBuilder->leftJoin('node__field_assurance', 'assurance', 'assurance.entity_id = n.nid');
    $queryBuilder->leftJoin('node__field_contact_d_urgence', 'urgence', 'urgence.entity_id = n.nid');
    $queryBuilder->leftJoin('node__field_notes_medicales', 'notes', 'notes.entity_id = n.nid');

    $queryBuilder->fields('age', ['field_age_value']);
    $queryBuilder->fields('sexe', ['field_sexe_value']);
    $queryBuilder->fields('phone', ['field_phone_value']);
    $queryBuilder->fields('email', ['field_email_value']);
    $queryBuilder->fields('adresse', ['field_adresse_value']);
    $queryBuilder->fields('allergies', ['field_allergies_value']);
    $queryBuilder->fields('assurance', ['field_assurance_value']);
    $queryBuilder->fields('urgence', ['field_contact_d_urgence_value']);
    $queryBuilder->fields('notes', ['field_notes_medicales_value']);

    // Search filter
    if (!empty($query)) {
      $group = $queryBuilder->orConditionGroup()
        ->condition('n.title', "%$query%", 'LIKE')
        ->condition('phone.field_phone_value', "%$query%", 'LIKE')
        ->condition('email.field_email_value', "%$query%", 'LIKE')
        ->condition('adresse.field_adresse_value', "%$query%", 'LIKE');
      $queryBuilder->condition($group);
    }

    // Apply additional filters
    foreach ($filters as $field => $value) {
      switch ($field) {
        case 'sexe':
          $queryBuilder->condition('sexe.field_sexe_value', $value);
          break;
        case 'assurance':
          $queryBuilder->condition('assurance.field_assurance_value', $value);
          break;
        case 'age_min':
          $queryBuilder->condition('age.field_age_value', $value, '>=');
          break;
        case 'age_max':
          $queryBuilder->condition('age.field_age_value', $value, '<=');
          break;
      }
    }

    $queryBuilder->range($offset, $limit);
    $queryBuilder->orderBy('n.created', 'DESC');

    $results = $queryBuilder->execute()->fetchAll();

    $patients = array_map(function ($row) {
      return [
        'nid' => (int) $row->nid,
        'name' => $row->title,
        'age' => $row->field_age_value ? (int) $row->field_age_value : null,
        'sexe' => $row->field_sexe_value,
        'phone' => $row->field_phone_value,
        'email' => $row->field_email_value,
        'adresse' => $row->field_adresse_value,
        'allergies' => $row->field_allergies_value,
        'assurance' => $row->field_assurance_value === '1',
        'contact_urgence' => $row->field_contact_d_urgence_value,
        'notes_medicales' => $row->field_notes_medicales_value,
        'created' => (int) $row->created,
      ];
    }, $results);

    return new JsonResponse([
      'success' => TRUE,
      'count' => count($patients),
      'data' => $patients,
    ]);
  }

  /**
   * Search medications/medicines by name or stock status.
   */
  public function searchMedications(Request $request)
  {
    $params = $this->getParams($request);
    $query = $params['query'] ?? '';
    $limit = (int) ($params['limit'] ?? 50);
    $offset = (int) ($params['offset'] ?? 0);
    $inStockOnly = $params['in_stock_only'] ?? FALSE;
    $lowStockOnly = $params['low_stock_only'] ?? FALSE;
    $lowStockThreshold = (int) ($params['low_stock_threshold'] ?? 10);

    try {
      $connection = Database::getConnection();
      $queryBuilder = $connection->select('node_field_data', 'n')
        ->fields('n', ['nid', 'title', 'created'])
        ->condition('n.type', 'article')
        ->condition('n.status', 1);

      // Join with article fields (using actual field names from database)
      $queryBuilder->leftJoin('node__field_quantite_stock', 'stock', 'stock.entity_id = n.nid');
      $queryBuilder->leftJoin('node__field_prix_unitaire', 'prix', 'prix.entity_id = n.nid');
      $queryBuilder->leftJoin('node__field_stock_unitaire', 'unite', 'unite.entity_id = n.nid');
      $queryBuilder->leftJoin('node__field_categorie', 'cat', 'cat.entity_id = n.nid');
      $queryBuilder->leftJoin('node__field_dci', 'dci', 'dci.entity_id = n.nid');

      $queryBuilder->fields('stock', ['field_quantite_stock_value']);
      $queryBuilder->fields('prix', ['field_prix_unitaire_value']);
      $queryBuilder->fields('unite', ['field_stock_unitaire_target_id']);
      $queryBuilder->fields('cat', ['field_categorie_target_id']);
      $queryBuilder->fields('dci', ['field_dci_target_id']);

      // Search filter
      if (!empty($query)) {
        $group = $queryBuilder->orConditionGroup()
          ->condition('n.title', "%$query%", 'LIKE');
        $queryBuilder->condition($group);
      }

      // Stock filters
      if ($inStockOnly) {
        $queryBuilder->condition('stock.field_quantite_stock_value', 0, '>');
      }

      if ($lowStockOnly) {
        $queryBuilder->condition('stock.field_quantite_stock_value', $lowStockThreshold, '<=');
        $queryBuilder->condition('stock.field_quantite_stock_value', 0, '>');
      }

      $queryBuilder->range($offset, $limit);
      $queryBuilder->orderBy('n.title', 'ASC');

      $results = $queryBuilder->execute()->fetchAll();

      // Get category names
      $catIds = array_filter(array_column($results, 'field_categorie_target_id'));
      $categories = [];
      if (!empty($catIds)) {
        $catQuery = $connection->select('taxonomy_term_field_data', 't')
          ->fields('t', ['tid', 'name'])
          ->condition('t.tid', $catIds, 'IN')
          ->execute();
        foreach ($catQuery as $cat) {
          $categories[$cat->tid] = $cat->name;
        }
      }

      // Get unit names
      $unitIds = array_filter(array_column($results, 'field_stock_unitaire_target_id'));
      $units = [];
      if (!empty($unitIds)) {
        $unitQuery = $connection->select('taxonomy_term_field_data', 't')
          ->fields('t', ['tid', 'name'])
          ->condition('t.tid', $unitIds, 'IN')
          ->execute();
        foreach ($unitQuery as $unit) {
          $units[$unit->tid] = $unit->name;
        }
      }

      // Get DCI names
      $dciIds = array_filter(array_column($results, 'field_dci_target_id'));
      $dcis = [];
      if (!empty($dciIds)) {
        $dciQuery = $connection->select('taxonomy_term_field_data', 't')
          ->fields('t', ['tid', 'name'])
          ->condition('t.tid', $dciIds, 'IN')
          ->execute();
        foreach ($dciQuery as $dci) {
          $dcis[$dci->tid] = $dci->name;
        }
      }

      $medications = array_map(function ($row) use ($categories, $units, $dcis) {
        return [
          'nid' => (int) $row->nid,
          'title' => $row->title,
          'stock' => (int) ($row->field_quantite_stock_value ?? 0),
          'price' => (float) ($row->field_prix_unitaire_value ?? 0),
          'unit' => $units[$row->field_stock_unitaire_target_id] ?? null,
          'dci' => $dcis[$row->field_dci_target_id] ?? null,
          'category' => $categories[$row->field_categorie_target_id] ?? null,
          'is_low_stock' => ($row->field_quantite_stock_value ?? 0) <= 10 && ($row->field_quantite_stock_value ?? 0) > 0,
          'created' => (int) $row->created,
        ];
      }, $results);

      return new JsonResponse([
        'success' => TRUE,
        'count' => count($medications),
        'data' => $medications,
      ]);
    } catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'count' => 0,
        'data' => [],
      ]);
    }
  }

  /**
   * Search consultations by patient, date, or motif.
   */
  public function searchConsultations(Request $request)
  {
    $params = $this->getParams($request);
    $query = $params['query'] ?? '';
    $patientId = $params['patient_id'] ?? null;
    $dateFrom = $params['date_from'] ?? null;
    $dateTo = $params['date_to'] ?? null;
    $limit = (int) ($params['limit'] ?? 20);
    $offset = (int) ($params['offset'] ?? 0);

    try {
      $connection = Database::getConnection();

      // Check if consultations type exists
      $typeExists = $connection->select('node_field_data', 'n')
        ->condition('n.type', 'consultations')
        ->countQuery()
        ->execute()
        ->fetchField();

      if (!$typeExists) {
        return new JsonResponse([
          'success' => TRUE,
          'count' => 0,
          'data' => [],
          'message' => 'No consultations found',
        ]);
      }

      $queryBuilder = $connection->select('node_field_data', 'n')
        ->fields('n', ['nid', 'title', 'created'])
        ->condition('n.type', 'consultations')
        ->condition('n.status', 1);

      // Join fields - wrap in try-catch for each field table
      try {
        $queryBuilder->leftJoin('node__field_client', 'client', 'client.entity_id = n.nid');
        $queryBuilder->fields('client', ['field_client_target_id']);
      } catch (\Exception $e) {
      }

      try {
        $queryBuilder->leftJoin('node__field_motif', 'motif', 'motif.entity_id = n.nid');
        $queryBuilder->fields('motif', ['field_motif_value']);
      } catch (\Exception $e) {
      }

      try {
        $queryBuilder->leftJoin('node__field_temperature', 'temp', 'temp.entity_id = n.nid');
        $queryBuilder->fields('temp', ['field_temperature_value']);
      } catch (\Exception $e) {
      }

      try {
        $queryBuilder->leftJoin('node__field_tension_arterielle', 'tension', 'tension.entity_id = n.nid');
        $queryBuilder->fields('tension', ['field_tension_arterielle_value']);
      } catch (\Exception $e) {
      }

      try {
        $queryBuilder->leftJoin('node__field_poids', 'poids', 'poids.entity_id = n.nid');
        $queryBuilder->fields('poids', ['field_poids_value']);
      } catch (\Exception $e) {
      }

      // Get patient name via join
      $queryBuilder->leftJoin('node_field_data', 'patient', 'patient.nid = client.field_client_target_id');
      $queryBuilder->addField('patient', 'title', 'patient_name');

      // Filters
      if (!empty($query)) {
        $group = $queryBuilder->orConditionGroup()
          ->condition('patient.title', "%$query%", 'LIKE');
        if (isset($motif)) {
          $group->condition('motif.field_motif_value', "%$query%", 'LIKE');
        }
        $queryBuilder->condition($group);
      }

      if ($patientId) {
        $queryBuilder->condition('client.field_client_target_id', $patientId);
      }

      if ($dateFrom) {
        $queryBuilder->condition('n.created', strtotime($dateFrom), '>=');
      }

      if ($dateTo) {
        $queryBuilder->condition('n.created', strtotime($dateTo) + 86400, '<');
      }

      $queryBuilder->range($offset, $limit);
      $queryBuilder->orderBy('n.created', 'DESC');

      $results = $queryBuilder->execute()->fetchAll();

      $consultations = [];
      foreach ($results as $row) {
        // Get medications and exams for this consultation
        $medications = $this->getConsultationMedications($row->nid);
        $exams = $this->getConsultationExams($row->nid);

        $consultations[] = [
          'nid' => (int) $row->nid,
          'patient_id' => (int) ($row->field_client_target_id ?? 0),
          'patient_name' => $row->patient_name ?? 'Anonyme',
          'motif' => $row->field_motif_value ?? null,
          'temperature' => isset($row->field_temperature_value) ? (float) $row->field_temperature_value : null,
          'tension_arterielle' => $row->field_tension_arterielle_value ?? null,
          'poids' => isset($row->field_poids_value) ? (float) $row->field_poids_value : null,
          'medications' => $medications,
          'exams' => $exams,
          'date' => date('Y-m-d', $row->created),
          'created' => (int) $row->created,
        ];
      }

      return new JsonResponse([
        'success' => TRUE,
        'count' => count($consultations),
        'data' => $consultations,
      ]);
    } catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'count' => 0,
        'data' => [],
      ]);
    }
  }

  /**
   * Search sales/orders by client, date, or product.
   */
  public function searchSales(Request $request)
  {
    $params = $this->getParams($request);
    $query = $params['query'] ?? '';
    $clientId = $params['client_id'] ?? null;
    $dateFrom = $params['date_from'] ?? null;
    $dateTo = $params['date_to'] ?? null;
    $limit = (int) ($params['limit'] ?? 20);
    $offset = (int) ($params['offset'] ?? 0);

    $connection = Database::getConnection();
    $queryBuilder = $connection->select('node_field_data', 'n')
      ->fields('n', ['nid', 'title', 'created'])
      ->condition('n.type', 'commande')
      ->condition('n.status', 1);

    // Join fields
    $queryBuilder->leftJoin('node__field_client', 'client', 'client.entity_id = n.nid');
    $queryBuilder->leftJoin('node__field_total_vente', 'total', 'total.entity_id = n.nid');
    $queryBuilder->leftJoin('node__field_status', 'status', 'status.entity_id = n.nid');
    $queryBuilder->leftJoin('node__field_date', 'date', 'date.entity_id = n.nid');

    $queryBuilder->fields('client', ['field_client_target_id']);
    $queryBuilder->fields('total', ['field_total_vente_value']);
    $queryBuilder->fields('status', ['field_status_value']);
    $queryBuilder->fields('date', ['field_date_value']);

    // Get client name
    $queryBuilder->leftJoin('node_field_data', 'clientNode', 'clientNode.nid = client.field_client_target_id');
    $queryBuilder->addField('clientNode', 'title', 'client_name');

    // Filters
    if (!empty($query)) {
      $group = $queryBuilder->orConditionGroup()
        ->condition('n.title', "%$query%", 'LIKE')
        ->condition('clientNode.title', "%$query%", 'LIKE');
      $queryBuilder->condition($group);
    }

    if ($clientId) {
      $queryBuilder->condition('client.field_client_target_id', $clientId);
    }

    if ($dateFrom) {
      $queryBuilder->condition('n.created', strtotime($dateFrom), '>=');
    }

    if ($dateTo) {
      $queryBuilder->condition('n.created', strtotime($dateTo) + 86400, '<');
    }

    $queryBuilder->range($offset, $limit);
    $queryBuilder->orderBy('n.created', 'DESC');

    $results = $queryBuilder->execute()->fetchAll();

    $sales = [];
    foreach ($results as $row) {
      // Get products and exams for this order
      $products = $this->getOrderProducts($row->nid);
      $exams = $this->getOrderExams($row->nid);

      $sales[] = [
        'nid' => (int) $row->nid,
        'order_number' => $row->title,
        'client_id' => (int) $row->field_client_target_id,
        'client_name' => $row->client_name,
        'total' => (float) ($row->field_total_vente_value ?? 0),
        'status' => $row->field_status_value,
        'date' => $row->field_date_value,
        'products' => $products,
        'exams' => $exams,
        'created' => (int) $row->created,
      ];
    }

    return new JsonResponse([
      'success' => TRUE,
      'count' => count($sales),
      'data' => $sales,
    ]);
  }

  /**
   * Get statistics for a specific period: today, last 7 days, or last 30 days.
   * 
   * @Query parameter:
   * - period: today, week, month (default: today)
   */
  public function searchWithPeriod(Request $request)
  {
    $connection = Database::getConnection();

    // Récupérer la période depuis la requête (GET ou POST)
    $params = $this->getParams($request);
    $period = $params['period'] ?? 'today';

    // Définir les dates en fonction de la période demandée
    switch ($period) {
      case 'week':
        $start = strtotime('-7 days midnight');
        $end = strtotime('now');
        $label = '7 derniers jours';
        break;

      case 'month':
        $start = strtotime('-30 days midnight');
        $end = strtotime('now');
        $label = '30 derniers jours';
        break;

      case 'today':
      default:
        $start = strtotime('today midnight');
        $end = $start + 86400;
        $label = 'Aujourd\'hui';
        break;
    }

    // Consultations count for the period
    $consultationsQuery = $connection->select('node_field_data', 'n')
      ->condition('n.type', 'consultations')
      ->condition('n.status', 1)
      ->condition('n.created', [$start, $end], 'BETWEEN')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Sales for the period
    $salesQuery = $connection->select('node_field_data', 'n');
    $salesQuery->leftJoin('node__field_total_vente', 'total', 'total.entity_id = n.nid');
    $salesQuery->condition('n.type', 'commande')
      ->condition('n.status', 1)
      ->condition('n.created', [$start, $end], 'BETWEEN');
    $salesQuery->addExpression('COUNT(*)', 'count');
    $salesQuery->addExpression('SUM(total.field_total_vente_value)', 'total_amount');
    $salesResult = $salesQuery->execute()->fetchObject();

    // Clients count for the period
    $clientsQuery = $connection->select('node_field_data', 'n')
      ->condition('n.type', 'client')
      ->condition('n.status', 1)
      ->condition('n.created', [$start, $end], 'BETWEEN')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Low stock items (toujours actuel, indépendant de la période)
    $lowStockQuery = $connection->select('node__field_quantite_stock', 's');
    $lowStockQuery->join('node_field_data', 'n', 'n.nid = s.entity_id');
    $lowStockQuery
      ->condition('n.status', 1) // seulement publié
      ->condition('s.field_quantite_stock_value', [1, 10], 'BETWEEN');

    $lowStockQuery->addExpression('COUNT(*)', 'count');

    $lowStockCount = $lowStockQuery->execute()->fetchField();

    // Détails pour la période demandée
    $consultRequest = new Request();
    $consultRequest->request->set('date_from', date('Y-m-d', $start));
    $consultRequest->request->set('date_to', date('Y-m-d', ($period === 'today' ? $start : $end)));
    $periodConsultations = $this->searchConsultations($consultRequest);
    $consultationsData = json_decode($periodConsultations->getContent(), TRUE);

    $salesRequest = new Request();
    $salesRequest->request->set('date_from', date('Y-m-d', $start));
    $salesRequest->request->set('date_to', date('Y-m-d', ($period === 'today' ? $start : $end)));
    $periodSales = $this->searchSales($salesRequest);
    $salesData = json_decode($periodSales->getContent(), TRUE);

    return new JsonResponse([
      'success' => TRUE,
      'period' => $label,
      'period_key' => $period,
      'date_from' => date('Y-m-d H:i:s', $start),
      'date_to' => date('Y-m-d H:i:s', $end),
      'stats' => [
        'consultations_count' => (int) $consultationsQuery,
        'sales_count' => (int) ($salesResult->count ?? 0),
        'sales_total' => (float) ($salesResult->total_amount ?? 0),
        'clients_count' => (int) $clientsQuery,
        'low_stock_items' => (int) $lowStockCount,
      ],
      'consultations' => $consultationsData['data'] ?? [],
      'sales' => $salesData['data'] ?? [],
    ]);
  }

  /**
   * RAG Query - Intelligent context builder for AI.
   */
  public function ragQuery(Request $request)
  {
    $params = json_decode($request->getContent(), TRUE) ?: [];
    $query = $params['query'] ?? '';
    $context = [];

    // Detect query intent and fetch relevant data
    $queryLower = strtolower($query);

    // Patient-related queries
    if (str_contains($queryLower, 'patient') || str_contains($queryLower, 'malade') || str_contains($queryLower, 'client')) {
      $patientRequest = new Request();
      $patientRequest->request->set('query', $query);
      $patientSearch = $this->searchPatients($patientRequest);
      $context['patients'] = json_decode($patientSearch->getContent(), TRUE)['data'] ?? [];
    }

    // Medication/stock queries
    if (
      str_contains($queryLower, 'medicament') || str_contains($queryLower, 'médicament') ||
      str_contains($queryLower, 'stock') || str_contains($queryLower, 'article')
    ) {
      $medRequest = new Request();
      $medRequest->request->set('query', $query);
      $medSearch = $this->searchMedications($medRequest);
      $context['medications'] = json_decode($medSearch->getContent(), TRUE)['data'] ?? [];
    }

    // Consultation queries
    if (str_contains($queryLower, 'consultation') || str_contains($queryLower, 'consultation')) {
      $consRequest = new Request();
      $consRequest->request->set('query', $query);
      $consSearch = $this->searchConsultations($consRequest);
      $context['consultations'] = json_decode($consSearch->getContent(), TRUE)['data'] ?? [];
    }

    // Sales queries
    if (
      str_contains($queryLower, 'vente') || str_contains($queryLower, 'commande') ||
      str_contains($queryLower, 'chiffre') || str_contains($queryLower, 'total')
    ) {
      $salesRequest = new Request();
      $salesRequest->request->set('query', $query);
      $salesSearch = $this->searchSales($salesRequest);
      $context['sales'] = json_decode($salesSearch->getContent(), TRUE)['data'] ?? [];
    }

    // Today queries
    if (
      str_contains($queryLower, "aujourd'hui") || str_contains($queryLower, 'today') ||
      str_contains($queryLower, 'jour')
    ) {
      $todayData = $this->searchWithPeriod($request);
      $todayContext = json_decode($todayData->getContent(), TRUE);
      $context['today'] = $todayContext;
    }

    return new JsonResponse([
      'success' => TRUE,
      'query' => $query,
      'context' => $context,
    ]);
  }

  /**
   * Semantic search - Full-text search across all entities.
   */
  public function semanticSearch(Request $request)
  {
    $params = json_decode($request->getContent(), TRUE) ?: [];
    $query = $params['query'] ?? '';
    $types = $params['types'] ?? ['patient', 'medication', 'consultation', 'sale'];
    $limit = (int) ($params['limit'] ?? 10);

    $results = [];

    if (in_array('patient', $types)) {
      $patientRequest = new Request();
      $patientRequest->request->set('query', $query);
      $patientRequest->request->set('limit', $limit);
      $patientResults = $this->searchPatients($patientRequest);
      $results['patients'] = json_decode($patientResults->getContent(), TRUE)['data'] ?? [];
    }

    if (in_array('medication', $types)) {
      $medRequest = new Request();
      $medRequest->request->set('query', $query);
      $medRequest->request->set('limit', $limit);
      $medResults = $this->searchMedications($medRequest);
      $results['medications'] = json_decode($medResults->getContent(), TRUE)['data'] ?? [];
    }

    if (in_array('consultation', $types)) {
      $consRequest = new Request();
      $consRequest->request->set('query', $query);
      $consRequest->request->set('limit', $limit);
      $consResults = $this->searchConsultations($consRequest);
      $results['consultations'] = json_decode($consResults->getContent(), TRUE)['data'] ?? [];
    }

    if (in_array('sale', $types)) {
      $saleRequest = new Request();
      $saleRequest->request->set('query', $query);
      $saleRequest->request->set('limit', $limit);
      $saleResults = $this->searchSales($saleRequest);
      $results['sales'] = json_decode($saleResults->getContent(), TRUE)['data'] ?? [];
    }

    return new JsonResponse([
      'success' => TRUE,
      'query' => $query,
      'results' => $results,
    ]);
  }

  /**
   * Helper: Get medications for a consultation.
   */
  private function getConsultationMedications($consultationId)
  {
    try {
      $connection = Database::getConnection();
      $query = $connection->select('node__field_medicaments', 'm')
        ->fields('m', ['field_medicaments_target_id'])
        ->condition('m.entity_id', $consultationId);

      $query->leftJoin('paragraph__field_articles', 'article', 'article.entity_id = m.field_medicaments_target_id');
      $query->leftJoin('paragraph__field_quantite', 'qte', 'qte.entity_id = m.field_medicaments_target_id');
      $query->leftJoin('paragraph__field_description', 'desc', 'desc.entity_id = m.field_medicaments_target_id');
      $query->leftJoin('paragraph__field_prix', 'prix', 'prix.entity_id = m.field_medicaments_target_id');

      // Get article title
      $query->leftJoin('node_field_data', 'articleNode', 'articleNode.nid = article.field_articles_target_id');
      $query->addField('articleNode', 'title', 'article_title');
      $query->addField('qte', 'field_quantite_value', 'quantity');
      $query->addField('desc', 'field_description_value', 'description');
      $query->addField('prix', 'field_prix_value', 'price');

      $results = $query->execute()->fetchAll();

      return array_map(function ($row) {
        return [
          'title' => $row->article_title ?? '',
          'quantity' => (int) ($row->quantity ?? 0),
          'description' => $row->description ?? '',
          'price' => (float) ($row->price ?? 0),
        ];
      }, $results);
    } catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Helper: Get exams for a consultation.
   */
  private function getConsultationExams($consultationId)
  {
    try {
      $connection = Database::getConnection();
      $query = $connection->select('node__field_examens', 'e')
        ->fields('e', ['field_examens_target_id'])
        ->condition('e.entity_id', $consultationId);

      $query->leftJoin('paragraph__field_examen', 'exam', 'exam.entity_id = e.field_examens_target_id');
      $query->leftJoin('paragraph__field_description', 'desc', 'desc.entity_id = e.field_examens_target_id');
      $query->leftJoin('paragraph__field_prix', 'prix', 'prix.entity_id = e.field_examens_target_id');

      // Get exam title
      $query->leftJoin('node_field_data', 'examNode', 'examNode.nid = exam.field_examen_target_id');
      $query->addField('examNode', 'title', 'exam_title');
      $query->addField('desc', 'field_description_value', 'description');
      $query->addField('prix', 'field_prix_value', 'price');

      $results = $query->execute()->fetchAll();

      return array_map(function ($row) {
        return [
          'title' => $row->exam_title ?? '',
          'description' => $row->description ?? '',
          'price' => (float) ($row->price ?? 0),
        ];
      }, $results);
    } catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Helper: Get products for an order.
   */
  private function getOrderProducts($orderId)
  {
    $connection = Database::getConnection();
    $query = $connection->select('node__field_articles', 'a')
      ->condition('a.entity_id', $orderId);

    $query->leftJoin('paragraph__field_article', 'article', 'article.entity_id = a.field_articles_target_id');
    $query->leftJoin('paragraph__field_quantite', 'qte', 'qte.entity_id = a.field_articles_target_id');
    $query->leftJoin('paragraph__field_prix_d_achat', 'prix', 'prix.entity_id = a.field_articles_target_id');

    // Get article title
    $query->leftJoin('node_field_data', 'articleNode', 'articleNode.nid = article.field_article_target_id');
    $query->addField('articleNode', 'title', 'article_title');
    $query->addField('qte', 'field_quantite_value', 'quantity');
    $query->addField('prix', 'field_prix_d_achat_value', 'price');

    $results = $query->execute()->fetchAll();

    return array_map(function ($row) {
      return [
        'title' => $row->article_title,
        'quantity' => (int) ($row->quantity ?? 0),
        'price' => (float) ($row->price ?? 0),
      ];
    }, $results);
  }

  /**
   * Helper: Get exams for an order.
   */
  private function getOrderExams($orderId)
  {
    $connection = Database::getConnection();
    $query = $connection->select('node__field_examens_order', 'e')
      ->condition('e.entity_id', $orderId);

    $query->leftJoin('paragraph__field_examen', 'exam', 'exam.entity_id = e.field_examens_order_target_id');

    // Get exam title
    $query->leftJoin('node_field_data', 'examNode', 'examNode.nid = exam.field_examen_target_id');
    $query->addField('examNode', 'title', 'exam_title');

    $results = $query->execute()->fetchAll();

    return array_map(function ($row) {
      return ['title' => $row->exam_title];
    }, $results);
  }

  /**
   * Helper: Get params from GET or POST.
   */
  private function getParams(Request $request)
  {
    if ($request->getMethod() === 'POST') {
      return json_decode($request->getContent(), TRUE) ?: [];
    }
    return $request->query->all();
  }
}
