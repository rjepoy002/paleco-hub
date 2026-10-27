<?php
namespace App\Services;
interface WordPressTransport {
    /** @return array{status:int,body:string} */
    public function request(array $connection,string $method,string $path,array $data=[],?array $file=null): array;
}
