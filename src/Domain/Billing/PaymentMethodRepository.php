<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Persistence of `payment_methods`: references to cards (or other ways to pay) that a provider keeps for us.
 */
final class PaymentMethodRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * Remember a method, or refresh the caption of a known one (the provider returns the same reference when the card is reused).
     */
    public function remember(int $workspaceId, string $provider, string $providerMethodId, string $title, ?string $customerKey): PaymentMethod
    {
        $existing = $this->db->table('payment_methods')->where('provider', '=', $provider)->where('provider_method_id', '=', $providerMethodId)->first();
        if ($existing !== null) {
            if ((int) $existing['workspace_id'] !== $workspaceId) {
                // A reference that belongs to another workspace is never moved here.
                throw new \DomainException('The payment method belongs to another workspace.');
            }
            $this->db->table('payment_methods')->where('id', '=', (int) $existing['id'])->update(['title' => mb_substr($title, 0, 100), 'status' => 'active']);

            return self::hydrate($this->db->table('payment_methods')->where('id', '=', (int) $existing['id'])->first() ?? $existing);
        }
        $publicId = (string) new Ulid();
        $this->db->table('payment_methods')->insert([
            'public_id' => $publicId,
            'workspace_id' => $workspaceId,
            'provider' => $provider,
            'provider_method_id' => $providerMethodId,
            'customer_key' => $customerKey,
            'title' => mb_substr($title, 0, 100),
            'status' => 'active',
            'created_at' => DbTime::format($this->clock->now()),
        ]);
        $row = $this->db->table('payment_methods')->where('public_id', '=', $publicId)->first();

        return self::hydrate($row ?? throw new \RuntimeException('The payment method was not saved.'));
    }

    public function find(int $id): ?PaymentMethod
    {
        $row = $this->db->table('payment_methods')->where('id', '=', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function revoke(int $id): void
    {
        $this->db->table('payment_methods')->where('id', '=', $id)->update(['status' => 'revoked']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): PaymentMethod
    {
        return new PaymentMethod(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (string) $row['provider'],
            (string) $row['provider_method_id'],
            isset($row['customer_key']) ? (string) $row['customer_key'] : null,
            (string) $row['title'],
            (string) $row['status'] === 'active',
            DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0'),
        );
    }
}
