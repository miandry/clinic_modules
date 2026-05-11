<?php

namespace Drupal\mz_clinic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Handles all authentication and CRUD API endpoints.
 */
class ApiController extends ControllerBase
{

    /**
     * Cookie lifetime: 2 months in seconds.
     */
    const COOKIE_LIFETIME = 5259488;

    // ─── Auth ────────────────────────────────────────────────────────────────

    /**
     * POST /api/crud/login
     */
    public function login(Request $request)
    {
        if ($request->getMethod() !== 'POST') {
            return new JsonResponse(['status' => false, 'message' => 'Méthode non autorisée. Utilisez POST'], 405);
        }

        $data = json_decode($request->getContent(), TRUE);

        if (empty($data['name']) || empty($data['password'])) {
            return new JsonResponse(['status' => false, 'message' => 'Nom d\'utilisateur et mot de passe requis'], 400);
        }

        $user = user_load_by_name($data['name']);

        if (!is_object($user)) {
            return new JsonResponse(['status' => false, 'message' => 'Utilisateur non trouvé'], 404);
        }

        $password_hasher = \Drupal::service('password');

        if (!$password_hasher->check($data['password'], $user->getPassword())) {
            return new JsonResponse(['status' => false, 'message' => 'Mot de passe incorrect'], 401);
        }

        $token = \Drupal::service('clinic.api')->generateBearerToken($user, 60);

        $response = new JsonResponse([
            'status' => true,
            'message' => 'Connexion réussie',
            'user' => [
                'id'    => $user->id(),
                'name'  => $user->getAccountName(),
                'mail'  => $user->getEmail(),
                'roles' => $user->getRoles(),
            ],
        ]);

        $response->headers->setCookie(new Cookie(
            'auth_token', $token, time() + self::COOKIE_LIFETIME,
            '/', null, false, true, false, 'Lax'
        ));

        return $response;
    }

    /**
     * POST /api/crud/logout
     */
    public function logout(Request $request)
    {
        $token = $request->cookies->get('auth_token');
        if ($token) {
            \Drupal::service('clinic.api')->invalidateBearerToken($token);
        }

        $response = new JsonResponse(['status' => true, 'message' => 'Déconnexion réussie']);
        $response->headers->clearCookie('auth_token', '/');
        return $response;
    }

    /**
     * GET /api/crud/check-auth
     */
    public function checkAuth(Request $request)
    {
        $token = $request->cookies->get('auth_token');

        if (!$token) {
            return new JsonResponse(['authenticated' => false, 'message' => 'Non authentifié'], 401);
        }

        $user = \Drupal::service('clinic.api')->validateBearerToken($token);

        if ($user) {
            return new JsonResponse([
                'authenticated' => true,
                'user' => [
                    'id'    => $user->id(),
                    'name'  => $user->getAccountName(),
                    'mail'  => $user->getEmail(),
                    'roles' => $user->getRoles(),
                ],
            ]);
        }

        $response = new JsonResponse(['authenticated' => false, 'message' => 'Session expirée'], 401);
        $response->headers->clearCookie('auth_token', '/');
        return $response;
    }

    // ─── User management ─────────────────────────────────────────────────────

    /**
     * POST /api/crud/create_user
     */
    public function createUser(Request $request)
    {
        [$service, $current_user, $error] = $this->requireAuth($request);
        if ($error) return $error;

        $allowed_roles = ['administrator', 'webmaster'];
        if (!array_intersect($allowed_roles, $current_user->getRoles())) {
            return new JsonResponse(['status' => false, 'error' => 'Accès non autorisé'], 403);
        }

        $data = json_decode($request->getContent(), TRUE);

        if (empty($data['name']) || empty($data['pass'])) {
            return new JsonResponse(['status' => false, 'error' => 'Données invalides'], 400);
        }

        if ($service->isUserNameExist($data['name'])) {
            return new JsonResponse(['status' => false, 'error' => 'Username existe déjà'], 400);
        }

        $user = User::create([
            'name'   => $data['name'],
            'mail'   => $data['mail'] ?? '',
            'status' => 1,
        ]);
        $user->setPassword($data['pass']);

        if (!empty($data['roles']) && is_array($data['roles'])) {
            foreach ($data['roles'] as $role) {
                $user->addRole($role);
            }
        }

        $user->save();

        return new JsonResponse([
            'status' => true,
            'user'   => [
                'id'      => $user->id(),
                'name'    => $user->getAccountName(),
                'mail'    => $user->getEmail(),
                'roles'   => $user->getRoles(),
                'created' => $user->getCreatedTime(),
            ],
        ], 201);
    }

    /**
     * POST /api/crud/user_edit
     */
    public function userEdit(Request $request)
    {
        [$service, $current_user, $error] = $this->requireAuth($request);
        if ($error) return $error;

        $data = json_decode($request->getContent(), TRUE);

        if (empty($data['uid'])) {
            return new JsonResponse(['status' => false, 'error' => 'UID manquant'], 400);
        }

        $allowed_roles = ['administrator', 'webmaster'];
        $is_admin = (bool) array_intersect($allowed_roles, $current_user->getRoles());

        if ($data['uid'] != $current_user->id() && !$is_admin) {
            return new JsonResponse(['status' => false, 'error' => 'Vous ne pouvez modifier que votre propre compte'], 403);
        }

        $user = User::load($data['uid']);
        if (!is_object($user)) {
            return new JsonResponse(['status' => false, 'error' => 'Utilisateur introuvable'], 404);
        }

        if (!empty($data['name']) && $data['name'] !== $user->getAccountName()) {
            if ($service->isUserNameExist($data['name'])) {
                return new JsonResponse(['status' => false, 'error' => 'Le nom d\'utilisateur est déjà pris'], 400);
            }
            $user->setUsername($data['name']);
        }

        if (isset($data['mail'])) {
            $user->setEmail($data['mail']);
        }

        if (!empty($data['pass'])) {
            $user->setPassword($data['pass']);
        }

        if (isset($data['status']) && $is_admin) {
            $user->set('status', $data['status'] ? 1 : 0);
        }

        if (!empty($data['roles']) && is_array($data['roles']) && $is_admin) {
            $user->set('roles', $data['roles']);
        }

        $saved = $user->save();

        // If own password changed, rotate token
        if (!empty($data['pass']) && $data['uid'] == $current_user->id()) {
            $token = $request->cookies->get('auth_token');
            $service->invalidateBearerToken($token);
            $new_token = $service->generateBearerToken($user, 60);

            $response = new JsonResponse([
                'status'  => (bool) $saved,
                'message' => 'Compte mis à jour. Veuillez vous reconnecter.',
                'user'    => ['uid' => $user->id(), 'name' => $user->getAccountName(), 'mail' => $user->getEmail()],
            ]);
            $response->headers->setCookie(new Cookie(
                'auth_token', $new_token, time() + self::COOKIE_LIFETIME,
                '/', null, false, true, false, 'Lax'
            ));
            return $response;
        }

        return new JsonResponse([
            'status' => (bool) $saved,
            'user'   => [
                'uid'    => $user->id(),
                'name'   => $user->getAccountName(),
                'mail'   => $user->getEmail(),
                'roles'  => $user->getRoles(),
                'status' => $user->isActive(),
            ],
        ]);
    }

    /**
     * POST /api/crud/user_delete
     */
    public function userDelete(Request $request)
    {
        [$service, $current_user, $error] = $this->requireAuth($request);
        if ($error) return $error;

        $allowed_roles = ['administrator', 'webmaster'];
        if (!array_intersect($allowed_roles, $current_user->getRoles())) {
            return new JsonResponse(['status' => false, 'error' => 'Accès non autorisé'], 403);
        }

        $data = json_decode($request->getContent(), TRUE);

        if (empty($data['uid'])) {
            return new JsonResponse(['status' => false, 'error' => 'UID manquant'], 400);
        }

        if ($data['uid'] == $current_user->id()) {
            return new JsonResponse(['status' => false, 'error' => 'Impossible de supprimer votre propre compte'], 400);
        }

        $user = User::load($data['uid']);
        if (!is_object($user)) {
            return new JsonResponse(['status' => false, 'error' => 'Utilisateur introuvable'], 404);
        }

        // Invalidate all tokens for this user before deleting
        $service->invalidateUserTokens($data['uid']);
        $user->delete();

        return new JsonResponse(['status' => true, 'message' => 'Utilisateur supprimé avec succès']);
    }

    /**
     * POST /api/crud/change-password
     */
    public function changePassword(Request $request)
    {
        [$service, $current_user, $error] = $this->requireAuth($request);
        if ($error) return $error;

        $data = json_decode($request->getContent(), TRUE);

        if (empty($data['current_password']) || empty($data['new_password'])) {
            return new JsonResponse(['status' => false, 'error' => 'Mot de passe actuel et nouveau requis'], 400);
        }

        if ($data['current_password'] === $data['new_password']) {
            return new JsonResponse(['status' => false, 'error' => 'Le nouveau mot de passe doit être différent de l\'ancien'], 400);
        }

        if (strlen($data['new_password']) < 6) {
            return new JsonResponse(['status' => false, 'error' => 'Le mot de passe doit contenir au moins 6 caractères'], 400);
        }

        $password_hasher = \Drupal::service('password');
        if (!$password_hasher->check($data['current_password'], $current_user->getPassword())) {
            return new JsonResponse(['status' => false, 'error' => 'Mot de passe actuel incorrect'], 401);
        }

        $current_user->setPassword($data['new_password']);
        $saved = $current_user->save();

        if (!$saved) {
            return new JsonResponse(['status' => false, 'error' => 'Erreur lors du changement de mot de passe'], 500);
        }

        $token = $request->cookies->get('auth_token');
        $service->invalidateBearerToken($token);
        $new_token = $service->generateBearerToken($current_user, 60);

        $response = new JsonResponse([
            'status'  => true,
            'message' => 'Mot de passe changé avec succès.',
            'user'    => ['id' => $current_user->id(), 'name' => $current_user->getAccountName(), 'mail' => $current_user->getEmail()],
        ]);
        $response->headers->setCookie(new Cookie(
            'auth_token', $new_token, time() + self::COOKIE_LIFETIME,
            '/', null, false, true, false, 'Lax'
        ));
        return $response;
    }

    // ─── Generic CRUD ────────────────────────────────────────────────────────

    /**
     * POST /api/crud/save
     */
    public function save(Request $request)
    {
        [$service, $user, $error] = $this->requireAuth($request);
        if ($error) return $error;

        $content = $request->getContent();
        if (empty($content)) {
            return new JsonResponse(['message' => 'Données non trouvées', 'status' => 'error'], 400);
        }

        $data        = json_decode($content, TRUE);
        $entity_type = $data['entity_type'] ?? '';
        $bundle      = $data['bundle'] ?? '';

        if ($entity_type === 'node' && !isset($data['uid'])) {
            $data['uid'] = $user->id();
        }

        unset($data['bundle'], $data['entity_type']);

        $entity = \Drupal::service('clinic.crud')->save($entity_type, $bundle, $data);

        if (is_object($entity)) {
            return new JsonResponse(['item' => $entity->id(), 'status' => true], 200);
        }

        return new JsonResponse(['message' => 'Erreur lors de la sauvegarde', 'status' => 'error'], 400);
    }

    /**
     * POST /api/crud/register
     */
    public function register(Request $request)
    {
        $service = \Drupal::service('clinic.api');
        $data    = json_decode($request->getContent(), TRUE);

        if (empty($data['name']) || empty($data['pass'])) {
            return new JsonResponse(['status' => false, 'message' => 'Nom d\'utilisateur et mot de passe requis'], 400);
        }

        if ($service->isUserNameExist($data['name'])) {
            return new JsonResponse(['status' => false, 'error' => 'Nom d\'utilisateur existe déjà'], 400);
        }

        $user = User::create();
        $user->setPassword($data['pass']);
        $user->enforceIsNew();
        $user->setEmail($data['email'] ?? 'email@yahoo.fr');
        $user->setUsername($data['name']);
        $saved = $user->save();

        if (!$saved) {
            return new JsonResponse(['status' => false, 'message' => 'Erreur lors de la création du compte'], 500);
        }

        $token    = $service->generateBearerToken($user, 60);
        $response = new JsonResponse([
            'status'  => true,
            'message' => 'Inscription réussie',
            'user'    => ['id' => $user->id(), 'name' => $user->getAccountName(), 'mail' => $user->getEmail()],
        ]);
        $response->headers->setCookie(new Cookie(
            'auth_token', $token, time() + self::COOKIE_LIFETIME,
            '/', null, false, true, false, 'Lax'
        ));
        return $response;
    }

    // ─── Helper ──────────────────────────────────────────────────────────────

    /**
     * Validate auth_token cookie and return [service, user, errorResponse].
     * If errorResponse is non-null, return it immediately.
     */
    private function requireAuth(Request $request): array
    {
        $token = $request->cookies->get('auth_token');

        if (!$token) {
            return [null, null, new JsonResponse(['message' => 'Non authentifié. Veuillez vous connecter.', 'status' => 'error'], 401)];
        }

        $service = \Drupal::service('clinic.api');
        $user    = $service->validateBearerToken($token);

        if (!$user) {
            $response = new JsonResponse(['message' => 'Session expirée. Veuillez vous reconnecter.', 'status' => 'error'], 401);
            $response->headers->clearCookie('auth_token', '/');
            return [null, null, $response];
        }

        return [$service, $user, null];
    }
}
