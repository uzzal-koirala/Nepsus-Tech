<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The four packages that used to be fixed in code, in order. */
    private const STARTERS = [
        'basic' => ['Basic', 'Starting point for small businesses', 'sprout', 'green', 10000, ['8 social media posts a month', 'Facebook & Instagram', 'Monthly report']],
        'standard' => ['Standard', 'Our most popular choice', 'star', 'blue', 20000, ['16 posts and 4 reels a month', 'Facebook, Instagram & TikTok', 'Boosted post management', 'Monthly report']],
        'premium' => ['Premium', 'Full-service marketing', 'crown', 'gold', 40000, ['Daily posting and 8 reels', 'All platforms incl. Google Business', 'Ads management', 'Photo shoot every month', 'Weekly report']],
        'enterprise' => ['Enterprise', 'Exclusive, highest-value partnership', 'gem', 'black', 75000, ['Dedicated account manager', 'Unlimited content', 'Website care and SEO', 'Video production', 'Priority support']],
    ];

    public function up(): void
    {
        $ids = [];
        $position = 0;

        foreach (self::STARTERS as $key => [$name, $description, $icon, $color, $price, $features]) {
            $ids[$key] = DB::table('packages')->insertGetId([
                'name' => $name,
                'description' => $description,
                'icon' => $icon,
                'color' => $color,
                'price' => $price,
                'billing_cycle' => 'monthly',
                'features' => json_encode($features),
                'is_active' => true,
                'position' => $position++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });

        foreach ($ids as $key => $id) {
            DB::table('clients')->where('package', $key)->update(['package_id' => $id]);
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('package');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('package')->nullable()->after('status');
        });

        foreach (DB::table('packages')->pluck('name', 'id') as $id => $name) {
            DB::table('clients')->where('package_id', $id)->update(['package' => strtolower($name)]);
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
        });
    }
};
