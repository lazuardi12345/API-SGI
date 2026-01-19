<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransaksiBaru implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $status;
    public $no_kwitansi;

    public function __construct($message, $status, $no_kwitansi = null)
    {
        $this->message = $message;
        $this->status = $status; 
        $this->no_kwitansi = $no_kwitansi;
    }

    public function broadcastOn()
    {
        return new Channel('monitoring-transaksi');
    }

    public function broadcastAs()
    {
        return 'notif.gadai';
    }
}