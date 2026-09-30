<?php

declare(strict_types=1);

namespace NovaNuke\Core\Blocks;

use PDO;
use RuntimeException;

final class BlockRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->database->query('SELECT * FROM blocks ORDER BY position, sort_order, id')->fetchAll();
        $roleMap=$this->roleMap(false);
        foreach ($rows as &$row) {
            $row = $this->decode($row);
            $row['role_ids'] = $roleMap[(int)$row['id']]??[];
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM blocks WHERE id=:id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $this->decode($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        $statement = $this->database->query(
            'SELECT * FROM blocks WHERE enabled = 1 '
            . 'AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP()) '
            . 'AND (ends_at IS NULL OR ends_at >= UTC_TIMESTAMP()) ORDER BY position, sort_order, id'
        );
        $rows = $statement->fetchAll();
        $roleMap=$this->roleMap(true);
        foreach ($rows as &$row) {
            $row = $this->decode($row);
            $row['role_slugs'] = $roleMap[(int)$row['id']]??[];
        }

        return $rows;
    }

    /** @param array<string, mixed> $data @param list<int> $roleIds */
    public function save(?int $id, array $data, array $roleIds): int
    {
        $this->database->beginTransaction();
        try {
            if ($id !== null) {
                $exists = $this->database->prepare('SELECT COUNT(*) FROM blocks WHERE id = :id');
                $exists->execute(['id' => $id]);
                if ((int) $exists->fetchColumn() !== 1) {
                    throw new RuntimeException('Block not found.');
                }
            }
            if ($id === null) {
                $sql = 'INSERT INTO blocks (title, slug, type, position, content, configuration, visibility_mode, audience, '
                    . 'page_patterns, module_slugs, enabled, show_title, sort_order, starts_at, ends_at, created_by, created_at, updated_at) '
                    . 'VALUES (:title, :slug, :type, :position, :content, :configuration, :visibility_mode, :audience, '
                    . ':page_patterns, :module_slugs, :enabled, :show_title, :sort_order, :starts_at, :ends_at, :created_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())';
            } else {
                $sql = 'UPDATE blocks SET title=:title, slug=:slug, type=:type, position=:position, content=:content, '
                    . 'configuration=:configuration, visibility_mode=:visibility_mode, audience=:audience, page_patterns=:page_patterns, '
                    . 'module_slugs=:module_slugs, enabled=:enabled, show_title=:show_title, sort_order=:sort_order, '
                    . 'starts_at=:starts_at, ends_at=:ends_at, updated_at=UTC_TIMESTAMP() WHERE id=:id';
            }
            $statement = $this->database->prepare($sql);
            $parameters = $data;
            if ($id !== null) {
                $parameters['id'] = $id;
                unset($parameters['created_by']);
            }
            $statement->execute($parameters);
            $blockId = $id ?? (int) $this->database->lastInsertId();
            $this->database->prepare('DELETE FROM block_roles WHERE block_id = :id')->execute(['id' => $blockId]);
            $insert = $this->database->prepare('INSERT INTO block_roles (block_id, role_id) VALUES (:block, :role)');
            foreach (array_unique($roleIds) as $roleId) {
                $insert->execute(['block' => $blockId, 'role' => $roleId]);
            }
            $this->database->commit();

            return $blockId;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            if ($error instanceof \PDOException && $error->getCode() === '23000') {
                throw new RuntimeException('The block slug is already in use.', 0, $error);
            }
            throw $error;
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM blocks WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Block not found.');
        }
    }

    /** @return list<array{id: int, name: string, slug: string}> */
    public function roles(): array
    {
        return $this->database->query('SELECT id, name, slug FROM roles ORDER BY id')->fetchAll();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function decode(array $row): array
    {
        foreach (['configuration', 'page_patterns', 'module_slugs'] as $field) {
            $row[$field] = is_string($row[$field]) ? (json_decode($row[$field], true) ?: []) : [];
        }
        return $row;
    }

    /** @return array<int,list<int|string>> */
    private function roleMap(bool $slugs):array
    {
        $sql=$slugs?'SELECT br.block_id,r.slug AS role_value FROM block_roles br INNER JOIN roles r ON r.id=br.role_id ORDER BY br.block_id,r.slug':'SELECT block_id,role_id AS role_value FROM block_roles ORDER BY block_id,role_id';$rows=$this->database->query($sql)->fetchAll();$map=[];foreach($rows as$row){$id=(int)$row['block_id'];$map[$id][]= $slugs?(string)$row['role_value']:(int)$row['role_value'];}return$map;
    }
}
