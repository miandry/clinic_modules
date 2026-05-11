<?php

namespace Drupal\mz_api_security\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Class MzApiSecuritySubscriber
 * 
 * @package Drupal\mz_api_security\EventSubscriber
 */
class MzApiSecuritySubscriber implements EventSubscriberInterface
{

    /**
     * Configuration factory.
     *
     * @var \Drupal\Core\Config\ConfigFactoryInterface
     */
    protected $configFactory;

    /**
     * Configuration du module.
     *
     * @var \Drupal\Core\Config\ImmutableConfig
     */
    protected $config;

    /**
     * Constructor.
     *
     * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
     */
    public function __construct(ConfigFactoryInterface $config_factory)
    {
        $this->configFactory = $config_factory;
        $this->config = $this->configFactory->get('mz_api_security.settings');
    }

    /**
     * Récupère le domaine autorisé (configuré ou URL du site par défaut)
     * 
     * @return string
     */
    protected function getAllowedDomain()
    {
        $allowedDomain = $this->config->get('allowed_domain');

        // Si aucun domaine n'est configuré, utiliser l'URL du site
        if (empty($allowedDomain)) {
            $siteConfig = $this->configFactory->get('system.site');
            $allowedDomain = $siteConfig->get('url');

            // Si l'URL du site n'est pas configurée, la construire
            if (empty($allowedDomain)) {
                $request = \Drupal::request();
                $allowedDomain = $request->getSchemeAndHttpHost();
            }
        }

        return $allowedDomain;
    }

    /**
     * Vérifie que les requêtes API proviennent du domaine autorisé
     * 
     * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
     */
    public function checkApiDomain(RequestEvent $event)
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Récupérer la configuration
        $allowedDomain = $this->getAllowedDomain();
        $apiPathPrefix = $this->config->get('api_path_prefix') ?: '/api/';
        $enableLogging = $this->config->get('enable_logging') ?: TRUE;
        $allowedIps = $this->config->get('allowed_ips') ?: '';

        // Convertir les IPs en tableau
        $allowedIpsArray = array_filter(array_map('trim', explode("\n", $allowedIps)));

        // Vérifier si la requête concerne une route API
        if (strpos($path, $apiPathPrefix) === 0) {

            // Récupérer les headers d'origine
            $origin = $request->headers->get('origin');
            $referer = $request->headers->get('referer');
            $clientIp = $request->getClientIp();

            // Le domaine propre du serveur Drupal (toujours autorisé)
            $serverHost = $request->getSchemeAndHttpHost();

            $isAuthorized = FALSE;

            // Autoriser les IPs locales (localhost / 127.0.0.1 / ::1)
            $localhostIps = ['127.0.0.1', '::1', '0:0:0:0:0:0:0:1'];
            if (in_array($clientIp, $localhostIps)) {
                $isAuthorized = TRUE;
            }

            // Vérifier si l'IP est dans la liste autorisée configurée
            if (!$isAuthorized && !empty($allowedIpsArray) && in_array($clientIp, $allowedIpsArray)) {
                $isAuthorized = TRUE;
                if ($enableLogging) {
                    \Drupal::logger('mz_api_security')->notice('API access granted from authorized IP: @ip', [
                        '@ip' => $clientIp
                    ]);
                }
            }

            // Vérifier l'origine contre le domaine configuré ET le domaine propre du serveur
            if (!$isAuthorized && $origin) {
                $allowedHosts = [
                    parse_url($allowedDomain, PHP_URL_HOST),
                    parse_url($serverHost, PHP_URL_HOST),
                ];
                $originHost = parse_url($origin, PHP_URL_HOST);
                if (in_array($originHost, array_filter($allowedHosts))) {
                    $isAuthorized = TRUE;
                }
            }

            // Vérifier le referer si pas d'origine
            if (!$isAuthorized && $referer) {
                $refererHost = parse_url($referer, PHP_URL_HOST);
                $allowedHosts = [
                    parse_url($allowedDomain, PHP_URL_HOST),
                    parse_url($serverHost, PHP_URL_HOST),
                ];
                if (in_array($refererHost, array_filter($allowedHosts))) {
                    $isAuthorized = TRUE;
                }
            }

            // Autoriser les requêtes sans Origin ni Referer (appels serveur-à-serveur)
            if (!$isAuthorized && !$origin && !$referer) {
                $isAuthorized = TRUE;
            }

            // Log des tentatives non autorisées
            if (!$isAuthorized && $enableLogging) {
                \Drupal::logger('mz_api_security')->warning('Unauthorized API access attempt - Path: @path, Origin: @origin, Referer: @referer, IP: @ip, Allowed domain: @domain', [
                    '@path' => $path,
                    '@origin' => $origin ?: 'none',
                    '@referer' => $referer ?: 'none',
                    '@ip' => $clientIp,
                    '@domain' => $allowedDomain
                ]);
            }

            // Bloquer l'accès si non autorisé
            if (!$isAuthorized) {
                $event->setResponse(new JsonResponse([
                    'error' => 'Accès non autorisé',
                    'message' => 'Accès non autorisé',
                    'code' => 403
                ], 403));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['checkApiDomain', 100],
        ];
    }
}
