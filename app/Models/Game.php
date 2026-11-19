<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    use HasFactory;
    protected $fillable = ['name','slug','platform','price_cents','cover_url','cheapshark_id','description'];
    public function keys(): HasMany { return $this->hasMany(GameKey::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
}
