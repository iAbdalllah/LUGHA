<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Domain\Commerce\RefundOrderItem;
use App\Domain\Payments\VerifyPayment;
use App\Filament\Resources\Orders\OrderResource;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        $order = $this->getRecord();

        return [
            Action::make('recheck')->label(__('Re-check payment with gateway'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => $order->payments()->exists() && ! $order->isPaidOrRefunded())
                ->action(function () use ($order) {
                    try {
                        app(VerifyPayment::class)->handle($order->payments()->first());
                        Notification::make()->title(__('Status: :s', ['s' => $order->refresh()->status->label()]))->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('refund')->label(__('Refund an item'))->icon(Heroicon::OutlinedReceiptRefund)->color('danger')
                ->visible(fn () => $order->isPaidOrRefunded() && auth()->user()->hasAnyRole(['super_admin', 'finance'])
                    && $order->items->contains(fn ($i) => $i->refundableAmount() > 0))
                ->schema([
                    Select::make('item')->label(__('Item'))->required()->options(fn () => $order->items->filter(fn ($i) => $i->refundableAmount() > 0)
                        ->mapWithKeys(fn ($i) => [$i->id => $i->package->name.' — '.Money::format($i->refundableAmount(), $order->currency, 'en')])),
                    Textarea::make('reason')->label(__('Reason'))->required(),
                ])
                ->modalDescription(__('Refunds through the payment gateway and revokes the customer\'s access to the package.'))
                ->requiresConfirmation()
                ->action(function (array $data) use ($order) {
                    try {
                        app(RefundOrderItem::class)->handle($order->items()->findOrFail($data['item']), $data['reason'], auth()->user());
                        Notification::make()->title(__('Refund processed'))->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title(__('Refund failed'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                    $this->refreshFormData([]);
                }),
        ];
    }
}
