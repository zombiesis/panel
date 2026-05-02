<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\ShopProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $price = $this->faker->numberBetween(1000, 10000);
        $taxValue = $this->faker->numberBetween(0, 1000);

        return [
            'payment_id' => Str::random(30),
            'payment_method' => 'Stripe',
            'user_id' => User::factory(),
            'shop_item_product_id' => ShopProduct::factory(),
            'type' => 'Credits',
            'status' => PaymentStatus::OPEN,
            'amount' => $this->faker->numberBetween(1000, 100000),
            'price' => $price,
            'tax_value' => $taxValue,
            'tax_percent' => 0,
            'total_price' => $price + $taxValue,
            'currency_code' => ['EUR', 'USD'][rand(0, 1)],
        ];
    }
}
