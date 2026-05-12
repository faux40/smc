<?php

use App\Broadcasting\OrgChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return $user->id === $id;
});

// Per-org private channel — all lifecycle events for an organization
// (user registered / updated / soft-deleted, org updated / deleted) ride
// this. Class-based callback so the auth logic is unit-testable.
Broadcast::channel('org.{orgId}', OrgChannel::class);
