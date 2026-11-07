<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShareRecipient extends Model
{
  protected $fillable = [
    'share_id',
    'name',
    'email',
    'notified_at'
  ];

  protected $casts = [
    'notified_at' => 'datetime',
  ];

  public function share()
  {
    return $this->belongsTo(Share::class);
  }
}
