<?php

return [
    // Semantic keys only: frontend renders its own icons, never supplied HTML/SVG.
    'icon_keys' => ['calendar', 'book', 'info', 'link', 'users'],

    // Verified against Dev_Erick's router. Authentication hints do not grant module roles.
    'internal_destinations' => [
        ['path' => '/teacher/exams', 'label' => 'Mis exámenes (docente)', 'requires_auth' => true],
    ],
];
