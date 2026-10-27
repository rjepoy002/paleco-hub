<?php
namespace App\Services;
interface RecognitionEngine { /** @return string[] Verified ISO dates from an image, without guessing. */ public function dates(string $path): array; }
