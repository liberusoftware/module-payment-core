<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Liberu\Payment\Contracts\Contracts\MerchantWebhookVerifierContract;
use Liberu\Payment\Contracts\Contracts\OutboundPaymentAdapterContract;
use Liberu\Payment\Contracts\Contracts\PaymentCaptureAndRefundContract;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayAdapterContract;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;
use Liberu\Payment\Contracts\Contracts\PaymentIntentServiceContract;
use Liberu\Payment\Contracts\DTOs\CancelPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\CapturePaymentCommand;
use Liberu\Payment\Contracts\DTOs\ConfirmPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\ConnectionHealthDto;
use Liberu\Payment\Contracts\DTOs\CreatePaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\MerchantWebhookEventDto;
use Liberu\Payment\Contracts\DTOs\MoneyAmount;
use Liberu\Payment\Contracts\DTOs\PaymentCapabilityDescriptorDto;
use Liberu\Payment\Contracts\DTOs\PaymentCaptureResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentIntentResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentRefundResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentVoidResultDto;
use Liberu\Payment\Contracts\DTOs\RawWebhookPayloadDto;
use Liberu\Payment\Contracts\DTOs\RefundPaymentCommand;
use Liberu\Payment\Contracts\DTOs\VoidPaymentCommand;
use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;
use Liberu\Payment\Core\PaymentCoreServiceProvider;
use Liberu\Payment\Core\Services\PaymentGatewayRegistry;
use Liberu\Payment\Core\Services\PaymentOrchestrator;

it('boots payment core service provider, merges config, binds singletons, and migrates tables', function (): void {
    $provider = $this->app->register(PaymentCoreServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    $registryA = $this->app->make(PaymentGatewayRegistryContract::class);
    $registryB = $this->app->make(PaymentGatewayRegistryContract::class);
    $orchestratorA = $this->app->make(PaymentOrchestrator::class);
    $orchestratorB = $this->app->make(PaymentOrchestrator::class);

    expect(config('payment-core.default_provider'))->toBe('stripe')
        ->and($registryA)->toBeInstanceOf(PaymentGatewayRegistry::class)
        ->and($registryA)->toBe($registryB)
        ->and($orchestratorA)->toBe($orchestratorB)
        ->and(Schema::hasTable('payment_core_intents'))->toBeTrue()
        ->and(Schema::hasTable('payment_core_webhook_inbox'))->toBeTrue();

    DB::table('payment_core_intents')->insert([
        'tenant_id' => 'tenant-1',
        'provider_key' => 'stripe',
        'provider_intent_id' => 'pi_123',
        'idempotency_key' => 'idem_123',
        'state' => 'authorized',
        'amount_minor' => 1500,
        'currency' => 'USD',
        'metadata' => json_encode(['order' => 1]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('payment_core_webhook_inbox')->insert([
        'provider_key' => 'stripe',
        'provider_event_id' => 'evt_123',
        'event_type' => 'payment_intent.succeeded',
        'payload' => json_encode(['id' => 'evt_123']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('payment_core_intents')->count())->toBe(1)
        ->and(DB::table('payment_core_webhook_inbox')->count())->toBe(1);
});

it('registers, checks, resolves, and lists adapters in PaymentGatewayRegistry', function (): void {
    $registry = new PaymentGatewayRegistry();

    $emptyAdapter = new class() implements PaymentGatewayAdapterContract
    {
        public function providerKey(): string
        {
            return '   ';
        }

        public function descriptor(): PaymentCapabilityDescriptorDto
        {
            return new PaymentCapabilityDescriptorDto('empty', 'Empty');
        }

        public function testConnection(MerchantAccountContextDto $account): ConnectionHealthDto
        {
            return new ConnectionHealthDto('empty', true, 'OK');
        }
    };

    expect(fn () => $registry->register($emptyAdapter))
        ->toThrow(InvalidArgumentException::class, 'Payment provider key cannot be empty.');

    $adapter = new class() implements PaymentGatewayAdapterContract
    {
        public function providerKey(): string
        {
            return ' MockPay ';
        }

        public function descriptor(): PaymentCapabilityDescriptorDto
        {
            return new PaymentCapabilityDescriptorDto('mockpay', 'MockPay');
        }

        public function testConnection(MerchantAccountContextDto $account): ConnectionHealthDto
        {
            return new ConnectionHealthDto('mockpay', true, 'OK');
        }
    };

    $registry->register($adapter);

    expect($registry->has('mockpay'))->toBeTrue()
        ->and($registry->has(' MOCKPAY '))->toBeTrue()
        ->and($registry->has('unknown'))->toBeFalse()
        ->and($registry->resolve('MOCKPAY'))->toBe($adapter)
        ->and($registry->all())->toBe(['mockpay' => $adapter]);

    expect(fn () => $registry->resolve('unknown'))
        ->toThrow(InvalidArgumentException::class, 'Payment gateway adapter [unknown] is not registered.');
});

it('orchestrates capable adapters and throws runtime exceptions for unsupported capabilities', function (): void {
    $registry = new PaymentGatewayRegistry();

    $bareAdapter = new class() implements PaymentGatewayAdapterContract
    {
        public function providerKey(): string
        {
            return 'bare';
        }

        public function descriptor(): PaymentCapabilityDescriptorDto
        {
            return new PaymentCapabilityDescriptorDto('bare', 'Bare');
        }

        public function testConnection(MerchantAccountContextDto $account): ConnectionHealthDto
        {
            return new ConnectionHealthDto('bare', true, 'OK');
        }
    };

    $fullAdapter = new class() implements MerchantWebhookVerifierContract, OutboundPaymentAdapterContract, PaymentCaptureAndRefundContract, PaymentIntentServiceContract
    {
        public function providerKey(): string
        {
            return 'full';
        }

        public function descriptor(): PaymentCapabilityDescriptorDto
        {
            return new PaymentCapabilityDescriptorDto('full', 'Full');
        }

        public function testConnection(MerchantAccountContextDto $account): ConnectionHealthDto
        {
            return new ConnectionHealthDto('full', true, 'OK');
        }

        public function createIntent(CreatePaymentIntentCommand $command): PaymentIntentResultDto
        {
            return new PaymentIntentResultDto('full', 'pi_'.$command->idempotencyKey, PaymentLifecycleState::Authorized, $command->amount);
        }

        public function confirmIntent(ConfirmPaymentIntentCommand $command): PaymentIntentResultDto
        {
            return new PaymentIntentResultDto('full', $command->providerIntentId, PaymentLifecycleState::Captured, new MoneyAmount(0, 'USD'));
        }

        public function cancelIntent(CancelPaymentIntentCommand $command): PaymentIntentResultDto
        {
            return new PaymentIntentResultDto('full', $command->providerIntentId, PaymentLifecycleState::Cancelled, new MoneyAmount(0, 'USD'));
        }

        public function capture(CapturePaymentCommand $command): PaymentCaptureResultDto
        {
            return new PaymentCaptureResultDto('full', $command->providerIntentId, 'cap_'.$command->idempotencyKey, PaymentLifecycleState::Captured, $command->amount ?? new MoneyAmount(0, 'USD'));
        }

        public function voidAuthorization(VoidPaymentCommand $command): PaymentVoidResultDto
        {
            return new PaymentVoidResultDto('full', $command->providerIntentId, PaymentLifecycleState::Voided);
        }

        public function refund(RefundPaymentCommand $command): PaymentRefundResultDto
        {
            return new PaymentRefundResultDto('full', $command->providerReference, 'ref_'.$command->idempotencyKey, PaymentLifecycleState::Refunded, $command->amount);
        }

        public function sendOutboundPayment(MerchantAccountContextDto $account, array $payment): array
        {
            return ['status' => 'sent', 'payment' => $payment];
        }

        public function sendOutboundBatch(MerchantAccountContextDto $account, string $title, array $payments, ?string $scheduleFor = null): array
        {
            return ['title' => $title, 'count' => count($payments), 'scheduleFor' => $scheduleFor];
        }

        public function verifyAndParse(RawWebhookPayloadDto $payload): MerchantWebhookEventDto
        {
            return new MerchantWebhookEventDto('full', 'evt_1', 'payment.captured', 'pi_1');
        }
    };

    $registry->register($bareAdapter);
    $registry->register($fullAdapter);

    $orchestrator = new PaymentOrchestrator($registry);
    $money = new MoneyAmount(2000, 'USD');
    $fullAccount = new MerchantAccountContextDto('tenant-1', 'full', ['key' => 'k']);
    $bareAccount = new MerchantAccountContextDto('tenant-1', 'bare');

    $intent = $orchestrator->createIntent(new CreatePaymentIntentCommand($fullAccount, $money, 'k1'));
    $capture = $orchestrator->capture(new CapturePaymentCommand($fullAccount, 'pi_k1', 'k2', $money));
    $refund = $orchestrator->refund(new RefundPaymentCommand($fullAccount, 'pi_k1', $money, 'k3'));
    $outbound = $orchestrator->sendOutboundPayment($fullAccount, ['amount' => 100]);
    $batch = $orchestrator->sendOutboundBatch($fullAccount, 'Weekly Payouts', [['amount' => 100]], '2026-12-01');
    $webhook = $orchestrator->verifyWebhook(new RawWebhookPayloadDto('full', '{}', [], 'sec'));

    expect($intent->providerIntentId)->toBe('pi_k1')
        ->and($capture->providerCaptureId)->toBe('cap_k2')
        ->and($refund->providerRefundId)->toBe('ref_k3')
        ->and($outbound)->toBe(['status' => 'sent', 'payment' => ['amount' => 100]])
        ->and($batch)->toBe(['title' => 'Weekly Payouts', 'count' => 1, 'scheduleFor' => '2026-12-01'])
        ->and($webhook->eventType)->toBe('payment.captured');

    expect(fn () => $orchestrator->createIntent(new CreatePaymentIntentCommand($bareAccount, $money, 'k1')))
        ->toThrow(RuntimeException::class, 'Adapter [bare] does not support payment intents.');

    expect(fn () => $orchestrator->capture(new CapturePaymentCommand($bareAccount, 'pi_1', 'k2')))
        ->toThrow(RuntimeException::class, 'Adapter [bare] does not support captures.');

    expect(fn () => $orchestrator->refund(new RefundPaymentCommand($bareAccount, 'pi_1', $money, 'k3')))
        ->toThrow(RuntimeException::class, 'Adapter [bare] does not support refunds.');

    expect(fn () => $orchestrator->sendOutboundPayment($bareAccount, []))
        ->toThrow(RuntimeException::class, 'Adapter [bare] does not support outbound payments.');

    expect(fn () => $orchestrator->sendOutboundBatch($bareAccount, 'Batch', []))
        ->toThrow(RuntimeException::class, 'Adapter [bare] does not support outbound payments.');

    expect(fn () => $orchestrator->verifyWebhook(new RawWebhookPayloadDto('bare', '{}', [], 'sec')))
        ->toThrow(RuntimeException::class, 'Adapter [bare] does not support webhook verification.');
});
