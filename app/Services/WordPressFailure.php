<?php
namespace App\Services;
final class WordPressFailure extends \RuntimeException {
    public function __construct(string $message,public readonly bool $uncertain=false) { parent::__construct($message); }
}
