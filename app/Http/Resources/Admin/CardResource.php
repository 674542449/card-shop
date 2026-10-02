<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class CardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = Arr::only($this->resource->attributesToArray(), ['id', 'product_id', 'order_id', 'status', 'locked_at', 'sold_at', 'replaced_at', 'created_at', 'updated_at']);
        // Authorization lives in the resource as well as the endpoint. makeVisible()
        // on a reused model can never expose inventory to a catalog/order-only user.
        if ($request->attributes->get('admin')?->allows('cards', 'read')) {
            $data['content'] = $this->resource->content;
        }
        if ($this->resource->relationLoaded('order')) {
            $order = $this->resource->getRelation('order');
            $data['order'] = $order ? Arr::only($order->attributesToArray(), ['id', 'order_no', 'status']) : null;
        }
        return $data;
    }
}
