<?php
declare(strict_types=1);

const MAX_STROKES = 300;
const KB_TTL = 3600;
const KB_NAME_RE = '/^[A-Za-z0-9_-]{1,32}$/';

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
