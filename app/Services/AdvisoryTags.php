<?php
namespace App\Services;
final class AdvisoryTags {
    public static function resolve(WordPressClient $client): int {
        return TagResolver::resolve($client,'POWER_ADVISORY','power_advisory','wp_advisory_');
    }
}
