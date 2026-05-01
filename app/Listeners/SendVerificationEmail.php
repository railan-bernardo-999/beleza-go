<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use App\Mail\VerifyEmail;
use Illuminate\Support\Facades\Mail;

class SendVerificationEmail
{
    public function handle(UserRegistered $event)
    {
        Mail::to($event->user->email)->send(new VerifyEmail($event->user));
    }
}
