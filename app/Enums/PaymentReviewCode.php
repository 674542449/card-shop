<?php

namespace App\Enums;

enum PaymentReviewCode: string
{
    case DuplicatePayment = 'duplicate_payment';
    case BindingMismatch = 'binding_mismatch';
    case OrderState = 'order_state';
    case InsufficientStock = 'insufficient_stock';
    case CouponUnavailable = 'coupon_unavailable';
    case LegacyUnknown = 'legacy_unknown';

    public static function resolvedByDelivery(): array
    {
        return [self::BindingMismatch->value, self::OrderState->value, self::InsufficientStock->value, self::CouponUnavailable->value];
    }
}
