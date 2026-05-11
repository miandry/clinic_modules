<?php

namespace Drupal\mz_api_security\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure MZ API Security settings.
 */
class MzApiSecuritySettingsForm extends ConfigFormBase
{

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames()
    {
        return ['mz_api_security.settings'];
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'mz_api_security_settings';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $config = $this->config('mz_api_security.settings');

        // Récupérer l'URL du site courant
        $siteUrl = $this->config('system.site')->get('url');

        // Si l'URL du site n'est pas configurée, essayer de la construire
        if (empty($siteUrl)) {
            $request = \Drupal::request();
            $siteUrl = $request->getSchemeAndHttpHost();
        }

        $form['allowed_domain'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Domaine autorisé'),
            '#description' => $this->t('Entrez le domaine autorisé à accéder à l\'API. Par défaut : @site_url', [
                '@site_url' => $siteUrl
            ]),
            '#default_value' => $config->get('allowed_domain') ?: $siteUrl,
            '#required' => TRUE,
            '#size' => 60,
            '#maxlength' => 255,
            '#placeholder' => 'https://exemple.com',
        ];

        $form['api_path_prefix'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Préfixe des routes API'),
            '#description' => $this->t('Le préfixe des routes API à protéger (ex: /api/)'),
            '#default_value' => $config->get('api_path_prefix') ?: '/api/',
            '#required' => TRUE,
            '#size' => 20,
            '#maxlength' => 50,
        ];

        $form['enable_logging'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Activer le logging'),
            '#description' => $this->t('Journaliser les tentatives d\'accès non autorisés'),
            '#default_value' => $config->get('enable_logging') ?: TRUE,
        ];

        $form['allowed_ips'] = [
            '#type' => 'textarea',
            '#title' => $this->t('IPs autorisées (optionnel)'),
            '#description' => $this->t('Une IP par ligne. Ces IPs auront accès même sans le bon domaine. Utile pour les tests. Exemples :<br>127.0.0.1<br>::1<br>192.168.1.100'),
            '#default_value' => $config->get('allowed_ips') ?: '',
            '#rows' => 5,
            '#cols' => 50,
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateForm($form, $form_state);

        // Validation du domaine autorisé
        $allowedDomain = $form_state->getValue('allowed_domain');

        // Vérifier que le champ n'est pas vide
        if (empty($allowedDomain)) {
            $form_state->setErrorByName('allowed_domain', $this->t('Le domaine autorisé ne peut pas être vide.'));
            return;
        }

        // Nettoyer l'URL (enlever les espaces et les slashs à la fin)
        $allowedDomain = trim($allowedDomain);
        $allowedDomain = rtrim($allowedDomain, '/');
        $form_state->setValue('allowed_domain', $allowedDomain);

        // Vérifier que c'est une URL valide
        if (!filter_var($allowedDomain, FILTER_VALIDATE_URL)) {
            $form_state->setErrorByName('allowed_domain', $this->t('Veuillez entrer une URL valide. Exemples : http://exemple.com, https://exemple.com'));
            return;
        }

        // Vérifier que l'URL a un schéma (http/https)
        $parsedUrl = parse_url($allowedDomain);
        if (!isset($parsedUrl['scheme'])) {
            $form_state->setErrorByName('allowed_domain', $this->t('L\'URL doit commencer par http:// ou https://'));
            return;
        }

        // Vérifier que le schéma est valide
        $scheme = $parsedUrl['scheme'];
        if (!in_array($scheme, ['http', 'https'])) {
            $form_state->setErrorByName('allowed_domain', $this->t('Le schéma de l\'URL doit être http ou https. Actuel : @scheme', ['@scheme' => $scheme]));
            return;
        }

        // Vérifier que le domaine n'est pas vide
        if (!isset($parsedUrl['host']) || empty($parsedUrl['host'])) {
            $form_state->setErrorByName('allowed_domain', $this->t('L\'URL doit contenir un nom de domaine valide.'));
            return;
        }

        // Validation du préfixe des routes API
        $apiPathPrefix = $form_state->getValue('api_path_prefix');

        // Vérifier que le préfixe commence par /
        if (strpos($apiPathPrefix, '/') !== 0) {
            $form_state->setErrorByName('api_path_prefix', $this->t('Le préfixe des routes API doit commencer par un slash (/). Exemple : /api/'));
            return;
        }

        // Vérifier que le préfixe se termine par /
        if (substr($apiPathPrefix, -1) !== '/') {
            $form_state->setErrorByName('api_path_prefix', $this->t('Le préfixe des routes API doit se terminer par un slash (/). Exemple : /api/'));
            return;
        }

        // Vérifier que le préfixe ne contient pas de caractères dangereux
        if (!preg_match('#^/[a-zA-Z0-9/_\-]+$#', $apiPathPrefix)) {
            $form_state->setErrorByName('api_path_prefix', $this->t('Le préfixe des routes API ne peut contenir que des lettres, chiffres, slashs, tirets et underscores.'));
            return;
        }

        // Validation des IPs autorisées
        $allowedIps = $form_state->getValue('allowed_ips');
        if (!empty($allowedIps)) {
            $ips = array_filter(array_map('trim', explode("\n", $allowedIps)));

            foreach ($ips as $ip) {
                // Vérifier si c'est une IP valide (IPv4 ou IPv6)
                if (!filter_var($ip, FILTER_VALIDATE_IP) && !$this->isValidCidr($ip)) {
                    $form_state->setErrorByName('allowed_ips', $this->t('L\'IP @ip n\'est pas valide. Utilisez une IP valide (ex: 192.168.1.1) ou un CIDR (ex: 192.168.1.0/24)', ['@ip' => $ip]));
                    break;
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $this->config('mz_api_security.settings')
            ->set('allowed_domain', $form_state->getValue('allowed_domain'))
            ->set('api_path_prefix', $form_state->getValue('api_path_prefix'))
            ->set('enable_logging', $form_state->getValue('enable_logging'))
            ->set('allowed_ips', $form_state->getValue('allowed_ips'))
            ->save();

        parent::submitForm($form, $form_state);

        // Message de confirmation
        \Drupal::messenger()->addMessage($this->t('Les paramètres ont été sauvegardés avec succès.'));

        // Message d'information supplémentaire si le domaine a changé
        $oldDomain = $this->config('mz_api_security.settings')->get('allowed_domain');
        $newDomain = $form_state->getValue('allowed_domain');

        if ($oldDomain && $oldDomain !== $newDomain) {
            \Drupal::messenger()->addWarning($this->t('Attention : Le domaine autorisé a été modifié. Assurez-vous que votre frontend utilise bien le nouveau domaine : @domain', [
                '@domain' => $newDomain
            ]));
        }
    }

    /**
     * Vérifie si une chaîne est un CIDR valide (ex: 192.168.1.0/24)
     * 
     * @param string $cidr
     * @return bool
     */
    private function isValidCidr($cidr)
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }

        $parts = explode('/', $cidr);
        if (count($parts) !== 2) {
            return false;
        }

        $ip = $parts[0];
        $mask = $parts[1];

        // Vérifier l'IP
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Vérifier le masque
        if (!is_numeric($mask) || $mask < 0 || $mask > 32) {
            return false;
        }

        return true;
    }
}
