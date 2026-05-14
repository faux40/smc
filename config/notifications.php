<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mail channel master switch
    |--------------------------------------------------------------------------
    |
    | Phase 15.4 — global gate for the `mail` channel on SMC's per-user
    | notifications. Off by default: the in-app inbox (`database`) and the
    | realtime bell (`broadcast`) always deliver, but email is opt-in at
    | the deployment level. Phase 15.5 layers per-user, per-type toggles
    | on top of this master switch via the `notification_preferences`
    | table — this flag stays the outermost gate.
    |
    */

    'mail_enabled' => env('MAIL_NOTIFICATIONS_ENABLED', false),

];
