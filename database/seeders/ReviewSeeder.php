<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Review;

class ReviewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $reviews = [
            [
                'customer_name' => 'Stepni Doe',
                'rating' => 5,
                'comment' => 'Excelente comida y atención, el filete estaba perfecto.',
                'created_at' => now()->subDays(3),
            ],
            [
                'customer_name' => 'Rehan Doe',
                'rating' => 4,
                'comment' => 'Muy buena experiencia, el delivery llegó a tiempo.',
                'created_at' => now()->subDays(4),
            ],
            [
                'customer_name' => 'Carlos López',
                'rating' => 5,
                'comment' => 'El servicio impecable y los postres deliciosos.',
                'created_at' => now()->subDays(1),
            ],
            [
                'customer_name' => 'María García',
                'rating' => 4,
                'comment' => 'Buen ambiente, precios razonables. Volveré pronto.',
                'created_at' => now()->subDays(2),
            ],
        ];

        foreach ($reviews as $reviewData) {
            \App\Models\Review::create($reviewData);
        }
    }
}
