<?php

use App\Enums\PaymentReviewCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->string('review_code', 40)->nullable();
            $table->index(['order_id', 'review_code', 'review_resolved_at'], 'payment_receipts_review_code_index');
        });
        Schema::table('orders', fn (Blueprint $table) => $table->string('payment_review_code', 40)->nullable());
        // Text is interpreted once for pre-existing records. Runtime transitions
        // use the stored code, so future wording changes do not change policy.
        foreach (['payment_receipts' => ['review_reason', 'review_code'], 'orders' => ['payment_review_reason', 'payment_review_code']] as $table => [$reason, $code]) {
            DB::table($table)->whereNotNull($reason)->update([$code => PaymentReviewCode::LegacyUnknown->value]);
            foreach (['已发货订单%' => PaymentReviewCode::DuplicatePayment, '库存不足%' => PaymentReviewCode::InsufficientStock,
                'USDT 收款交易号%' => PaymentReviewCode::BindingMismatch, '网关收款方式%' => PaymentReviewCode::BindingMismatch,
                '订单状态为%' => PaymentReviewCode::OrderState, '迟到付款的优惠券%' => PaymentReviewCode::CouponUnavailable] as $pattern => $value) {
                DB::table($table)->where($reason, 'like', $pattern)->update([$code => $value->value]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropIndex('payment_receipts_review_code_index');
            $table->dropColumn('review_code');
        });
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('payment_review_code'));
    }
};
