<?php
namespace App\Services;
/** Only controlled, credential-free messages from the OCR adapter may reach the browser. */
final class OcrFailure extends \RuntimeException {}
