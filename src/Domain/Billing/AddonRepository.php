<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Persistence of `addon_packages` (the catalogue) and `workspace_addons` (what a workspace has bought and until when).
 */
final class AddonRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @return list<AddonPackage>
     */
    public function all(bool $onlyActive = false): array
    {
        $rows = $this->db->select('SELECT * FROM addon_packages' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY sort ASC, id ASC');

        return array_map(self::hydrate(...), $rows);
    }

    public function find(int $id): ?AddonPackage
    {
        $row = $this->db->table('addon_packages')->where('id', '=', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function findByCode(string $code): ?AddonPackage
    {
        $row = $this->db->table('addon_packages')->where('code', '=', $code)->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @param array<string, int> $prices
     */
    public function save(?int $id, string $code, string $name, string $kind, int $quantity, int $days, array $prices, bool $active, int $sort): AddonPackage
    {
        $values = ['name' => $name, 'kind' => $kind, 'quantity' => $quantity, 'days' => $days, 'prices_json' => json_encode($prices, JSON_THROW_ON_ERROR), 'active' => $active ? 1 : 0, 'sort' => $sort];
        if ($id === null) {
            $id = (int) $this->db->table('addon_packages')->insert($values + ['code' => $code, 'created_at' => DbTime::format($this->clock->now())]);
        } else {
            $this->db->table('addon_packages')->where('id', '=', $id)->update($values);
        }

        return $this->find($id) ?? throw new \RuntimeException('The package was not saved.');
    }

    /**
     * Record a bought limit add-on. Safe to call twice for one invoice.
     */
    public function grant(int $workspaceId, AddonPackage $package, int $invoiceId, DateTimeImmutable $from): void
    {
        if ($this->db->table('workspace_addons')->where('invoice_id', '=', $invoiceId)->exists()) {
            return;
        }
        $this->db->table('workspace_addons')->insert([
            'workspace_id' => $workspaceId,
            'package_id' => $package->id,
            'kind' => $package->kind,
            'quantity' => $package->quantity,
            'invoice_id' => $invoiceId,
            'valid_until' => DbTime::format($from->modify(sprintf('+%d days', $package->days))),
            'created_at' => DbTime::format($this->clock->now()),
        ]);
    }

    /**
     * What the workspace's active add-ons add to each limit now.
     *
     * @return array<string, int> limit key => extra
     */
    public function extras(int $workspaceId): array
    {
        $extra = [];
        $rows = $this->db->select(
            'SELECT kind, SUM(quantity) AS total FROM workspace_addons WHERE workspace_id = ? AND valid_until > ? GROUP BY kind',
            [$workspaceId, DbTime::format($this->clock->now())],
        );
        foreach ($rows as $row) {
            $extra[(string) $row['kind']] = (int) $row['total'];
        }

        return $extra;
    }

    /**
     * @return list<array{package: string, kind: string, quantity: int, valid_until: DateTimeImmutable}>
     */
    public function active(int $workspaceId): array
    {
        $out = [];
        $rows = $this->db->select(
            'SELECT a.kind, a.quantity, a.valid_until, COALESCE(p.name, a.kind) AS name FROM workspace_addons a LEFT JOIN addon_packages p ON p.id = a.package_id WHERE a.workspace_id = ? AND a.valid_until > ? ORDER BY a.valid_until ASC',
            [$workspaceId, DbTime::format($this->clock->now())],
        );
        foreach ($rows as $row) {
            $out[] = ['package' => (string) $row['name'], 'kind' => (string) $row['kind'], 'quantity' => (int) $row['quantity'], 'valid_until' => DbTime::parse($row['valid_until']) ?? $this->clock->now()];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): AddonPackage
    {
        $prices = [];
        $decoded = json_decode((string) $row['prices_json'], true);
        foreach (is_array($decoded) ? $decoded : [] as $currency => $amount) {
            if (is_string($currency) && is_int($amount) && $amount > 0) {
                $prices[$currency] = $amount;
            }
        }

        return new AddonPackage((int) $row['id'], (string) $row['code'], (string) $row['name'], (string) $row['kind'], (int) $row['quantity'], (int) $row['days'], $prices, (int) $row['active'] === 1, (int) $row['sort']);
    }
}
