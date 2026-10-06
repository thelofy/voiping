<?php

use App\Broadcasting\OperatorPopupChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('operators.{userId}', OperatorPopupChannel::class);
