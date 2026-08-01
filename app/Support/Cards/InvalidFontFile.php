<?php

namespace App\Support\Cards;

use RuntimeException;

/**
 * The uploaded file is not a font this converter can use — wrong format,
 * truncated, or carrying no usable family name.
 *
 * Its message is shown to the uploader, so it says what to do rather than
 * what went wrong internally.
 */
class InvalidFontFile extends RuntimeException {}
