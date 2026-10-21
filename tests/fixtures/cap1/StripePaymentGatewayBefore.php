<?php

namespace Enrollment\Services;

use Enrollment\Contracts\PaymentGatewayContract;
use Enrollment\Data\CheckoutSessionData;
use Enrollment\Models\Order;
use Enrollment\Models\Payment;
use Illuminate\Support\Str;
use Stripe\StripeClient;

final class StripePaymentGateway implements PaymentGatewayContract
{
    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): CheckoutSessionData
    {
        $secretKey = config('enrollment.stripe.secret_key');

        if (! empty($secretKey) && class_exists('Stripe\\StripeClient')) {
            $stripe = new StripeClient($secretKey);

            $lineItems = $order->items->map(fn ($item) => [
                'price_data' => [
                    'currency' => strtolower($order->currency),
                    'unit_amount' => $item->unit_price,
                    'product_data' => [
                        'name' => "Training Seat #{$item->training_id}",
                    ],
                ],
                'quantity' => $item->quantity,
            ])->all();

            $session = $stripe->checkout->sessions->create([
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => (string) $order->id,
            ]);

            return new CheckoutSessionData(
                checkout_url: $session->url ?? "https://checkout.stripe.com/pay/{$session->id}",
                session_id: $session->id,
                order_id: $order->id,
                amount: $order->total,
                currency: $order->currency,
            );
        }

        $mockSessionId = 'cs_test_'.Str::random(24);

        return new CheckoutSessionData(
            checkout_url: "https://checkout.stripe.com/c/pay/{$mockSessionId}",
            session_id: $mockSessionId,
            order_id: $order->id,
            amount: $order->total,
            currency: $order->currency,
        );
    }

    public function refundPayment(Payment $payment, ?int $amountInCents = null): bool
    {
        $secretKey = config('enrollment.stripe.secret_key');

        if (! empty($secretKey) && ! empty($payment->gateway_transaction_id) && class_exists('Stripe\\StripeClient')) {
            $stripe = new StripeClient($secretKey);

            $params = [
                'payment_intent' => $payment->gateway_transaction_id,
            ];

            if ($amountInCents !== null) {
                $params['amount'] = $amountInCents;
            }

            $stripe->refunds->create($params);
        }

        return true;
    }
}
