<?php
declare(strict_types=1);

const MAX_STROKES = 300;
const KB_TTL = 3600;
const KB_NAME_RE = '/^[A-Za-z0-9_-]{1,32}$/';

const MAX_PROGRAM_SIZE = 2048; // probe program size cap (chars)
const MAX_STATE_BITS = 4096;   // per-step state width cap (bits)
// SHA-256 hex of the admin password (Security spec). Default password is
// "admin"; rotate by replacing this constant with the new password's hash.
const ADMIN_HASH = '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918';

const KEYBOARD_SIZES = [
    1  => [1, 1],
    2  => [2, 1],
    4  => [2, 2],
    6  => [3, 2],
    9  => [3, 3],
    12 => [4, 3],
    16 => [4, 4],
    20 => [5, 4],
    25 => [5, 5],
];
