<?php

namespace Enrollment\Actions;

use Enrollment\Data\CreateOrderData;
use Enrollment\Enums\OrderStatus;
use Enrollment\Models\Order;
use Enrollment\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Training\Actions\ReserveSeatsAction;
use Training\Models\Training;

final class CreateOrderAction
{
    public function __construct(
        private readonly ReserveSeatsAction $reserveSeats,
    ) {}

    public function __invoke(CreateOrderData $data): Order
    {
        return DB::transaction(function () use ($data) {
            $totals = $this->calculateOrderTotals($data->items, $data->tax);
            $orderNumber = $this->generateUniqueOrderNumber();

            $this->holdTrainingSeats($data->items);

            $order = $this->persistOrderRecord($data, $orderNumber, $totals);
            $this->persistOrderItems($order, $data->items);

            return $order->fresh(['items']);
        });
    }

    private function calculateOrderTotals(array $items, int $tax): array
    {
        $subtotal = 0;

        foreach ($items as $item) {
            $subtotal += ($item['unit_price'] * $item['quantity']);
        }

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal + $tax,
        ];
    }

    private function generateUniqueOrderNumber(): string
    {
        $year = now()->format('Y');
        $random = strtoupper(Str::random(6));

        return "ORD-{$year}-{$random}";
    }

    private function holdTrainingSeats(array $items): void
    {
        foreach ($items as $item) {
            $training = Training::findOrFail($item['training_id']);
            ($this->reserveSeats)($training, $item['quantity']);
        }
    }

    private function persistOrderRecord(CreateOrderData $data, string $orderNumber, array $totals): Order
    {
        return Order::create([
            'order_number' => $orderNumber,
            'user_id' => $data->user_id,
            'status' => OrderStatus::Pending,
            'subtotal' => $totals['subtotal'],
            'tax' => $totals['tax'],
            'total' => $totals['total'],
            'currency' => strtoupper($data->currency),
            'billing_address' => $data->billing_address,
            'terms_accepted_at' => $data->terms_accepted ? now() : null,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    private function persistOrderItems(Order $order, array $items): void
    {
        foreach ($items as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'training_id' => $item['training_id'],
                'unit_price' => $item['unit_price'],
                'quantity' => $item['quantity'],
                'total' => $item['unit_price'] * $item['quantity'],
            ]);
        }
    }
}
