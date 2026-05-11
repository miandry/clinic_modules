<?php

namespace Drupal\mz_clinic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class ClinicController.
 */
class ClinicController extends ControllerBase
{
    /**
     * Save complete consultation with all related entities.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function saveConsultation(Request $request)
    {
        $method = $request->getMethod();

        if ($method == "POST") {
            // Récupérer le token depuis le cookie HTTP-Only
            $token = $request->cookies->get('auth_token');

            if (!$token) {
                return new JsonResponse([
                    'message' => 'Non authentifié. Veuillez vous connecter.',
                    'status' => 'error'
                ], 401);
            }

            $content = $request->getContent();

            if (!empty($content)) {
                $data = json_decode($content, TRUE);
                $crudService = \Drupal::service('api.crud');

                // Valider le token
                $user = $crudService->validateBearerToken($token);

                if ($user) {
                    try {
                        $results = [];
                        
                        // 1. Créer/Mettre à jour la consultation
                        $consultationData = $data['consultation'] ?? [];
                        if (!empty($consultationData)) {
                            // Définir l'auteur automatiquement pour les nodes
                            if (!isset($consultationData['uid'])) {
                                $consultationData['uid'] = $user->id();
                            }

                            $entity_type = $consultationData["entity_type"] ?? 'node';
                            $bundle = $consultationData["bundle"] ?? 'consultations';
                            
                            unset($consultationData["entity_type"]);
                            unset($consultationData["bundle"]);

                            $consultation = \Drupal::service('crud')->save($entity_type, $bundle, $consultationData);
                            
                            if (is_object($consultation)) {
                                $consultationId = $consultation->id();
                                $results['consultation'] = [
                                    'id' => $consultationId,
                                    'status' => true
                                ];
                            } else {
                                throw new \Exception("Erreur lors de la sauvegarde de la consultation");
                            }
                        }

                        // 2. Mettre à jour le rendez-vous si fourni
                        if (!empty($data['appointment']) && !empty($consultationId)) {
                            $appointmentData = $data['appointment'];
                            $appointmentData['field_app_consultation'] = $consultationId;
                            
                            $entity_type = $appointmentData["entity_type"] ?? 'node';
                            $bundle = $appointmentData["bundle"] ?? 'rendez_vous_medical';
                            
                            unset($appointmentData["entity_type"]);
                            unset($appointmentData["bundle"]);

                            $appointment = \Drupal::service('crud')->save($entity_type, $bundle, $appointmentData);
                            
                            if (is_object($appointment)) {
                                $results['appointment'] = [
                                    'id' => $appointment->id(),
                                    'status' => true
                                ];
                            }
                        }

                        // 3. Mettre à jour le patient si fourni
                        if (!empty($data['patient']) && !empty($consultationId)) {
                            $patientData = $data['patient'];
                            $patientData['field_consultation'] = $consultationId;
                            
                            $entity_type = $patientData["entity_type"] ?? 'node';
                            $bundle = $patientData["bundle"] ?? 'client';
                            
                            unset($patientData["entity_type"]);
                            unset($patientData["bundle"]);

                            $patient = \Drupal::service('crud')->save($entity_type, $bundle, $patientData);
                            
                            if (is_object($patient)) {
                                $results['patient'] = [
                                    'id' => $patient->id(),
                                    'status' => true
                                ];
                            }
                        }

                        // 4. Créer la commande si fournie
                        if (!empty($data['order']) && !empty($consultationId)) {
                            $orderData = $data['order'];
                            $orderData['field_consultation_nid'] = $consultationId;
                            
                            $entity_type = $orderData["entity_type"] ?? 'node';
                            $bundle = $orderData["bundle"] ?? 'commande';
                            
                            unset($orderData["entity_type"]);
                            unset($orderData["bundle"]);

                            $order = \Drupal::service('crud')->save($entity_type, $bundle, $orderData);
                            
                            if (is_object($order)) {
                                $orderId = $order->id();
                                $results['order'] = [
                                    'id' => $orderId,
                                    'status' => true
                                ];

                                // 4.5. Mettre à jour la consultation avec la référence à la commande
                                $consultationUpdateData = [
                                    'nid' => $consultationId,
                                    'field_commande_cons' => $orderId,
                                ];
                                
                                \Drupal::service('crud')->save('node', 'consultations', $consultationUpdateData);
                                
                                $results['consultation_update'] = [
                                    'id' => $consultationId,
                                    'field_commande_cons' => $orderId,
                                    'status' => true
                                ];

                                // 5. Créer la facture si fournie
                                if (!empty($data['invoice'])) {
                                    $invoiceData = $data['invoice'];
                                    $invoiceData['field_commande'] = $orderId;
                                    
                                    $entity_type = $invoiceData["entity_type"] ?? 'node';
                                    $bundle = $invoiceData["bundle"] ?? 'facture';
                                    
                                    unset($invoiceData["entity_type"]);
                                    unset($invoiceData["bundle"]);

                                    $invoice = \Drupal::service('crud')->save($entity_type, $bundle, $invoiceData);
                                    
                                    if (is_object($invoice)) {
                                        $invoiceId = $invoice->id();
                                        $results['invoice'] = [
                                            'id' => $invoiceId,
                                            'status' => true
                                        ];

                                        // 6. Mettre à jour la commande avec la facture
                                        $orderUpdateData = [
                                            'nid' => $orderId,
                                            'field_facture' => $invoiceId,
                                        ];

                                        \Drupal::service('crud')->save('node', 'commande', $orderUpdateData);
                                        
                                        $results['order_update'] = [
                                            'id' => $orderId,
                                            'status' => true
                                        ];
                                    }
                                }
                            }
                        }

                        // 7. Nettoyage des anciennes données en mode édition
                        if (!empty($data['cleanup']) && !empty($consultationId)) {
                            $cleanupData = $data['cleanup'];
                            
                            // Supprimer ancienne commande si existe
                            if (!empty($cleanupData['old_order_id'])) {
                                \Drupal::service('crud')->delete('node', 'commande', $cleanupData['old_order_id']);
                                $results['cleanup']['old_order_deleted'] = true;
                            }
                            
                            // Supprimer ancienne consultation si en mode édition
                            if (!empty($cleanupData['old_consultation_id']) && $cleanupData['old_consultation_id'] != $consultationId) {
                                \Drupal::service('crud')->delete('node', 'consultations', $cleanupData['old_consultation_id']);
                                $results['cleanup']['old_consultation_deleted'] = true;
                            }
                        }

                        return new JsonResponse([
                            'status' => true,
                            'message' => 'Consultation et entités associées sauvegardées avec succès',
                            'results' => $results,
                            'consultation_id' => $consultationId ?? null
                        ], 200);

                    } catch (\Exception $e) {
                        return new JsonResponse([
                            'status' => false,
                            'message' => 'Erreur lors de la sauvegarde: ' . $e->getMessage()
                        ], 500);
                    }
                } else {
                    // Token invalide, supprimer le cookie
                    $message = "Session expirée. Veuillez vous reconnecter.";
                    $response = new JsonResponse([
                        'message' => $message,
                        'status' => 'error'
                    ], 401);

                    $response->headers->clearCookie('auth_token', '/');
                    return $response;
                }
            } else {
                return new JsonResponse([
                    'message' => "Données non trouvées",
                    'status' => 'error'
                ], 400);
            }
        } else {
            return new JsonResponse([
                'message' => "Méthode non autorisée.",
                'status' => 'error'
            ], 405);
        }
    }

    /**
     * Cancel consultation with its related order.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function cancelConsultation(Request $request)
    {
        $method = $request->getMethod();

        if ($method == "POST") {
            // Récupérer le token depuis le cookie HTTP-Only
            $token = $request->cookies->get('auth_token');

            if (!$token) {
                return new JsonResponse([
                    'message' => 'Non authentifié. Veuillez vous connecter.',
                    'status' => 'error'
                ], 401);
            }

            $content = $request->getContent();

            if (!empty($content)) {
                $data = json_decode($content, TRUE);
                $crudService = \Drupal::service('api.crud');

                // Valider le token
                $user = $crudService->validateBearerToken($token);

                if ($user) {
                    try {
                        $results = [];
                        
                        // 1. Annuler la consultation
                        $consultationId = $data['consultation_id'] ?? null;
                        if (!empty($consultationId)) {
                            $consultationData = [
                                'nid' => $consultationId,
                                'field_consultation_status' => 'cancelled'
                            ];
                            
                            \Drupal::service('crud')->save('node', 'consultations', $consultationData);
                            
                            $results['consultation'] = [
                                'id' => $consultationId,
                                'status' => 'cancelled',
                                'success' => true
                            ];
                        }

                        // 2. Annuler la commande associée si elle existe
                        if (!empty($data['order_id'])) {
                            $orderId = $data['order_id'];
                            $orderData = [
                                'nid' => $orderId,
                                'field_status' => 'cancel'
                            ];
                            
                            \Drupal::service('crud')->save('node', 'commande', $orderData);
                            
                            $results['order'] = [
                                'id' => $orderId,
                                'status' => 'cancel',
                                'success' => true
                            ];
                        }

                        return new JsonResponse([
                            'status' => true,
                            'message' => 'Consultation et commande annulées avec succès',
                            'results' => $results
                        ], 200);

                    } catch (\Exception $e) {
                        return new JsonResponse([
                            'status' => false,
                            'message' => 'Erreur lors de l\'annulation: ' . $e->getMessage()
                        ], 500);
                    }
                } else {
                    // Token invalide, supprimer le cookie
                    $message = "Session expirée. Veuillez vous reconnecter.";
                    $response = new JsonResponse([
                        'message' => $message,
                        'status' => 'error'
                    ], 401);

                    $response->headers->clearCookie('auth_token', '/');
                    return $response;
                }
            } else {
                return new JsonResponse([
                    'message' => "Données non trouvées",
                    'status' => 'error'
                ], 400);
            }
        } else {
            return new JsonResponse([
                'message' => "Méthode non autorisée.",
                'status' => 'error'
            ], 405);
        }
    }

    /**
     * Create order and invoice together in a single request.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createOrderWithInvoice(Request $request)
    {
        $method = $request->getMethod();

        if ($method == "POST") {
            // Récupérer le token depuis le cookie HTTP-Only
            $token = $request->cookies->get('auth_token');

            if (!$token) {
                return new JsonResponse([
                    'message' => 'Non authentifié. Veuillez vous connecter.',
                    'status' => 'error'
                ], 401);
            }

            $content = $request->getContent();

            if (!empty($content)) {
                $data = json_decode($content, TRUE);
                $crudService = \Drupal::service('api.crud');

                // Valider le token
                $user = $crudService->validateBearerToken($token);

                if ($user) {
                    try {
                        $results = [];
                        
                        // 1. Créer la commande
                        $orderData = $data['order'] ?? [];
                        $order = null;
                        $orderId = null;
                        
                        if (!empty($orderData)) {
                            $entity_type = $orderData["entity_type"] ?? 'node';
                            $bundle = $orderData["bundle"] ?? 'commande';
                            
                            unset($orderData["entity_type"]);
                            unset($orderData["bundle"]);

                            $order = \Drupal::service('crud')->save($entity_type, $bundle, $orderData);
                            
                            if (is_object($order)) {
                                $orderId = $order->id();
                                $results['order'] = [
                                    'id' => $orderId,
                                    'status' => true
                                ];
                            } else {
                                throw new \Exception("Erreur lors de la sauvegarde de la commande");
                            }
                        }

                        // 2. Créer la facture avec référence à la commande
                        $invoiceData = $data['invoice'] ?? [];
                        $invoice = null;
                        $invoiceId = null;
                        
                        if (!empty($invoiceData) && !empty($orderId)) {
                            $invoiceData['field_commande'] = $orderId;
                            
                            $entity_type = $invoiceData["entity_type"] ?? 'node';
                            $bundle = $invoiceData["bundle"] ?? 'facture';
                            
                            unset($invoiceData["entity_type"]);
                            unset($invoiceData["bundle"]);

                            $invoice = \Drupal::service('crud')->save($entity_type, $bundle, $invoiceData);
                            
                            if (is_object($invoice)) {
                                $invoiceId = $invoice->id();
                                $results['invoice'] = [
                                    'id' => $invoiceId,
                                    'status' => true
                                ];
                            } else {
                                throw new \Exception("Erreur lors de la sauvegarde de la facture");
                            }
                        }

                        // 3. Mettre à jour la commande avec la référence à la facture
                        if (!empty($orderId) && !empty($invoiceId)) {
                            $orderUpdateData = [
                                'nid' => $orderId,
                                'field_facture' => $invoiceId,
                            ];

                            \Drupal::service('crud')->save('node', 'commande', $orderUpdateData);
                            
                            $results['order_update'] = [
                                'id' => $orderId,
                                'field_facture' => $invoiceId,
                                'status' => true
                            ];
                        }

                        return new JsonResponse([
                            'status' => true,
                            'message' => 'Commande et facture créées avec succès',
                            'results' => $results,
                            'order_id' => $orderId,
                            'invoice_id' => $invoiceId
                        ], 200);

                    } catch (\Exception $e) {
                        return new JsonResponse([
                            'status' => false,
                            'message' => 'Erreur lors de la création: ' . $e->getMessage()
                        ], 500);
                    }
                } else {
                    // Token invalide, supprimer le cookie
                    $response = new JsonResponse([
                        'message' => 'Session expirée. Veuillez vous reconnecter.',
                        'status' => 'error'
                    ], 401);

                    $response->headers->clearCookie('auth_token', '/');
                    return $response;
                }
            } else {
                return new JsonResponse([
                    'message' => "Données non trouvées",
                    'status' => 'error'
                ], 400);
            }
        } else {
            return new JsonResponse([
                'message' => "Méthode non autorisée.",
                'status' => 'error'
            ], 405);
        }
    }
}
