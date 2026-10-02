<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Product;
use App\Exceptions\CheckoutException;

/** Server-side pricing shared by web and API checkout. Call inside a transaction. */
class CheckoutPricingService
{
    private const MAX_AMOUNT = '99999999.99';

    /** @return array{unit_price:string,total_amount:string,discount_amount:string,coupon:?Coupon} */
    public function calculate(Product $product, int $quantity, ?string $couponCode, bool $lockCoupon = true): array
    {
        if ($couponCode !== null && (! mb_check_encoding($couponCode, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $couponCode) || mb_strlen($couponCode) > 50)) {
            throw new CheckoutException('优惠码格式无效。');
        }
        if ($quantity < 1) {
            throw new CheckoutException('购买数量必须为正整数');
        }
        $unitPrice = $product->getEffectivePrice($quantity);
        if (! $this->validMoney($unitPrice) || bccomp($unitPrice, '0.01', 2) < 0) {
            throw new CheckoutException('商品价格配置无效，请联系站点客服');
        }
        $subtotal = bcmul($unitPrice, (string) $quantity, 2);
        if (bccomp($subtotal, self::MAX_AMOUNT, 2) > 0) {
            throw new CheckoutException('订单金额超出允许范围，请减少购买数量');
        }

        $coupon = null;
        $discount = '0.00';
        $couponCode = trim((string) $couponCode);
        if ($couponCode !== '') {
            // The lock keeps the validity check and claimed use consistent with
            // an administrator disabling or changing this coupon concurrently.
            $couponQuery = Coupon::where('code', $couponCode);
            $coupon = ($lockCoupon ? $couponQuery->lockForUpdate() : $couponQuery)->first();
            if (! $coupon) {
                throw new CheckoutException('优惠码不存在');
            }
            if (! $coupon->isValid()) {
                throw new CheckoutException('优惠码已过期或已达使用上限');
            }
            if ($coupon->product_id && $coupon->product_id !== $product->id) {
                throw new CheckoutException('该优惠码不适用于此商品');
            }
            if (! $this->validMoney($coupon->min_amount) || bccomp($subtotal, $coupon->min_amount, 2) < 0) {
                throw new CheckoutException('订单金额不满足该优惠码的最低消费 ¥'.$coupon->min_amount.' 的要求');
            }
            if (! $this->validMoney($coupon->value) || bccomp($coupon->value, '0.00', 2) <= 0
                || ! in_array($coupon->type, ['fixed', 'percent'], true)
                || ($coupon->type === 'percent' && bccomp($coupon->value, '100.00', 2) > 0)) {
                throw new CheckoutException('优惠码折扣配置无效，请联系站点客服');
            }

            if ($coupon->type === 'fixed') {
                $discount = $coupon->value;
            } else {
                $rawDiscount = bcdiv(bcmul($subtotal, $coupon->value, 4), '100', 4);
                // Round positive money half up to a cent, without binary floats.
                $discount = bcadd(bcadd($rawDiscount, '0.0050', 4), '0', 2);
            }
            // Keep both the payable amount and receipt arithmetic correct for a
            // full-value coupon. These are paid orders, with a minimum of one cent.
            $maximumDiscount = bcsub($subtotal, '0.01', 2);
            if (bccomp($discount, $maximumDiscount, 2) > 0) {
                $discount = $maximumDiscount;
            }
        }

        return [
            'unit_price' => $unitPrice,
            'total_amount' => bcsub($subtotal, $discount, 2),
            'discount_amount' => $discount,
            'coupon' => $coupon,
        ];
    }

    private function validMoney(string $amount): bool
    {
        return preg_match('/^\d{1,8}\.\d{2}$/D', $amount) === 1;
    }
}
