<?php

return [

    /*
    | Learning-materials access policy. When false (default), any enrolled
    | learner - including status `applied` with an unpaid application fee -
    | can open course materials. When true, `applied` learners get a grace
    | window (MATERIALS_GRACE_DAYS from applied_at) to study while they
    | arrange payment; once it lapses they get 403 until the fee is paid,
    | and the frontend shows a pay-to-unlock notice instead of the materials.
    */
    'materials_require_application_fee' => (bool) env('MATERIALS_REQUIRE_APPLICATION_FEE', false),
    'materials_grace_days' => (int) env('MATERIALS_GRACE_DAYS', 7),
];
