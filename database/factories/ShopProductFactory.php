<?php

namespace Database\Factories;

use App\Models\ShopProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShopProductFactory extends Factory
{
    protected $model = ShopProduct::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'type' => 'Credits',
            'price' => $this->faker->numberBetween(1, 20),
            'description' => $this->faker->sentence(),
            'display' => $this->faker->words(2, true),
            'currency_code' => 'USD',
            'quantity' => $this->faker->numberBetween(10, 100),
            'disabled' => false,
        ];
    }
}
