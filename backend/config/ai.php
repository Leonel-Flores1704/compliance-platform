<?php

return [
    // CMP-03/ADR-010 must set this to the dimension of the selected embedding model before the first production migration.
    'embedding_dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 1536),
];
