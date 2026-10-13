<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Personal, family, address, emergency, identity and bank details. */
    public const FIELDS = [
        'date_of_birth' => 'date', 'gender' => 'string', 'blood_group' => 'string', 'marital_status' => 'string',
        'father_name' => 'string', 'mother_name' => 'string', 'spouse_name' => 'string',
        'permanent_address' => 'string', 'current_address' => 'string', 'district' => 'string', 'province' => 'string',
        'emergency_name' => 'string', 'emergency_relation' => 'string', 'emergency_phone' => 'string',
        'citizenship_no' => 'string', 'pan_no' => 'string',
        'bank_name' => 'string', 'bank_account_name' => 'string', 'bank_account_no' => 'string',
    ];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach (self::FIELDS as $name => $type) {
                $type === 'date' ? $table->date($name)->nullable() : $table->string($name)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::FIELDS));
        });
    }
};
