<?php

namespace Enrollment\Services;

use Enrollment\Contracts\PaymentGatewayContract;
use Enrollment\Data\CheckoutSessionData;
use Enrollment\Models\Order;
use Enrollment\Models\Payment;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

final class StripePaymentGateway implements PaymentGatewayContract
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): CheckoutSessionData
    {
        $lineItems = $this->resolveLineItems($order);
        $session = $this->createStripeSession($order, $lineItems, $successUrl, $cancelUrl);

        return $this->toCheckoutSessionData($order, $session);
    }

    public function refundPayment(Payment $payment, ?int $amountInCents = null): bool
    {
        if (! $this->hasGatewayTransaction($payment)) {
            return false;
        }

        $this->stripe->refunds->create($this->refundParameters($payment, $amountInCents));

        return true;
    }

    private function resolveLineItems(Order $order): array
    {
        return $order->items->map(fn ($item) => [
            'price_data' => [
                'currency' => strtolower($order->currency),
                'unit_amount' => $item->unit_price,
                'product_data' => [
                    'name' => "Training Seat #{$item->training_id}",
                ],
            ],
            'quantity' => $item->quantity,
        ])->all();
    }

    private function createStripeSession(Order $order, array $lineItems, string $successUrl, string $cancelUrl): Session
    {
        return $this->stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $order->id,
        ]);
    }

    private function toCheckoutSessionData(Order $order, Session $session): CheckoutSessionData
    {
        return new CheckoutSessionData(
            checkoutUrl: $session->url ?? "https://checkout.stripe.com/pay/{$session->id}",
            sessionId: $session->id,
            orderId: $order->id,
            amount: $order->total,
            currency: $order->currency,
        );
    }

    private function hasGatewayTransaction(Payment $payment): bool
    {
        return filled($payment->gateway_transaction_id);
    }

    private function refundParameters(Payment $payment, ?int $amountInCents): array
    {
        return array_filter([
            'payment_intent' => $payment->gateway_transaction_id,
            'amount' => $amountInCents,
        ], fn ($value) => $value !== null);
    }
}
