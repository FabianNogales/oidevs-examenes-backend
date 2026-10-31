<?php

return [
    // Semantic keys only: frontend renders its own icons, never supplied HTML/SVG.
    'icon_keys' => ['calendar', 'book', 'info', 'link', 'users'],

    // Populate only after agreeing the actual routes with Dev_Erick. Each entry:
    // ['path' => '/confirmed-route', 'label' => 'Approved label', 'requires_auth' => true].
    // An empty list deliberately prevents inventing internal destinations.
    'internal_destinations' => [],
];
