<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

class MapStore
{
  public function __construct(private PDO $pdo) {}
  public function findDocument(string $source, string $sourceType, string $sourceKey): ?array
  {
    $st = $this->pdo->prepare('SELECT * FROM maps_documents WHERE source=? AND source_type=? AND source_key=? LIMIT 1');
    $st->execute([$source, $sourceType, $sourceKey]);
    $r = $st->fetch();
    return $r ?: null;
  }
  public function mapDocument(string $source, string $sourceType, string $sourceKey, string $qboEntity, string $qboId): void
  {
    $st = $this->pdo->prepare('INSERT INTO maps_documents (source,source_type,source_key,qbo_entity,qbo_id,created_at) VALUES (?,?,?,?,?,NOW())');
    $st->execute([$source, $sourceType, $sourceKey, $qboEntity, $qboId]);
  }
  public function getCursor(string $stream): ?string
  {
    $st = $this->pdo->prepare('SELECT last_seen_timestamp FROM sync_cursors WHERE stream=? LIMIT 1');
    $st->execute([$stream]);
    $r = $st->fetch();
    return $r ? $r['last_seen_timestamp'] : null;
  }
  public function setCursor(string $stream, string $ts): void
  {
    $st = $this->pdo->prepare('INSERT INTO sync_cursors (stream,last_seen_timestamp) VALUES (?,?) ON DUPLICATE KEY UPDATE last_seen_timestamp=VALUES(last_seen_timestamp)');
    $st->execute([$stream, $ts]);
  }
  public function findMasterData(string $kind, string $sourceKey): ?string
  {
    $st = $this->pdo->prepare('SELECT qbo_id FROM maps_masterdata WHERE kind=? AND source_key=? LIMIT 1');
    $st->execute([$kind, $sourceKey]);
    $r = $st->fetch();
    return $r ? $r['qbo_id'] : null;
  }
  public function mapMasterData(string $kind, string $sourceKey, string $qboId): void
  {
    $st = $this->pdo->prepare('INSERT INTO maps_masterdata (kind,source_key,qbo_id,created_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE qbo_id=VALUES(qbo_id)');
    $st->execute([$kind, $sourceKey, $qboId]);
  }
}
