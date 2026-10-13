<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['slug', 'name', 'icon', 'position'])]
class Department extends Model
{
    public static function add(string $name): self
    {
        $base = Str::limit(Str::slug($name) ?: 'department', 36, '');
        $slug = $base;
        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return static::create(['slug' => $slug, 'name' => $name, 'position' => (int) static::max('position') + 1]);
    }
}
