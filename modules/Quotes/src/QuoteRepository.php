<?php

declare(strict_types=1);

namespace Modules\Quotes\src;

use PDO;

final class QuoteRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function published(): array
    {
        return $this->database->query(
            'SELECT id, quote_text, attribution, created_at FROM quotes WHERE is_published = 1 ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function all(): array
    {
        return $this->database->query(
            'SELECT id, quote_text, attribution, is_published, created_at FROM quotes ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function create(string $quote, string $attribution, bool $published): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO quotes (quote_text, attribution, is_published, created_at, updated_at) VALUES (:quote, :attribution, :published, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'quote' => $quote,
            'attribution' => $attribution,
            'published' => $published ? 1 : 0,
        ]);
        return (int) $this->database->lastInsertId();
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM quotes WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
