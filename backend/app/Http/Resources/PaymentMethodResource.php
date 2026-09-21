<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A way this shop takes money, as its owner configured it.
 *
 * Alongside the shop's own fields the resource reports what the channel *means*,
 * because that is what a till needs and cannot derive for itself: whether this
 * method hands change back, whether it needs a reference typed in, whether a
 * provider has to confirm the money. A client that reads those three flags can
 * build a correct payment dialog without knowing the nine channels exist, and
 * without a rename in the admin panel changing how the money behaves.
 */
class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'code' => $this->code,
            'name' => $this->name,
            'channel' => $this->channel?->value,
            'channel_label' => $this->channel?->label(),
            'provider' => $this->provider,
            'icon' => $this->icon,
            'description' => $this->description,

            'requires_reference' => (bool) $this->requires_reference,
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'settings' => $this->settings,

            // Read off the channel rather than stored, so a method can never
            // claim to hand change back on money that has no drawer.
            'takes_tender' => $this->channel?->takesTender(),
            'uses_customer_account' => $this->channel?->usesCustomerAccount(),

            'payments_count' => $this->whenCounted('payments'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
