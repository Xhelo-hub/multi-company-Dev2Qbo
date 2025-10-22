<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

class TokenStore
{
  public function __construct(private PDO $pdo) {}
  public function getQboTokens(): ?array
  {
    $st = $this->pdo->query('SELECT * FROM oauth_tokens_qbo ORDER BY id DESC LIMIT 1');
    $r = $st->fetch();
    return $r ?: null;
  }
  public function saveQboTokens(array $t): void
  {
    $st = $this->pdo->prepare('INSERT INTO oauth_tokens_qbo (access_token,refresh_token,expires_at,realm_id) VALUES (?,?,?,?)');
    $st->execute([$t['access_token'], $t['refresh_token'], (int)$t['expires_at'], $t['realm_id']]);
  }
  public function getDevposToken(): ?array
  {
    $st = $this->pdo->query('SELECT * FROM oauth_tokens_devpos ORDER BY id DESC LIMIT 1');
    $r = $st->fetch();
    return $r ?: null;
  }
  public function saveDevposToken(array $t): void
  {
    $st = $this->pdo->prepare('INSERT INTO oauth_tokens_devpos (access_token,expires_at,tenant,username) VALUES (?,?,?,?)');
    $st->execute([$t['access_token'], (int)$t['expires_at'], $t['tenant'] ?? null, $t['username'] ?? null]);
  }
  public function clearTokens(): void
  {
    $this->pdo->exec('DELETE FROM oauth_tokens_qbo');
    $this->pdo->exec('DELETE FROM oauth_tokens_devpos');
  }
}
