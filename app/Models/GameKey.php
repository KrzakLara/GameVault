<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameKey extends Model
{
    use HasFactory;
    protected $fillable = ['game_id','code','status','order_id'];
    protected $hidden = ['code'];
    protected function casts(): array { return ['code'=>'encrypted']; }
    public function game(): BelongsTo { return $this->belongsTo(Game::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
