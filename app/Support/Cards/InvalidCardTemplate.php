<?php

namespace App\Support\Cards;

/**
 * An uploaded file that is not a usable card template — not an archive, the
 * wrong kind of document, or more sides than a card has. Controllers map
 * this onto a 422 against the file field.
 */
class InvalidCardTemplate extends \RuntimeException {}
