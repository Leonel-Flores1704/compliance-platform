<?php

return [
    // ADR-010: bge-m3 produces 1024-dimension vectors. Changing it requires a new migration and re-indexing every document.
    'embedding_dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 1024),
];
