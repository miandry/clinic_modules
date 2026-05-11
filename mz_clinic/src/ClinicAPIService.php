<?php

namespace Drupal\mz_clinic;

use Drupal\user\Entity\User;

/**
 * Authentication service: Bearer token generation and validation.
 * Tokens are stored in the mz_crud_tokens table (shared with mz_crud).
 */
class ClinicAPIService
{
    const TOKEN_TABLE = 'mz_crud_tokens';

    // ─── User lookup ─────────────────────────────────────────────────────────

    public function isUserNameExist(string $name): bool
    {
        $result = \Drupal::entityQuery('user')
            ->condition('name', $name)
            ->range(0, 1)
            ->accessCheck(FALSE)
            ->execute();
        return !empty($result);
    }

    // ─── Token management ────────────────────────────────────────────────────

    public function generateBearerToken(User $user, int $days = 60): string
    {
        $token      = bin2hex(random_bytes(32));
        $request    = \Drupal::request();
        $session_id = hash('sha256', $user->id() . $request->headers->get('User-Agent', '') . $request->getClientIp() . time() . uniqid('', true));

        \Drupal::database()->insert(self::TOKEN_TABLE)
            ->fields([
                'uid'           => $user->id(),
                'token'         => $token,
                'session_id'    => $session_id,
                'user_agent'    => $request->headers->get('User-Agent', ''),
                'ip_address'    => $request->getClientIp(),
                'created'       => time(),
                'expiration'    => time() + ($days * 86400),
                'last_activity' => time(),
            ])
            ->execute();

        return $token;
    }

    public function validateBearerToken(string $token): ?User
    {
        if (empty($token)) {
            return null;
        }

        $row = \Drupal::database()->select(self::TOKEN_TABLE, 't')
            ->fields('t', ['uid'])
            ->condition('token', $token)
            ->condition('expiration', time(), '>')
            ->execute()
            ->fetchAssoc();

        if ($row && isset($row['uid'])) {
            \Drupal::database()->update(self::TOKEN_TABLE)
                ->fields(['last_activity' => time()])
                ->condition('token', $token)
                ->execute();
            return User::load($row['uid']);
        }

        return null;
    }

    public function invalidateBearerToken(string $token): void
    {
        if (!empty($token)) {
            \Drupal::database()->delete(self::TOKEN_TABLE)
                ->condition('token', $token)
                ->execute();
        }
    }

    public function invalidateUserTokens(int $uid): void
    {
        if (!empty($uid)) {
            \Drupal::database()->delete(self::TOKEN_TABLE)
                ->condition('uid', $uid)
                ->execute();
        }
    }

    public function cleanupExpiredTokens(): int
    {
        return (int) \Drupal::database()->delete(self::TOKEN_TABLE)
            ->condition('expiration', time(), '<')
            ->execute();
    }

    public function getUserSessions(int $uid): array
    {
        return \Drupal::database()->select(self::TOKEN_TABLE, 't')
            ->fields('t', ['token', 'session_id', 'user_agent', 'ip_address', 'created', 'expiration', 'last_activity'])
            ->condition('uid', $uid)
            ->condition('expiration', time(), '>')
            ->execute()
            ->fetchAll();
    }

    public function invalidateSession(string $session_id): void
    {
        if (!empty($session_id)) {
            \Drupal::database()->delete(self::TOKEN_TABLE)
                ->condition('session_id', $session_id)
                ->execute();
        }
    }
}
